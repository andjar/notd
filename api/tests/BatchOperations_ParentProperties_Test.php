<?php

require_once __DIR__ . '/../../config.php';
// The batch_operations.php file includes db_connect.php, response_utils.php, data_manager.php, pattern_processor.php
// It also now defines process_batch_request()
require_once __DIR__ . '/../v1/batch_operations.php';

echo "BatchOperations_ParentProperties_Test Output:\n\n";

// --- Helper Functions (copied from DataManager_GetNoteById_ParentProperties_Test.php) ---
function setupTestDatabase(): PDO {
    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON;"); // Ensure foreign keys are enforced for tests

        // Simplified schema for Notes
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER,
                parent_note_id INTEGER DEFAULT NULL,
                content TEXT,
                order_index INTEGER DEFAULT 0,
                collapsed INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                active INTEGER DEFAULT 1,
                internal INTEGER DEFAULT 0,
                FOREIGN KEY (parent_note_id) REFERENCES Notes(id) ON DELETE SET NULL
            )
        ");

        // Simplified schema for Properties
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Properties (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                note_id INTEGER DEFAULT NULL,
                page_id INTEGER DEFAULT NULL,
                name TEXT NOT NULL,
                value TEXT,
                weight REAL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                active INTEGER DEFAULT 1,
                FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE CASCADE,
                FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE
            )
        ");

        // Dummy Pages table for foreign key constraints if any are implied by Properties or Notes
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT
            )
        ");
        // Insert a default page if operations rely on a page_id
        $pdo->exec("INSERT INTO Pages (id, title) VALUES (1, 'Test Page')");


        // Simplified schema for Attachments (as required by DataManager::getNoteById which is called internally)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                note_id INTEGER NOT NULL,
                file_name TEXT NOT NULL,
                file_type TEXT,
                file_size INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                active INTEGER DEFAULT 1,
                FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE CASCADE
            )
        ");
        return $pdo;
    } catch (PDOException $e) {
        die("Failed to create in-memory SQLite database: " . $e->getMessage());
    }
}

// Note: createNote in batch operations does not take an ID. ID is auto-generated.
// This helper is for setting up pre-existing notes.
function createDbNote(PDO $pdo, int $id, ?int $parentId, string $content = '', int $pageId = 1, int $active = 1): void {
    $stmt = $pdo->prepare(
        "INSERT INTO Notes (id, page_id, parent_note_id, content, active) VALUES (:id, :page_id, :parent_note_id, :content, :active)"
    );
    $stmt->execute([':id' => $id, ':page_id' => $pageId, ':parent_note_id' => $parentId, ':content' => $content, ':active' => $active]);
}

function createDbProperty(PDO $pdo, int $noteId, string $name, string $value, float $weight = 0, int $active = 1): void {
    $stmt = $pdo->prepare(
        "INSERT INTO Properties (note_id, name, value, weight, active, page_id) VALUES (:note_id, :name, :value, :weight, :active, 1)" // Assuming page_id 1 for properties
    );
    $stmt->execute([':note_id' => $noteId, ':name' => $name, ':value' => $value, ':weight' => $weight, ':active' => $active]);
}

function compareParentProperties(array $expected, ?array $actual): bool {
    if ($actual === null && empty($expected)) return true;
    if ($actual === null || $expected === null) return false;
    if (count($expected) !== count($actual)) return false;

    // Normalize actual properties to only include 'value' for comparison simplicity, matching expected structure
    $normalizedActual = [];
    foreach($actual as $key => $props) {
        $normalizedActual[$key] = array_map(function($p) { return ['value' => $p['value']]; }, $props);
    }
    $actual = $normalizedActual;


    foreach ($expected as $key => $expectedValues) {
        if (!isset($actual[$key])) return false;

        $actualPropValues = array_map(function($item){ return $item['value']; }, $actual[$key]);
        $expectedPropValues = array_map(function($item){ return $item['value']; }, $expectedValues);

        sort($actualPropValues);
        sort($expectedPropValues);
        if ($actualPropValues !== $expectedPropValues) return false;
    }
    return true;
}
// --- End Helper Functions ---

// --- New Helper Function for Batch Operations ---
/** @var PDO|null $currentPdo For tests to reuse the same DB connection */
$currentPdo = null;

