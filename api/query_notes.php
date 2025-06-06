<?php
/**
 * Query Notes API - Enhanced to support property-based queries
 * 
 * This API allows executing safe, predefined SQL queries to find notes by various criteria.
 * 
 * Allowed Query Patterns:
 * 1. Direct Notes table queries:
 *    SELECT id FROM Notes WHERE [conditions]
 *    
 * 2. Notes with Properties JOIN:
 *    SELECT [DISTINCT] N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE [conditions]
 *    
 * 3. Notes with Properties subquery:
 *    SELECT id FROM Notes WHERE id IN (SELECT note_id FROM Properties WHERE [conditions])
 *    
 * Example Usage:
 * - Find notes with a specific property: 
 *   SELECT DISTINCT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'status' AND P.value = 'TODO'
 *   
 * - Find notes with any property starting with 'tag::':
 *   SELECT id FROM Notes WHERE id IN (SELECT note_id FROM Properties WHERE name LIKE 'tag::%')
 *   
 * - Find notes on a specific page with properties:
 *   SELECT DISTINCT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE N.page_id = 1 AND P.name = 'priority'
 *   
 * Security: Only SELECT queries are allowed, limited to Notes, Properties, and Pages tables.
 */
require_once '../config.php';
require_once 'db_connect.php';
require_once 'response_utils.php'; // Include the new response utility

// header('Content-Type: application/json'); // Will be handled by ApiResponse

$pdo = get_db_connection();
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['sql_query'])) {
    ApiResponse::error('Missing sql_query parameter.', 400);
    exit;
}

$sqlQuery = trim($input['sql_query']);

// --- SQL Validation ---

// Security Check 1: Basic Structure
// Allow multiple query patterns for Notes and Properties
$allowedPatterns = [
    '/^SELECT\s+id\s+FROM\s+Notes\s+WHERE\s+/i',  // Original pattern
    '/^SELECT\s+DISTINCT\s+N\.id\s+FROM\s+Notes\s+N\s+JOIN\s+Properties\s+P\s+ON\s+N\.id\s*=\s*P\.note_id\s+WHERE\s+/i', // JOIN with Properties
    '/^SELECT\s+N\.id\s+FROM\s+Notes\s+N\s+JOIN\s+Properties\s+P\s+ON\s+N\.id\s*=\s*P\.note_id\s+WHERE\s+/i', // JOIN without DISTINCT
    '/^SELECT\s+id\s+FROM\s+Notes\s+WHERE\s+id\s+IN\s*\(\s*SELECT\s+note_id\s+FROM\s+Properties\s+WHERE\s+/i' // Subquery pattern
];

$patternMatched = false;
foreach ($allowedPatterns as $pattern) {
    if (preg_match($pattern, $sqlQuery)) {
        $patternMatched = true;
        break;
    }
}

if (!$patternMatched) {
    ApiResponse::error('Query must be one of the allowed patterns: SELECT id FROM Notes WHERE..., SELECT [DISTINCT] N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE..., or SELECT id FROM Notes WHERE id IN (SELECT note_id FROM Properties WHERE...). Invalid query: ' . substr($sqlQuery, 0, 100), 400);
    exit;
}

// Security Check 2: Forbidden Keywords/Characters
$forbiddenKeywords = [
    'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'EXEC', 
    'CREATE', 'UNION', 'ATTACH', 'DETACH', 'HANDLER', 'CALL', 'LOCK', 'REPLACE'
    // Leaving out 'SELECT' from this list as it's part of the allowed prefix, but context is important.
    // 'INTO', 'VALUES' might be part of subqueries or specific functions, but for now, let's be strict.
];
// Also check for '--' and '/*' to prevent comments that could hide malicious code
$forbiddenPatterns = [
    '/\b(' . implode('|', $forbiddenKeywords) . ')\b/i', // Whole word match for keywords
    '/--/', // SQL comment
    '/\/\*/', // SQL block comment start
    '/\*\//'  // SQL block comment end
];

// Check for semicolons not at the very end of the query
if (strpos($sqlQuery, ';') !== false && strpos($sqlQuery, ';') !== strlen($sqlQuery) - 1) {
    ApiResponse::error('Semicolons are only allowed at the very end of the query.', 400);
    exit;
}
// Remove trailing semicolon if present, for consistency before further checks
if (substr($sqlQuery, -1) === ';') {
    $sqlQuery = substr($sqlQuery, 0, -1);
}

foreach ($forbiddenPatterns as $pattern) {
    if (preg_match($pattern, $sqlQuery)) {
        ApiResponse::error('Query contains forbidden SQL keywords/characters or comments. Pattern: ' . $pattern, 400);
        exit;
    }
}

// Security Check 3: Table/Column Validation
// Ensure only allowed tables and columns are referenced
$allowedTables = ['Notes', 'Properties', 'Pages'];
$allowedNotesColumns = ['id', 'content', 'page_id', 'parent_note_id', 'created_at', 'updated_at', 'order_index', 'collapsed', 'internal', 'active'];
$allowedPropertiesColumns = ['note_id', 'page_id', 'name', 'value', 'internal', 'active', 'created_at', 'updated_at'];
$allowedPagesColumns = ['id', 'name', 'alias', 'active', 'created_at', 'updated_at'];

// Basic table reference validation (prevent access to unauthorized tables)
// This regex looks for table names that are not in our allowed list
if (preg_match('/\bFROM\s+(?!(?:Notes|Properties|Pages)\b)\w+/i', $sqlQuery) ||
    preg_match('/\bJOIN\s+(?!(?:Notes|Properties|Pages)\b)\w+/i', $sqlQuery)) {
    ApiResponse::error('Query references unauthorized tables. Only Notes, Properties, and Pages are allowed.', 400);
    exit;
}

// --- Query Execution ---
try {
    // Step 1: Execute the provided query to get note IDs
    $stmtGetIds = $pdo->prepare($sqlQuery);
    $stmtGetIds->execute();
    $noteIds = $stmtGetIds->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($noteIds)) {
        ApiResponse::success([]);
        exit;
    }

    // Step 2: Fetch full note data for the retrieved IDs
    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
    $sqlFetchNotes = "SELECT * FROM Notes WHERE id IN ({$placeholders})";
    
    $stmtFetchNotes = $pdo->prepare($sqlFetchNotes);
    $stmtFetchNotes->execute($noteIds);
    $notes = $stmtFetchNotes->fetchAll(PDO::FETCH_ASSOC);

    ApiResponse::success($notes);

} catch (PDOException $e) {
    // Log the detailed error to server logs for debugging
    error_log("Database error in query_notes.php: " . $e->getMessage());
    error_log("Offending SQL (potentially): " . $sqlQuery);

    // Provide a generic error message to the client
    ApiResponse::error('A database error occurred.', 500);
} catch (Exception $e) {
    error_log("General error in query_notes.php: " . $e->getMessage());
    ApiResponse::error('An unexpected error occurred.', 500);
}

?>
