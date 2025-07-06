<?php

namespace App;

/**
 * Query Notes API - Enhanced to support property-based queries
 * This API allows executing safe, predefined SQL queries to find notes.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../data_manager.php';

$pdo = get_db_connection();
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['sql_query'])) {
    \App\ApiResponse::error('Missing sql_query parameter.', 400);
}

// --- SQL Validation ---
$sqlQuery = trim($input['sql_query']);
$allowedPatterns = [
    '/^SELECT\s+id\s+FROM\s+Notes\s+WHERE\s+/i',
    '/^SELECT\s+(DISTINCT\s+)?N\.id\s+FROM\s+Notes\s+N\s+JOIN\s+Properties\s+P\s+ON\s+N\.id\s*=\s*P\.note_id\s+WHERE\s+/i',
    '/^SELECT\s+id\s+FROM\s+Notes\s+WHERE\s+id\s+IN\s*\(\s*SELECT\s+note_id\s+FROM\s+Properties\s+WHERE\s+/i'
];

$patternMatched = false;
foreach ($allowedPatterns as $pattern) {
    if (preg_match($pattern, $sqlQuery)) {
        $patternMatched = true;
        break;
    }
}

if (!$patternMatched) {
    \App\ApiResponse::error('Query must be one of the allowed patterns.', 400);
}

if (strpos($sqlQuery, ';') !== false) {
    \App\ApiResponse::error('Query must not contain semicolons.', 400);
}

$forbiddenKeywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'EXEC', 'CREATE', 'ATTACH', 'DETACH'];
foreach ($forbiddenKeywords as $keyword) {
    if (preg_match('/\b' . $keyword . '\b/i', $sqlQuery)) {
        \App\ApiResponse::error('Query contains forbidden SQL keywords.', 400);
    }
}

// Check for allowed columns to prevent data leakage from other columns.
// This is a simple text-based check and not a full AST parser, but provides a good layer of security.
$allowedColumnsRegex = '/\b(N|P|Notes|Properties)\.(id|content|page_id|parent_note_id|created_at|updated_at|order_index|collapsed|active|note_id|name|value|weight)\b/i';
$strippedQuery = preg_replace($allowedColumnsRegex, '', $sqlQuery);
if (preg_match('/(N|P|Notes|Properties)\.\w+/', $strippedQuery)) {
     \App\ApiResponse::error('Query references unauthorized columns.', 400);
}


// --- Query Execution ---
try {
    $page = max(1, intval($input['page'] ?? 1));
    $perPage = max(1, min(100, intval($input['per_page'] ?? 20)));
    $includeProperties = (bool)($input['include_properties'] ?? true);
    $offset = ($page - 1) * $perPage;

    // Get total count by wrapping the user's query
    $countQuery = "SELECT COUNT(*) FROM (" . $sqlQuery . ") as count_query";
    $totalCount = (int)$pdo->query($countQuery)->fetchColumn();
    $totalPages = ceil($totalCount / $perPage);

    // Get the paginated list of note IDs
    $paginatedQuery = $sqlQuery . " LIMIT ? OFFSET ?";
    $stmtGetIds = $pdo->prepare($paginatedQuery);
    $stmtGetIds->execute([$perPage, $offset]);
    $noteIds = $stmtGetIds->fetchAll(\PDO::FETCH_COLUMN, 0);

    if (empty($noteIds)) {
        \App\ApiResponse::success(
            [], 200, ['pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total_items' => $totalCount, 'total_pages' => $totalPages]]
        );
        exit;
    }

    // Fetch full note data for the retrieved IDs
    $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
    $sqlFetchNotes = "SELECT * FROM Notes WHERE id IN ({$placeholders})";
    $stmtFetchNotes = $pdo->prepare($sqlFetchNotes);
    $stmtFetchNotes->execute($noteIds);
    $notes = $stmtFetchNotes->fetchAll(\PDO::FETCH_ASSOC);

    // If properties are requested, fetch and format them using the DataManager
    if ($includeProperties && !empty($notes)) {
        $dataManager = new \App\DataManager($pdo);
        // Pass 'true' to include properties that are normally hidden in view mode
        $propertiesByNoteId = $dataManager->getPropertiesForNoteIds($noteIds, true);

        foreach ($notes as &$note) {
            $note['properties'] = $propertiesByNoteId[$note['id']] ?? [];
        }
    }

    \App\ApiResponse::success(
        $notes,
        200,
        [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalCount,
                'total_pages' => $totalPages
            ]
        ]
    );

} catch (PDOException $e) {
    error_log("Database error in query_notes.php: " . $e->getMessage() . " SQL: " . $sqlQuery);
    \App\ApiResponse::error('A database error occurred during query execution.', 500);
}