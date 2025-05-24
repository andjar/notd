<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/migration_errors.log'); // Log errors to a file in logs directory

echo "Starting Page Links Migration Script...\n";

$dbPath = __DIR__ . '/../db/notes.db';
$linksProcessed = 0;
$linksInserted = 0;
$errorsEncountered = 0;

try {
    $db = new SQLite3($dbPath);
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }
    echo "Successfully connected to the database.\n";
    // Enable foreign key constraints for this connection
    if (!$db->exec('PRAGMA foreign_keys = ON;')) {
        error_log("Notice: Attempted to enable foreign_keys for migrate_links.php. Check SQLite logs if issues persist with FKs.");
    }

    // Begin a transaction for batch inserts, if preferred, though for idempotency checks, individual might be okay.
    // For simplicity here, we'll do checks individually. A transaction might speed up bulk inserts if checks are minimal.
    // $db->exec('BEGIN TRANSACTION');

    // Fetch all notes
    echo "Fetching all notes from the database...\n";
    $notesQuery = $db->query('SELECT id AS note_id, page_id AS source_page_id, content FROM notes');
    if (!$notesQuery) {
        throw new Exception('Failed to query notes: ' . $db->lastErrorMsg());
    }

    $insertStmt = $db->prepare('
        INSERT INTO page_links (source_page_id, target_page_id, source_note_id)
        VALUES (:source_page_id, :target_page_id, :source_note_id)
    ');
    if (!$insertStmt) {
        throw new Exception('Failed to prepare insert statement for page_links: ' . $db->lastErrorMsg());
    }

    $checkStmt = $db->prepare('
        SELECT 1 FROM page_links 
        WHERE source_page_id = :source_page_id 
        AND target_page_id = :target_page_id 
        AND source_note_id = :source_note_id
    ');
    if (!$checkStmt) {
        throw new Exception('Failed to prepare check statement for page_links: ' . $db->lastErrorMsg());
    }

    while ($note = $notesQuery->fetchArray(SQLITE3_ASSOC)) {
        $linksProcessed++;
        echo "Processing Note ID: {$note['note_id']} (Page ID: {$note['source_page_id']})...\n";

        if (empty($note['content'])) {
            echo "  Note ID: {$note['note_id']} has no content. Skipping.\n";
            continue;
        }

        // Regex to find all [[link targets]]
        // Using PREG_SET_ORDER makes $matches structured per match.
        // $matches will be an array of arrays, where each inner array has:
        // $matches[i][0] == "[[link target]]"
        // $matches[i][1] == "link target"
        if (preg_match_all('/\[\[([a-zA-Z0-9_\-\s]+)\]\]/', $note['content'], $matches, PREG_SET_ORDER)) {
            $foundLinksCount = count($matches);
            echo "  Found $foundLinksCount link(s) in Note ID: {$note['note_id']}.\n";
            
            $uniqueTargetIds = [];
            foreach ($matches as $match) {
                $uniqueTargetIds[] = trim($match[1]);
            }
            $uniqueTargetIds = array_unique($uniqueTargetIds);

            foreach ($uniqueTargetIds as $targetPageId) {
                if (empty($targetPageId)) {
                    echo "  Warning: Found an empty link target in Note ID: {$note['note_id']}. Skipping.\n";
                    continue;
                }

                // Check for existence (Idempotency)
                $checkStmt->bindValue(':source_page_id', $note['source_page_id'], SQLITE3_TEXT);
                $checkStmt->bindValue(':target_page_id', $targetPageId, SQLITE3_TEXT);
                $checkStmt->bindValue(':source_note_id', $note['note_id'], SQLITE3_INTEGER);
                
                $result = $checkStmt->execute();
                if (!$result) {
                    echo "  Error executing check statement for Note ID {$note['note_id']} to target '$targetPageId': " . $db->lastErrorMsg() . "\n";
                    $errorsEncountered++;
                    $checkStmt->reset(); // Reset for next execution
                    continue; 
                }

                if ($result->fetchArray(SQLITE3_ASSOC)) {
                    echo "  Link from Page ID {$note['source_page_id']} (Note ID {$note['note_id']}) to '$targetPageId' already exists. Skipping.\n";
                } else {
                    // Insert the link
                    $insertStmt->bindValue(':source_page_id', $note['source_page_id'], SQLITE3_TEXT);
                    $insertStmt->bindValue(':target_page_id', $targetPageId, SQLITE3_TEXT);
                    $insertStmt->bindValue(':source_note_id', $note['note_id'], SQLITE3_INTEGER);

                    if ($insertStmt->execute()) {
                        echo "  Successfully inserted link from Page ID {$note['source_page_id']} (Note ID {$note['note_id']}) to '$targetPageId'.\n";
                        $linksInserted++;
                    } else {
                        echo "  Error inserting link from Page ID {$note['source_page_id']} (Note ID {$note['note_id']}) to '$targetPageId': " . $db->lastErrorMsg() . "\n";
                        // This could be due to FK constraint (target_page_id does not exist in pages table)
                        // Or source_page_id not in pages (less likely if data is consistent)
                        // Or source_note_id not in notes (very unlikely here)
                        $errorsEncountered++;
                    }
                    $insertStmt->reset(); // Reset for next execution
                }
                $checkStmt->reset(); // Reset for next execution
            }
        } else {
            echo "  No links found in Note ID: {$note['note_id']}.\n";
        }
    }

    // $db->exec('COMMIT'); // Commit transaction if one was started

    echo "\nMigration Complete.\n";
    echo "------------------------------------\n";
    echo "Total Notes Processed: $linksProcessed\n"; // This is actually notes processed
    echo "Total Links Inserted: $linksInserted\n";
    echo "Errors Encountered: $errorsEncountered\n";

} catch (Exception $e) {
    // if ($db) $db->exec('ROLLBACK'); // Rollback transaction if one was started and an exception occurred
    echo "An critical error occurred: " . $e->getMessage() . "\n";
    $errorsEncountered++;
    echo "\nMigration Failed.\n";
    echo "------------------------------------\n";
    echo "Total Notes Processed (before failure): $linksProcessed\n";
    echo "Total Links Inserted (before failure): $linksInserted\n";
    echo "Errors Encountered: $errorsEncountered\n";
} finally {
    if (isset($db) && $db) {
        $db->close();
        echo "Database connection closed.\n";
    }
}

?>
