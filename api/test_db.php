<?php
header('Content-Type: application/json');
error_reporting(E_ERROR);
ini_set('display_errors', 1);

try {
    $db = new SQLite3('../db/notes.db');
    if (!$db) {
        // Although the catch block would handle it, explicit check after new SQLite3 can be clearer for immediate connection issues.
        throw new Exception('Failed to connect to database in test_db.php: ' . SQLite3::lastErrorMsg());
    }
    $db->busyTimeout(5000); // Set busy timeout to 5000 milliseconds (5 seconds)
    
    // Test if we can query the database
    $result = $db->query('SELECT name FROM sqlite_master WHERE type="table"');
    $tables = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row['name'];
    }
    
    echo json_encode([
        'status' => 'success',
        'tables' => $tables
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 