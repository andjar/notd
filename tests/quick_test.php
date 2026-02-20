<?php
// Quick test script to verify basic functionality without PHPUnit

echo "=== QUICK FUNCTIONALITY TEST ===\n";

// Print working directory and include path
echo "CWD: " . getcwd() . "\n";
echo "Include path: " . get_include_path() . "\n";

// Print contents of vendor/composer/autoload_psr4.php if it exists
$autoload_psr4 = __DIR__ . '/../vendor/composer/autoload_psr4.php';
if (file_exists($autoload_psr4)) {
    echo "autoload_psr4.php exists. Contents:\n";
    echo file_get_contents($autoload_psr4) . "\n";
} else {
    echo "autoload_psr4.php does NOT exist!\n";
}

// Check for PSR-4 filenames (capitalized)
$paths = [
    __DIR__ . '/../api/DataManager.php',
    __DIR__ . '/../api/PatternProcessor.php',
    __DIR__ . '/../api/PropertyTriggerService.php',
    __DIR__ . '/../api/v1/WebhooksManager.php',
];
foreach ($paths as $p) {
    echo "$p: " . (file_exists($p) ? 'YES' : 'NO') . "\n";
}

// Check class_exists before loading bootstrap
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
echo "class_exists('App\\DataManager') before autoload: " . (class_exists('App\\DataManager') ? 'YES' : 'NO') . "\n";
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    echo "Composer autoloader loaded\n";
    echo "class_exists('App\\DataManager') after autoload: " . (class_exists('App\\DataManager') ? 'YES' : 'NO') . "\n";
}

// Load bootstrap
echo "1. Loading bootstrap...\n";
require_once __DIR__ . '/bootstrap.php';
echo "class_exists('App\\DataManager') after bootstrap: " . (class_exists('App\\DataManager') ? 'YES' : 'NO') . "\n";

// Basic assertions helper
function assert_equal($expected, $actual, $message) {
    if ($expected === $actual) {
        echo "   ✅ $message\n";
        return true;
    } else {
        echo "   ❌ $message - Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . "\n";
        return false;
    }
}

function assert_true($condition, $message) {
    if ($condition) {
        echo "   ✅ $message\n";
        return true;
    } else {
        echo "   ❌ $message\n";
        return false;
    }
}

$tests_passed = 0;
$tests_total = 0;

// Test 1: DataManager instantiation
echo "2. Testing DataManager instantiation...\n";
$tests_total++;
try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $dm = new App\DataManager($pdo);
    echo "   ✅ DataManager instantiated successfully\n";
    $tests_passed++;
} catch (Exception $e) {
    echo "   ❌ DataManager instantiation failed: " . $e->getMessage() . "\n";
}

// Test 2: Basic database operations
echo "3. Testing basic database operations...\n";
$tests_total++;
try {
    $pages = $dm->getPages();
    assert_true(is_array($pages), "getPages() returns an array");
    assert_true(isset($pages['data']), "Pages result has 'data' key");
    assert_true(count($pages['data']) > 0, "At least one page exists");
    $tests_passed++;
} catch (Exception $e) {
    echo "   ❌ Basic database operations failed: " . $e->getMessage() . "\n";
}

// Test 3: Page properties
echo "4. Testing page properties...\n";
$tests_total++;
try {
    // Get the Home page ID from the seeded data
    $stmt = $pdo->query("SELECT id FROM Pages WHERE name = 'Home'");
    $pageId = $stmt->fetchColumn();
    
    $page = $dm->getPageById($pageId);
    assert_true($page !== null, "Page with ID $pageId exists");
    assert_equal('Home', $page['name'], "Page name is 'Home'");
    assert_true(isset($page['properties']), "Page has properties");
    $tests_passed++;
} catch (Exception $e) {
    echo "   ❌ Page properties test failed: " . $e->getMessage() . "\n";
}

// Test 4: Note properties
echo "5. Testing note properties...\n";
$tests_total++;
try {
    // Get the first note ID from the seeded data
    $stmt = $pdo->query("SELECT id FROM Notes WHERE content = 'First note'");
    $noteId = $stmt->fetchColumn();
    
    $props = $dm->getNoteProperties($noteId);
    assert_true(is_array($props), "getNoteProperties returns an array");
    assert_true(isset($props['status']), "Note has status property");
    assert_equal('TODO', $props['status'][0]['value'], "Status property value is 'TODO'");
    $tests_passed++;
} catch (Exception $e) {
    echo "   ❌ Note properties test failed: " . $e->getMessage() . "\n";
}

// Test 5: PatternProcessor instantiation
echo "6. Testing PatternProcessor instantiation...\n";
$tests_total++;
try {
    $pp = new App\PatternProcessor($pdo);
    echo "   ✅ PatternProcessor instantiated successfully\n";
    $tests_passed++;
} catch (Exception $e) {
    echo "   ❌ PatternProcessor instantiation failed: " . $e->getMessage() . "\n";
}

// Test 6: PropertyTriggerService instantiation
echo "7. Testing PropertyTriggerService instantiation...\n";
$tests_total++;
try {
    $pts = new App\PropertyTriggerService($pdo);
    echo "   ✅ PropertyTriggerService instantiated successfully\n";
    $tests_passed++;
} catch (Exception $e) {
    echo "   ❌ PropertyTriggerService instantiation failed: " . $e->getMessage() . "\n";
}

// Test 7: WebhooksManager instantiation
echo "8. Testing WebhooksManager instantiation...\n";
$tests_total++;
try {
    $wm = new App\WebhooksManager($pdo);
    echo "   ✅ WebhooksManager instantiated successfully\n";
    $tests_passed++;
} catch (Exception $e) {
    echo "   ❌ WebhooksManager instantiation failed: " . $e->getMessage() . "\n";
}

// Summary
echo "\n=== SUMMARY ===\n";
echo "Tests passed: $tests_passed/$tests_total\n";
if ($tests_passed === $tests_total) {
    echo "✅ All tests passed! The setup is working correctly.\n";
    exit(0);
} else {
    echo "❌ Some tests failed. Check the output above for details.\n";
    exit(1);
}

echo "=== END QUICK TEST ===\n"; 