<?php
// Syntax validation script to check for PHP syntax errors

echo "=== SYNTAX VALIDATION TEST ===\n";

$files = [
    'config.php',
    'api/data_manager.php',
    'api/pattern_processor.php',
    'api/property_trigger_service.php',
    'api/v1/webhooks.php',
    'api/db_connect.php',
    'api/response_utils.php',
    'api/validator_utils.php',
    'api/db_helpers.php',
    'db/setup_db.php'
];

$errors = [];

foreach ($files as $file) {
    $path = __DIR__ . '/../' . $file;
    
    if (!file_exists($path)) {
        echo "❌ File not found: $file\n";
        $errors[] = "File not found: $file";
        continue;
    }
    
    echo "Checking syntax: $file...";
    
    // Check syntax using php -l
    $output = [];
    $returnCode = 0;
    exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        echo " ✅ OK\n";
    } else {
        echo " ❌ SYNTAX ERROR\n";
        echo "   Error: " . implode("\n   ", $output) . "\n";
        $errors[] = "Syntax error in $file: " . implode(" ", $output);
    }
}

echo "\n=== SUMMARY ===\n";
if (empty($errors)) {
    echo "✅ All files have valid syntax!\n";
} else {
    echo "❌ Found " . count($errors) . " error(s):\n";
    foreach ($errors as $error) {
        echo "   - $error\n";
    }
}

echo "=== END SYNTAX VALIDATION ===\n"; 