<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $db = new SQLite3('../db/notes.db');
    
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