function executeBatchOperations(array $operations, bool $includeParentProperties): array {
    global $currentPdo; // Use the global PDO object for the test session

    if ($currentPdo === null) {
        // This should ideally be set up once per test run or per test case group
        echo "Error: PDO object not initialized for executeBatchOperations.\n";
        return ['error' => 'PDO not initialized'];
    }

    $requestData = [
        'operations' => $operations,
        'include_parent_properties' => $includeParentProperties
    ];

    // Call the refactored function from batch_operations.php
    // Pass the existing $currentPdo to ensure operations use the test database
    return process_batch_request($requestData, $currentPdo);
}
// --- End New Helper Function ---


// --- Test Cases ---
function test_create_note_with_parent_properties() {
    echo __FUNCTION__ . ": ";
    global $currentPdo;
    $currentPdo = setupTestDatabase();

    createDbNote($currentPdo, 10, null, "Parent P10");
    createDbProperty($currentPdo, 10, "color", "blue");

    $operations = [
        [
            'type' => 'create',
            'payload' => [
                'page_id' => 1,
                'content' => 'Child C1 of P10',
                'parent_note_id' => 10,
                'client_temp_id' => 'temp-c1'
            ]
        ]
    ];

    $result = executeBatchOperations($operations, true);

    if (isset($result['results'][0]['status']) && $result['results'][0]['status'] === 'success') {
        $note = $result['results'][0]['note'];
        $expectedParentProperties = ["color" => [["value" => "blue"]]];
        if (compareParentProperties($expectedParentProperties, $note['parent_properties'] ?? null)) {
            echo "PASS\n";
        } else {
            echo "FAIL - Parent properties mismatch.\n";
            echo "Expected: " . json_encode($expectedParentProperties) . "\n";
            echo "Actual: " . json_encode($note['parent_properties'] ?? null) . "\n";
        }
    } else {
        echo "FAIL - Operation unsuccessful or error in result.\n";
        print_r($result);
    }
    $currentPdo = null;
}

function test_create_note_without_parent_properties() {
    echo __FUNCTION__ . ": ";
    global $currentPdo;
    $currentPdo = setupTestDatabase();

    createDbNote($currentPdo, 20, null, "Parent P20");
    createDbProperty($currentPdo, 20, "color", "red");

    $operations = [
        [
            'type' => 'create',
            'payload' => [
                'page_id' => 1,
                'content' => 'Child C1 of P20',
                'parent_note_id' => 20
            ]
        ]
    ];

    $result = executeBatchOperations($operations, false); // include_parent_properties: false

    if (isset($result['results'][0]['status']) && $result['results'][0]['status'] === 'success') {
        $note = $result['results'][0]['note'];
        if (empty($note['parent_properties']) || $note['parent_properties'] === null) {
            echo "PASS\n";
        } else {
            echo "FAIL - Parent properties not empty/null.\n";
            echo "Actual: " . json_encode($note['parent_properties']) . "\n";
        }
    } else {
        echo "FAIL - Operation unsuccessful.\n";
        print_r($result);
    }
    $currentPdo = null;
}

function test_update_note_with_parent_properties() {
    echo __FUNCTION__ . ": ";
    global $currentPdo;
    $currentPdo = setupTestDatabase();

    createDbNote($currentPdo, 30, null, "Parent P30");
    createDbProperty($currentPdo, 30, "size", "large");
    createDbNote($currentPdo, 31, 30, "Child C31"); // Child note to be updated

    $operations = [
        [
            'type' => 'update',
            'payload' => [
                'id' => 31,
                'content' => 'Updated content for C31'
            ]
        ]
    ];

    $result = executeBatchOperations($operations, true);

    if (isset($result['results'][0]['status']) && $result['results'][0]['status'] === 'success') {
        $note = $result['results'][0]['note'];
        $expectedParentProperties = ["size" => [["value" => "large"]]];
        if (compareParentProperties($expectedParentProperties, $note['parent_properties'] ?? null)) {
            echo "PASS\n";
        } else {
            echo "FAIL - Parent properties mismatch.\n";
            echo "Expected: " . json_encode($expectedParentProperties) . "\n";
            echo "Actual: " . json_encode($note['parent_properties'] ?? null) . "\n";
        }
    } else {
        echo "FAIL - Operation unsuccessful.\n";
        print_r($result);
    }
    $currentPdo = null;
}

