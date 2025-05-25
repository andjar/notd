<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Errors should be logged, not displayed for API
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Consistent error logging

try {
    $db = new SQLite3(__DIR__ . '/../db/notes.db'); // Use absolute path
    if (!$db) {
        // Handle error immediately if connection fails
        error_log("recent_pages.php: Failed to connect to database: " . SQLite3::lastErrorMsg());
        echo json_encode(['error' => 'Failed to connect to database.']);
        exit;
    }
    $db->busyTimeout(5000); // Set busy timeout to 5000 milliseconds (5 seconds)
    // Enable foreign key constraints for this connection
    if (!$db->exec('PRAGMA foreign_keys = ON;')) {
        error_log("Notice: Attempted to enable foreign_keys for recent_pages.php. Check SQLite logs if issues persist with FKs.");
    }

    function getRecentPages() {
        global $db;
        
        $stmt = $db->prepare('
            SELECT r.page_id, r.last_opened, p.title
            FROM recent_pages r
            LEFT JOIN pages p ON r.page_id = p.id
            ORDER BY r.last_opened DESC
            LIMIT 7
        ');
        
        $result = $stmt->execute();
        $pages = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $pages[] = [
                'page_id' => $row['page_id'],
                'title' => $row['title'],
                'last_opened' => $row['last_opened']
            ];
        }
        
        return $pages;
    }

    function updateRecentPage($pageId) {
        global $db;

        if (!$db->exec('BEGIN TRANSACTION')) {
            error_log("recent_pages.php: Failed to begin transaction: " . $db->lastErrorMsg());
            return ['error' => 'Failed to start database transaction.'];
        }

        try {
            // Delete existing entry
            $stmtDelete = $db->prepare('DELETE FROM recent_pages WHERE page_id = :page_id');
            if (!$stmtDelete) { // Check if prepare failed
                $errorMsg = $db->lastErrorMsg();
                $db->exec('ROLLBACK'); // Attempt rollback
                error_log("recent_pages.php: Failed to prepare delete statement: " . $errorMsg);
                return ['error' => 'Database error (prepare delete): ' . $errorMsg];
            }
            $stmtDelete->bindValue(':page_id', $pageId, SQLITE3_TEXT);
            if (!$stmtDelete->execute()) {
                $errorMsg = $db->lastErrorMsg();
                $db->exec('ROLLBACK');
                error_log("recent_pages.php: Failed to delete from recent_pages: " . $errorMsg);
                $stmtDelete->close();
                return ['error' => 'Failed to delete existing recent page entry: ' . $errorMsg];
            }
            $stmtDelete->close();

            // Insert new entry
            $stmtInsert = $db->prepare('INSERT INTO recent_pages (page_id, last_opened) VALUES (:page_id, CURRENT_TIMESTAMP)');
            if (!$stmtInsert) { // Check if prepare failed
                $errorMsg = $db->lastErrorMsg();
                $db->exec('ROLLBACK'); // Attempt rollback
                error_log("recent_pages.php: Failed to prepare insert statement: " . $errorMsg);
                return ['error' => 'Database error (prepare insert): ' . $errorMsg];
            }
            $stmtInsert->bindValue(':page_id', $pageId, SQLITE3_TEXT);
            if (!$stmtInsert->execute()) {
                $errorMsg = $db->lastErrorMsg();
                $db->exec('ROLLBACK');
                error_log("recent_pages.php: Failed to insert into recent_pages: " . $errorMsg);
                $stmtInsert->close();
                return ['error' => 'Failed to insert new recent page entry: ' . $errorMsg];
            }
            $stmtInsert->close();

            if (!$db->exec('COMMIT')) {
                $errorMsg = $db->lastErrorMsg();
                // Attempt to rollback if commit failed, though state might be undefined
                // No explicit rollback here as per SQLite docs, failed COMMIT means transaction is already rolled back.
                error_log("recent_pages.php: Failed to commit transaction: " . $errorMsg);
                return ['error' => 'Failed to commit transaction: ' . $errorMsg];
            }

            return ['success' => true];

        } catch (Exception $e) {
            // This catch is for more general exceptions, e.g. if $db object is not available
            // or other unexpected PHP errors converted to exceptions by a handler (if any).
            // Check if a transaction was started and is still active before attempting rollback.
            // SQLite3::querySingle for PRAGMA might not be reliable if connection is bad.
            // A simple exec('ROLLBACK') is often safe; if no transaction, it's a no-op or minor error.
            // However, to be absolutely safe, one might check if $db is in a transaction state.
            // For simplicity and given typical SQLite behavior, an unconditional rollback attempt inside
            // a general exception handler (if a transaction was known to be started) is common.
            // But, specific rollbacks are already handled for DB errors.
            error_log("recent_pages.php: Exception during updateRecentPage: " . $e->getMessage());
            // Attempt to rollback just in case the transaction was started but an unexpected PHP error occurred
            // before specific DB error handling could rollback.
            $db->exec('ROLLBACK'); // If no transaction active, it's a no-op or harmless error.
            return ['error' => 'An unexpected error occurred: ' . $e->getMessage()];
        }
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            echo json_encode(getRecentPages());
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['page_id'])) {
                echo json_encode(['error' => 'Page ID required']);
                exit;
            }
            echo json_encode(updateRecentPage($data['page_id']));
            break;
            
        default:
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 