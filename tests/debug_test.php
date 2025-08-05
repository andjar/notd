<?php
// Simple debug test to check class loading

echo "=== BOOTSTRAP DEBUG TEST ===\n";

// Check if we can include the bootstrap
echo "1. Loading bootstrap...\n";
try {
    require_once __DIR__ . '/bootstrap.php';
    echo "   ✅ Bootstrap loaded successfully\n";
} catch (Exception $e) {
    echo "   ❌ Bootstrap failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if constants are defined
echo "2. Checking constants...\n";
echo "   DB_PATH: " . (defined('DB_PATH') ? DB_PATH : 'NOT DEFINED') . "\n";
echo "   DB_PATH exists: " . (file_exists(DB_PATH) ? 'YES' : 'NO') . "\n";

// Check if classes exist
echo "3. Checking if classes exist...\n";
$classes = [
    'App\DataManager',
    'App\PatternProcessor',
    'App\PropertyTriggerService',
    'App\WebhooksManager',
    'App\UuidUtils'
];

foreach ($classes as $class) {
    echo "   $class: " . (class_exists($class) ? '✅ YES' : '❌ NO') . "\n";
}

// Check if files exist
echo "4. Checking if API files exist...\n";
$files = [
    'api/DataManager.php',
    'api/PatternProcessor.php',
    'api/PropertyTriggerService.php',
    'api/v1/WebhooksManager.php',
    'api/UuidUtils.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/../' . $file;
    echo "   $file: " . (file_exists($path) ? '✅ YES' : '❌ NO') . "\n";
}

// Check if composer autoloader exists
echo "5. Checking composer autoloader...\n";
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
echo "   vendor/autoload.php: " . (file_exists($autoloadPath) ? '✅ YES' : '❌ NO') . "\n";

// Try to instantiate classes
echo "6. Attempting to instantiate classes...\n";

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    echo "   ✅ PDO created successfully\n";
    
    if (class_exists('App\DataManager')) {
        $dm = new App\DataManager($pdo);
        echo "   ✅ DataManager instantiated successfully\n";
        
        // Try a simple method
        $pages = $dm->getPages();
        echo "   ✅ getPages() method works: " . (is_array($pages) ? 'YES' : 'NO') . "\n";
        echo "   ✅ Pages returned: " . count($pages['data']) . "\n";
    } else {
        echo "   ❌ DataManager class not found\n";
    }
    
    if (class_exists('App\UuidUtils')) {
        $uuid = \App\UuidUtils::generateUuidV7();
        echo "   ✅ UUID generated: " . $uuid . "\n";
        echo "   ✅ UUID is valid: " . (\App\UuidUtils::isValidUuidV7($uuid) ? 'YES' : 'NO') . "\n";
    } else {
        echo "   ❌ UuidUtils class not found\n";
    }
    
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    echo "   ❌ File: " . $e->getFile() . "\n";
    echo "   ❌ Line: " . $e->getLine() . "\n";
    echo "   ❌ Stack trace:\n";
    foreach ($e->getTrace() as $trace) {
        echo "     " . ($trace['file'] ?? 'unknown') . ':' . ($trace['line'] ?? 'unknown') . "\n";
    }
}

echo "=== END DEBUG TEST ===\n"; 