function test_update_note_without_parent_properties() {
    echo __FUNCTION__ . ": ";
    global $currentPdo;
    $currentPdo = setupTestDatabase();

    createDbNote($currentPdo, 40, null, "Parent P40");
    createDbProperty($currentPdo, 40, "size", "small");
    createDbNote($currentPdo, 41, 40, "Child C41");

    $operations = [
        [
            'type' => 'update',
            'payload' => [
                'id' => 41,
                'content' => 'Updated content for C41'
            ]
        ]
    ];

    $result = executeBatchOperations($operations, false); // include_parent_properties: false

    if (isset($result['results'][0]['status']) && $result['results'][0]['status'] === 'success') {
        $note = $result['results'][0]['note'];
        if (empty($note['parent_properties']) || $note['parent_properties'] === null) {
            echo "PASS\n";
        } else {
            echo "FAIL - Parent properties not empty/null.\n";
            echo "Actual: " . json_encode($note['parent_properties']) . "\n";
        }
    } else {
        echo "FAIL - Operation unsuccessful.\n";
        print_r($result);
    }
    $currentPdo = null;
}

function test_batch_mixed_operations_with_parent_properties() {
    echo __FUNCTION__ . ": ";
    global $currentPdo;
    $currentPdo = setupTestDatabase();

    createDbNote($currentPdo, 50, null, "Parent P50");
    createDbProperty($currentPdo, 50, "project", "Alpha");
    createDbNote($currentPdo, 51, 50, "Child C51 (to be updated)");

    $operations = [
        [
            'type' => 'create',
            'payload' => [
                'page_id' => 1,
                'content' => 'New Child C52 of P50',
                'parent_note_id' => 50,
                'client_temp_id' => 'temp-c52'
            ]
        ],
        [
            'type' => 'update',
            'payload' => [
                'id' => 51,
                'content' => 'Updated C51 content'
            ]
        ]
    ];

    $result = executeBatchOperations($operations, true);
    $expectedParentProperties = ["project" => [["value" => "Alpha"]]];
    $allPass = true;

    if (count($result['results'] ?? []) !== 2) {
        echo "FAIL - Expected 2 results in the batch.\n";
        print_r($result);
        $currentPdo = null;
        return;
    }

    foreach ($result['results'] as $opResult) {
        if (!isset($opResult['status']) || $opResult['status'] !== 'success') {
            echo "FAIL - Operation " . ($opResult['type'] ?? 'unknown') . " was not successful.\n";
            print_r($opResult);
            $allPass = false;
            continue;
        }
        $note = $opResult['note'];
        if (!compareParentProperties($expectedParentProperties, $note['parent_properties'] ?? null)) {
            echo "FAIL - Parent properties mismatch for " . ($opResult['type'] ?? 'unknown') . " note ID " . ($note['id'] ?? 'N/A') . ".\n";
            echo "Expected: " . json_encode($expectedParentProperties) . "\n";
            echo "Actual: " . json_encode($note['parent_properties'] ?? null) . "\n";
            $allPass = false;
        }
    }

    if ($allPass) {
        echo "PASS\n";
    }
    $currentPdo = null;
}

// --- Run Tests ---
function runAllBatchOpTests() {
    // Get all declared functions in this file
    $functions = get_defined_functions()['user'];

    // Filter for functions starting with "test_"
    $testFunctions = [];
    foreach ($functions as $func) {
        // Check if function is defined in the current file
        $rf = new ReflectionFunction($func);
        if (basename($rf->getFileName()) === basename(__FILE__) && strpos($func, 'test_') === 0) {
            $testFunctions[] = $func;
        }
    }

    echo "Running BatchOperations_ParentProperties_Test tests...\n";
    $originalPdo = $GLOBALS['currentPdo'] ?? null; // Save global state

    foreach ($testFunctions as $testFunction) {
        if (is_callable($testFunction)) {
            call_user_func($testFunction);
        } else {
            echo "Warning: Found function {$testFunction} that starts with test_ but is not callable.\n";
        }
    }

    $GLOBALS['currentPdo'] = $originalPdo; // Restore global state
    echo "\nBatchOperations_ParentProperties_Test tests finished.\n";
}

// Only run tests if this file is executed directly
if (php_sapi_name() === 'cli' && getenv('PHPUNIT_TEST_SUITE') === false) {
    runAllBatchOpTests();
}

?>
