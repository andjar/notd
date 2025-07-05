<?php
// Simple debug test to check class loading

// Load the bootstrap
require_once __DIR__ . '/bootstrap.php';

echo "=== DEBUG TEST ===\n";

// Check if classes exist
echo "DataManager exists: " . (class_exists('App\DataManager') ? 'YES' : 'NO') . "\n";
echo "PatternProcessor exists: " . (class_exists('App\PatternProcessor') ? 'YES' : 'NO') . "\n";

// Try to instantiate DataManager
try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    echo "PDO created successfully\n";
    
    $dm = new App\DataManager($pdo);
    echo "DataManager instantiated successfully\n";
    
    // Try a simple method
    $pages = $dm->getPages();
    echo "getPages() method works: " . (is_array($pages) ? 'YES' : 'NO') . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}

echo "=== END DEBUG TEST ===\n"; 