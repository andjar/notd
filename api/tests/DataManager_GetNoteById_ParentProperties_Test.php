<?php

// It's good practice to ensure we're running from the project root or adjust paths accordingly.
// For this script, we assume it's run in a context where these paths are correct.
require_once __DIR__ . '/../../config.php'; // For potential global constants or settings
require_once __DIR__ . '/../data_manager.php'; // The class we are testing

// Suppress header errors if running in CLI and session functions are called by included files
// @ini_set('session.use_cookies', '0');
// @ini_set('session.cache_limiter', '');

echo "DataManager_GetNoteById_ParentProperties_Test Output:\n\n";

function setupTestDatabase(): PDO {
    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
                active INTEGER DEFAULT 1, -- Added active column as DataManager uses it
                internal INTEGER DEFAULT 0 -- Added internal column
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
                weight REAL DEFAULT 0, -- Changed to REAL to align with typical use (e.g. 2.0, 3.0)
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                active INTEGER DEFAULT 1 -- Added active column
            )
        ");

        // Simplified schema for Attachments (as required by DataManager::getNoteById)
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

function createNote(PDO $pdo, int $id, ?int $parentId, string $content = '', int $pageId = 1, int $active = 1): void {
    $stmt = $pdo->prepare(
        "INSERT INTO Notes (id, page_id, parent_note_id, content, active) VALUES (:id, :page_id, :parent_note_id, :content, :active)"
    );
    $stmt->execute([':id' => $id, ':page_id' => $pageId, ':parent_note_id' => $parentId, ':content' => $content, ':active' => $active]);
}

function createProperty(PDO $pdo, int $noteId, string $name, string $value, float $weight = 0, int $active = 1): void {
    $stmt = $pdo->prepare(
        "INSERT INTO Properties (note_id, name, value, weight, active) VALUES (:note_id, :name, :value, :weight, :active)"
    );
    $stmt->execute([':note_id' => $noteId, ':name' => $name, ':value' => $value, ':weight' => $weight, ':active' => $active]);
}

// Helper for comparing parent_properties, ignoring order of keys and values within a property
function compareParentProperties(array $expected, ?array $actual): bool {
    if ($actual === null && empty($expected)) return true;
    if ($actual === null || $expected === null) return false; // one is null, other is not (empty handled above)
    if (count($expected) !== count($actual)) return false;

    foreach ($expected as $key => $expectedValues) {
        if (!isset($actual[$key])) return false;
        $actualValuesObjects = $actual[$key];
        $actualValues = array_map(function($item){ return $item['value']; }, $actualValuesObjects);

        $expectedSimpleValues = array_map(function($item){ return $item['value']; }, $expectedValues);

        sort($actualValues);
        sort($expectedSimpleValues);
        if ($actualValues !== $expectedSimpleValues) return false;
    }
    return true;
}

// --- Test Cases ---

function test_getNoteById_noParents_includeParentPropertiesTrue() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    createNote($pdo, 1, null, "Child Note");

    $dataManager = new DataManager($pdo);
    $result = $dataManager->getNoteById(1, false, true);

    $expectedParentProperties = []; // Or null, depending on implementation choice for no parents
    if (compareParentProperties($expectedParentProperties, $result['parent_properties'] ?? null) || ($result['parent_properties'] === null && empty($expectedParentProperties))) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        echo "Expected: " . json_encode($expectedParentProperties) . "\n";
        echo "Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
    }
}

function test_getNoteById_oneParent_includeParentPropertiesTrue() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    createNote($pdo, 101, null, "Parent Note");
    createProperty($pdo, 101, "color", "blue", 0);
    createNote($pdo, 102, 101, "Child Note");

    $dataManager = new DataManager($pdo);
    $result = $dataManager->getNoteById(102, false, true);

    $expectedParentProperties = ["color" => [["value" => "blue"]]];
    if (compareParentProperties($expectedParentProperties, $result['parent_properties'] ?? null)) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        echo "Expected: " . json_encode($expectedParentProperties) . "\n";
        echo "Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
    }
}

function test_getNoteById_multipleParentLevels_includeParentPropertiesTrue() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    createNote($pdo, 201, null, "GrandParent Note");
    createProperty($pdo, 201, "size", "large", 0);
    createNote($pdo, 202, 201, "Parent Note");
    createProperty($pdo, 202, "color", "red", 0);
    createNote($pdo, 203, 202, "Child Note");

    $dataManager = new DataManager($pdo);
    $result = $dataManager->getNoteById(203, false, true);

    $expectedParentProperties = [
        "size" => [["value" => "large"]],
        "color" => [["value" => "red"]]
    ];
    if (compareParentProperties($expectedParentProperties, $result['parent_properties'] ?? null)) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        echo "Expected: " . json_encode($expectedParentProperties) . "\n";
        echo "Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
    }
}

function test_getNoteById_parentProperties_uniqueValues() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    createNote($pdo, 301, null, "GrandParent Note");
    createProperty($pdo, 301, "tag", "important", 0);
    createProperty($pdo, 301, "status", "pending", 0);
    createNote($pdo, 302, 301, "Parent Note");
    createProperty($pdo, 302, "tag", "urgent", 0);
    createProperty($pdo, 302, "tag", "important", 0); // Duplicate value, different note
    createNote($pdo, 303, 302, "Child Note");

    $dataManager = new DataManager($pdo);
    $result = $dataManager->getNoteById(303, false, true);

    $expectedParentProperties = [
        "tag" => [["value" => "important"], ["value" => "urgent"]],
        "status" => [["value" => "pending"]]
    ];
     if (compareParentProperties($expectedParentProperties, $result['parent_properties'] ?? null)) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        echo "Expected: " . json_encode($expectedParentProperties) . "\n";
        echo "Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
    }
}


