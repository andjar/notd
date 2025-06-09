<?php
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/data_manager.php';

$pdo = get_db_connection();

echo "<h2>Database Debug Information</h2>";

// Check if Pages table exists and has data
echo "<h3>Pages Table:</h3>";
try {
    $stmt = $pdo->query("SELECT * FROM Pages LIMIT 5");
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . json_encode($pages, JSON_PRETTY_PRINT) . "</pre>";
} catch (Exception $e) {
    echo "Error querying Pages: " . $e->getMessage();
}

// Check if Notes table exists and has data
echo "<h3>Notes Table:</h3>";
try {
    $stmt = $pdo->query("SELECT * FROM Notes LIMIT 5");
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . json_encode($notes, JSON_PRETTY_PRINT) . "</pre>";
} catch (Exception $e) {
    echo "Error querying Notes: " . $e->getMessage();
}

// Check for page_id = 1 specifically (since that's what the request used)
echo "<h3>Notes for page_id = 1:</h3>";
try {
    $stmt = $pdo->prepare("SELECT * FROM Notes WHERE page_id = :pageId");
    $stmt->execute([':pageId' => 1]);
    $notesForPage1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . json_encode($notesForPage1, JSON_PRETTY_PRINT) . "</pre>";
} catch (Exception $e) {
    echo "Error querying Notes for page_id=1: " . $e->getMessage();
}

// Test DataManager directly
echo "<h3>DataManager Test:</h3>";
try {
    $dataManager = new DataManager($pdo);
    
    echo "<h4>DataManager->getNotesByPageId(1, false):</h4>";
    $dmNotes = $dataManager->getNotesByPageId(1, false);
    echo "<pre>" . json_encode($dmNotes, JSON_PRETTY_PRINT) . "</pre>";
    
    echo "<h4>DataManager->getPageWithNotes(1, false):</h4>";
    $dmPageData = $dataManager->getPageWithNotes(1, false);
    echo "<pre>" . json_encode($dmPageData, JSON_PRETTY_PRINT) . "</pre>";
    
} catch (Exception $e) {
    echo "Error testing DataManager: " . $e->getMessage();
}

// Check table structure
echo "<h3>Notes Table Structure:</h3>";
try {
    $stmt = $pdo->query("PRAGMA table_info(Notes)");
    $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . json_encode($structure, JSON_PRETTY_PRINT) . "</pre>";
} catch (Exception $e) {
    echo "Error getting table structure: " . $e->getMessage();
}
?> 