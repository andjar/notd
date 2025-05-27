<?php
ini_set('display_errors', 1); // For server-side debugging
ini_set('display_startup_errors', 1); // For server-side debugging
error_reporting(E_ALL); // For server-side debugging

$rawInput = file_get_contents('php://input');
error_log('toggle_favorite.php received input: ' . $rawInput);

// Set display_errors to 0 for client output to ensure valid JSON response even if minor notices occur
ini_set('display_errors', 0); 
header('Content-Type: application/json');

try {
    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Invalid JSON received: ' . json_last_error_msg() . '. Raw input: ' . $rawInput);
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    if (!isset($input['note_id'])) {
        error_log('Note ID not provided in JSON input. Input: ' . $rawInput);
        throw new Exception('Note ID is required');
    }
    $noteId = $input['note_id']; // Store for consistent use
    error_log('Parsed note_id: ' . $noteId);

    // Validate note_id (assuming it should be a positive integer for this example)
    $validatedNoteId = filter_var($noteId, FILTER_VALIDATE_INT);
    if ($validatedNoteId === false || $validatedNoteId <= 0) {
        error_log('Invalid note_id format or value: ' . $noteId . '. Validated as: ' . $validatedNoteId);
        throw new Exception('Invalid Note ID format.');
    }
    // Use validatedNoteId from here onwards


    $db = new SQLite3(__DIR__ . '/../db/notes.db');
    if (!$db) {
        error_log('Failed to connect to database');
        throw new Exception('Failed to connect to database');
    }
    
    // Check if is_favorite column exists
    $tableInfoResult = $db->query("PRAGMA table_info(notes)");
    if (!$tableInfoResult) {
        error_log('Failed to check table structure: ' . $db->lastErrorMsg());
        throw new Exception('Failed to check table structure');
    }
    
    $hasFavoriteColumn = false;
    while ($column = $tableInfoResult->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name'] === 'is_favorite') {
            $hasFavoriteColumn = true;
            break;
        }
    }
    
    // Add is_favorite column if it doesn't exist
    if (!$hasFavoriteColumn) {
        error_log('is_favorite column not found, attempting to add it.');
        $alterResult = $db->exec('ALTER TABLE notes ADD COLUMN is_favorite INTEGER DEFAULT 0');
        if (!$alterResult) {
            error_log('Failed to add is_favorite column: ' . $db->lastErrorMsg());
            throw new Exception('Failed to add is_favorite column: ' . $db->lastErrorMsg());
        }
        error_log('is_favorite column added successfully.');
    }
    
    // First get current state
    $stmt = $db->prepare('SELECT is_favorite FROM notes WHERE id = :note_id');
    if (!$stmt) {
        error_log('Failed to prepare select query: ' . $db->lastErrorMsg());
        throw new Exception('Failed to prepare query: ' . $db->lastErrorMsg());
    }
    
    // Using SQLITE3_INTEGER for note_id as it's expected to be an integer
    $stmt->bindValue(':note_id', $validatedNoteId, SQLITE3_INTEGER); 
    $fetchResult = $stmt->execute();
    if (!$fetchResult) {
        error_log('Failed to execute select query: ' . $db->lastErrorMsg());
        throw new Exception('Failed to execute query: ' . $db->lastErrorMsg());
    }
    
    $row = $fetchResult->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        error_log('Note not found with id: ' . $validatedNoteId);
        throw new Exception('Note not found');
    }
    error_log('Current state for note ' . $validatedNoteId . ': ' . ($row['is_favorite'] ? '1' : '0'));
    
    // Toggle the favorite state
    $newState = !$row['is_favorite'];
    error_log('New state for note ' . $validatedNoteId . ' will be: ' . ($newState ? '1' : '0'));

    $updateStmt = $db->prepare('UPDATE notes SET is_favorite = :state WHERE id = :note_id');
    if (!$updateStmt) {
        error_log('Failed to prepare update query: ' . $db->lastErrorMsg());
        throw new Exception('Failed to prepare update query: ' . $db->lastErrorMsg());
    }
    
    $updateStmt->bindValue(':state', $newState ? 1 : 0, SQLITE3_INTEGER);
    $updateStmt->bindValue(':note_id', $validatedNoteId, SQLITE3_INTEGER); // Use SQLITE3_INTEGER
    $executeUpdateResult = $updateStmt->execute();
    
    if (!$executeUpdateResult) {
        error_log('Failed to update favorite state: ' . $db->lastErrorMsg());
        throw new Exception('Failed to update favorite state: ' . $db->lastErrorMsg());
    }
    
    $changes = $db->changes();
    error_log('Number of rows changed: ' . $changes);

    if ($changes === 0) {
        // This could mean the note_id was not found (already handled by initial select)
        // or the state was already the $newState.
        // Let's re-verify the state if no rows were reported changed.
        $verifyStmt = $db->prepare('SELECT is_favorite FROM notes WHERE id = :note_id');
        $verifyStmt->bindValue(':note_id', $validatedNoteId, SQLITE3_INTEGER); // Use SQLITE3_INTEGER
        $verifyResult = $verifyStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($verifyResult && ($verifyResult['is_favorite'] ? 1:0) == ($newState ? 1:0)) {
            error_log('Note state confirmed to be as intended after 0 changes reported for id: ' . $validatedNoteId);
        } else {
            error_log('0 rows changed and state does not match target. Note ID: ' . $validatedNoteId . '. Expected state: ' . $newState . '. Actual: ' . ($verifyResult ? $verifyResult['is_favorite'] : 'not found'));
            // Potentially throw an error here if this scenario is critical
        }
    }
    
    echo json_encode(['is_favorite' => $newState]);
    
} catch (Exception $e) {
    error_log('Caught Exception in toggle_favorite.php: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 