function test_getNoteById_parentProperties_respectsIncludeInternalFalse() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    createNote($pdo, 401, null, "Parent Note");
    createProperty($pdo, 401, "public", "true", 0); // weight 0 (not internal)
    createProperty($pdo, 401, "internal_id", "abc", 3); // weight 3 (internal)
    createNote($pdo, 402, 401, "Child Note");

    $dataManager = new DataManager($pdo);
    // includeInternal = false
    $result = $dataManager->getNoteById(402, false, true);

    $expectedParentProperties = ["public" => [["value" => "true"]]];
    if (compareParentProperties($expectedParentProperties, $result['parent_properties'] ?? null)) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        echo "Expected: " . json_encode($expectedParentProperties) . "\n";
        echo "Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
    }
}

function test_getNoteById_parentProperties_respectsIncludeInternalTrue() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    createNote($pdo, 501, null, "Parent Note");
    createProperty($pdo, 501, "public", "true", 0); // weight 0
    createProperty($pdo, 501, "internal_id", "abc", 3); // weight 3
    createNote($pdo, 502, 501, "Child Note");

    $dataManager = new DataManager($pdo);
    // includeInternal = true
    $result = $dataManager->getNoteById(502, true, true);

    $expectedParentProperties = [
        "public" => [["value" => "true"]],
        "internal_id" => [["value" => "abc"]]
    ];
    if (compareParentProperties($expectedParentProperties, $result['parent_properties'] ?? null)) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        echo "Expected: " . json_encode($expectedParentProperties) . "\n";
        echo "Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
    }
}

function test_getNoteById_includeParentPropertiesFalse() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    createNote($pdo, 601, null, "Parent Note");
    createProperty($pdo, 601, "color", "blue", 0);
    createNote($pdo, 602, 601, "Child Note");
    createProperty($pdo, 602, "own_prop", "value", 0);


    $dataManager = new DataManager($pdo);
    $result = $dataManager->getNoteById(602, false, false); // includeParentProperties = false

    // parent_properties should be null or empty
    $parentPropsOk = ($result['parent_properties'] === null || (is_array($result['parent_properties']) && empty($result['parent_properties'])));
    // Direct properties should still be there
    $directPropsOk = isset($result['properties']['own_prop']) && $result['properties']['own_prop'][0]['value'] === 'value';

    if ($parentPropsOk && $directPropsOk) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        if (!$parentPropsOk) {
            echo "Reason: Parent properties not null/empty. Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
        }
        if (!$directPropsOk) {
            echo "Reason: Direct properties incorrect or missing. Actual: " . json_encode($result['properties'] ?? null) . "\n";
        }
    }
}

function test_getNoteById_cyclicDependencyCheck() {
    echo __FUNCTION__ . ": ";
    $pdo = setupTestDatabase();
    // Note 701 is parent of 702, 702 is parent of 701 (cyclic)
    createNote($pdo, 701, 702, "Note 701");
    createProperty($pdo, 701, "propA", "valA");
    createNote($pdo, 702, 701, "Note 702");
    createProperty($pdo, 702, "propB", "valB");
    createNote($pdo, 703, 702, "Child Note of 702");


    $dataManager = new DataManager($pdo);
    $result = null;
    $error = null;
    try {
        // Fetching child 703, its parent is 702. 702's parent is 701. 701's parent is 702 (cycle)
        $result = $dataManager->getNoteById(703, true, true);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // The code should break the loop and not hang.
    // Expected: propB from note 702, and propA from note 701.
    // The exact content of parent_properties depends on how deep it goes before cycle detection.
    // The DataManager's `visitedParentIds` should prevent infinite loop.
    // We expect properties from 702 and 701.
    $expectedParentProperties = [
        "propB" => [["value" => "valB"]], // From parent 702
        "propA" => [["value" => "valA"]]  // From grandparent 701 (which is parent of 702)
    ];

    if ($error === null && compareParentProperties($expectedParentProperties, $result['parent_properties'] ?? null)) {
        echo "PASS (No error, properties as expected)\n";
    } else {
        echo "FAIL\n";
        if ($error) {
            echo "Error: " . $error . "\n";
        }
        echo "Expected: " . json_encode($expectedParentProperties) . "\n";
        echo "Actual: " . json_encode($result['parent_properties'] ?? null) . "\n";
    }
}


// --- Run Tests ---
function runAllTests() {
    // Get all declared functions
    $functions = get_defined_functions();
    $userFunctions = $functions['user'];

    // Filter for functions starting with "test_"
    $testFunctions = array_filter($userFunctions, function($functionName) {
        return strpos($functionName, 'test_') === 0;
    });

    echo "Running tests...\n";
    foreach ($testFunctions as $testFunction) {
        // Check if function name (string) is callable
        if (is_callable($testFunction)) {
            call_user_func($testFunction);
        } else {
            echo "Warning: Found function {$testFunction} that starts with test_ but is not callable.\n";
        }
    }
    echo "\nTests finished.\n";
}

runAllTests();

?>
