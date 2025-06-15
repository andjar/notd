<?php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../data_manager.php';

echo "SearchParentPropertiesTest Output:\n\n";

// --- Global variables for test state ---
/** @var ?PDO $testPdo */
$testPdo = null;
/** @var ?array $testEntities */
$testEntities = [
    'page_id' => null,
    'note_p_id' => null,
    'note_c1_id' => null,
    'note_c2_id' => null,
    'note_t_id' => null,
];
/** @var string $testDbPath */
$testDbPath = '';
/** @var string $baseApiUrl */
$baseApiUrl = '';


// --- Database Setup and Helper Functions ---

function setupTestDatabaseForSearch(): PDO {
    global $testDbPath;
    $testDbPath = __DIR__ . '/test_search_db.sqlite'; // Store for teardown and URL construction

    if (file_exists($testDbPath)) {
        unlink($testDbPath);
    }

    try {
        $pdo = new PDO('sqlite:' . $testDbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS Pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, content TEXT, alias TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, active INTEGER DEFAULT 1
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS Notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, parent_note_id INTEGER DEFAULT NULL, content TEXT,
            order_index INTEGER DEFAULT 0, collapsed INTEGER DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, active INTEGER DEFAULT 1, internal INTEGER DEFAULT 0,
            FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_note_id) REFERENCES Notes(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS Properties (
            id INTEGER PRIMARY KEY AUTOINCREMENT, note_id INTEGER DEFAULT NULL, page_id INTEGER DEFAULT NULL,
            name TEXT NOT NULL, value TEXT, weight REAL DEFAULT 2, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, active INTEGER DEFAULT 1,
            FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE CASCADE,
            FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS Notes_fts USING fts5(
            content, tokenize = 'porter unicode61 remove_diacritics 2'
        )");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS notes_ai AFTER INSERT ON Notes BEGIN INSERT INTO Notes_fts (rowid, content) VALUES (new.id, new.content); END;");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS notes_ad AFTER DELETE ON Notes BEGIN INSERT INTO Notes_fts (Notes_fts, rowid, content) VALUES ('delete', old.id, old.content); END;");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS notes_au AFTER UPDATE ON Notes BEGIN INSERT INTO Notes_fts (Notes_fts, rowid, content) VALUES ('delete', old.id, old.content); INSERT INTO Notes_fts (rowid, content) VALUES (new.id, new.content); END;");

        // Required by DataManager for some property operations, even if not directly used by search for parent props
        $pdo->exec("CREATE TABLE IF NOT EXISTS Attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT, note_id INTEGER NOT NULL, file_name TEXT NOT NULL, file_type TEXT,
                file_size INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, active INTEGER DEFAULT 1,
                FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE CASCADE
            )");

        return $pdo;
    } catch (PDOException $e) {
        die("Failed to create SQLite database '{$testDbPath}': " . $e->getMessage());
    }
}

function createPage(PDO $pdo, string $name, string $content = ''): int {
    $stmt = $pdo->prepare("INSERT INTO Pages (name, content) VALUES (:name, :content)");
    $stmt->execute([':name' => $name, ':content' => $content]);
    return $pdo->lastInsertId();
}

function createNoteForSearch(PDO $pdo, int $pageId, ?int $parentId, string $content, int $id = null): int {
    // To ensure FTS index is populated, we need to use DataManager or replicate its logic for property parsing.
    // For simplicity in test setup, we'll directly insert and then also call createPropertyForSearch for explicit properties.
    // The FTS trigger handles `content` for full-text search. `search.php` uses Properties table for `tasks=TODO`.
    if ($id === null) {
        $stmt = $pdo->prepare("INSERT INTO Notes (page_id, parent_note_id, content) VALUES (:page_id, :parent_note_id, :content)");
        $stmt->execute([':page_id' => $pageId, ':parent_note_id' => $parentId, ':content' => $content]);
        return $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, parent_note_id, content) VALUES (:id, :page_id, :parent_note_id, :content)");
        $stmt->execute([':id'=> $id, ':page_id' => $pageId, ':parent_note_id' => $parentId, ':content' => $content]);
        return $id;
    }
}

function createPropertyForSearch(PDO $pdo, ?int $noteId, string $name, string $value, float $weight = 2, ?int $pageId = null): void {
    $stmt = $pdo->prepare("INSERT INTO Properties (note_id, page_id, name, value, weight) VALUES (:note_id, :page_id, :name, :value, :weight)");
    $stmt->execute([':note_id' => $noteId, ':page_id' => $pageId, ':name' => $name, ':value' => $value, ':weight' => $weight]);
}

function compareParentProperties(array $expected, ?array $actual): bool {
    if ($actual === null && empty($expected)) return true;
    if ($actual === [] && empty($expected)) return true; // Treat empty array same as null for this comparison
    if ($expected === [] && $actual === null) return true;


    if ($actual === null || $expected === null) {
        if (empty($expected) && $actual === null) return true;
        if (empty($actual) && $expected === null) return true;
        return false;
    }
    if (count($expected) !== count($actual)) return false;

    foreach ($expected as $key => $expectedValuesObjects) {
        if (!isset($actual[$key])) return false;

        $actualValuesFromObjects = array_map(function($item){ return $item['value']; }, $actual[$key]);
        $expectedValuesFromObjects = array_map(function($item){ return $item['value']; }, $expectedValuesObjects);

        sort($actualValuesFromObjects);
        sort($expectedValuesFromObjects);
        if ($actualValuesFromObjects !== $expectedValuesFromObjects) return false;
    }
    return true;
}

function findNoteInResults(array $results, int $noteId): ?array {
    foreach ($results as $result) {
        if (isset($result['note_id']) && $result['note_id'] == $noteId) {
            return $result;
        }
    }
    return null;
}

function currentTestSetup(): void {
    global $testPdo, $testEntities, $baseApiUrl;
    $testPdo = setupTestDatabaseForSearch();

    $testEntities['page_id'] = createPage($testPdo, "Test Page for Search");

    $testEntities['note_p_id'] = createNoteForSearch($testPdo, $testEntities['page_id'], null, "Parent Note P {project_code::Alpha}");
    createPropertyForSearch($testPdo, $testEntities['note_p_id'], "project_code", "Alpha");

    $testEntities['note_c1_id'] = createNoteForSearch($testPdo, $testEntities['page_id'], $testEntities['note_p_id'], "Child task one {status::TODO}");
    createPropertyForSearch($testPdo, $testEntities['note_c1_id'], "status", "TODO");

    $testEntities['note_c2_id'] = createNoteForSearch($testPdo, $testEntities['page_id'], $testEntities['note_p_id'], "Child task two {status::TODO} {task_specific::Beta}");
    createPropertyForSearch($testPdo, $testEntities['note_c2_id'], "status", "TODO");
    createPropertyForSearch($testPdo, $testEntities['note_c2_id'], "task_specific", "Beta");

    $testEntities['note_t_id'] = createNoteForSearch($testPdo, $testEntities['page_id'], null, "Unrelated task T {status::TODO}");
    createPropertyForSearch($testPdo, $testEntities['note_t_id'], "status", "TODO");

    $baseApiUrl = getenv('TEST_API_BASE_URL') ?: 'http://localhost';
     // If running via a web server on a non-standard port during testing (e.g. php -S localhost:8000 from project root)
    if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
        if (strpos($baseApiUrl, 'localhost') !== false && strpos($baseApiUrl, ':'.$_SERVER['SERVER_PORT']) === false) {
             // Update $baseApiUrl to include the port if it's a localhost URL and port is non-standard
             // This assumes the test script itself is not being run via the same php -S server,
             // or relies on TEST_API_BASE_URL being set correctly.
        }
    }
}

function currentTestTeardown(): void {
    global $testPdo, $testDbPath;
    if ($testPdo) {
        $testPdo = null;
    }
    if (file_exists($testDbPath)) {
        unlink($testDbPath);
        echo "Teardown: Database file '{$testDbPath}' deleted.\n";
    } else {
        echo "Teardown: Database file '{$testDbPath}' not found or already deleted.\n";
    }
}

// --- Test Cases ---

function testSearchTasksWithParentProps() {
    echo __FUNCTION__ . ": ";
    global $testEntities, $baseApiUrl, $testDbPath;

    $queryUrl = $baseApiUrl . "/api/v1/search.php?tasks=TODO&include_parent_properties=true&DB_PATH_OVERRIDE=" . urlencode(realpath($testDbPath));
    $responseJson = @file_get_contents($queryUrl);

    if ($responseJson === false) {
        echo "FAIL - Could not fetch URL: $queryUrl (Ensure web server is running and configured, and TEST_API_BASE_URL is correct if not localhost)\n";
        return;
    }
    $response = json_decode($responseJson, true);

    if (!$response || !isset($response['status']) || $response['status'] !== 'success') {
        echo "FAIL - API error: " . ($response['message'] ?? $responseJson) . "\n";
        return;
    }

    $results = $response['data']['results'];

    $noteC1 = findNoteInResults($results, $testEntities['note_c1_id']);
    $noteC2 = findNoteInResults($results, $testEntities['note_c2_id']);
    $noteT = findNoteInResults($results, $testEntities['note_t_id']);

    $allFound = $noteC1 && $noteC2 && $noteT;
    if (!$allFound) {
        echo "FAIL - Not all notes found in results.\n";
        if(!$noteC1) echo "  Note C1 (ID {$testEntities['note_c1_id']}) missing.\n";
        if(!$noteC2) echo "  Note C2 (ID {$testEntities['note_c2_id']}) missing.\n";
        if(!$noteT) echo "  Note T (ID {$testEntities['note_t_id']}) missing.\n";
        return;
    }

    $c1DirectProps = $noteC1['properties'];
    $c1ParentProps = $noteC1['parent_properties'] ?? null;
    $c1StatusOk = isset($c1DirectProps['status']) && $c1DirectProps['status'][0]['value'] === 'TODO';
    $c1ParentOk = compareParentProperties(['project_code' => [['value' => 'Alpha']]], $c1ParentProps);

    $c2DirectProps = $noteC2['properties'];
    $c2ParentProps = $noteC2['parent_properties'] ?? null;
    $c2StatusOk = isset($c2DirectProps['status']) && $c2DirectProps['status'][0]['value'] === 'TODO';
    $c2SpecificOk = isset($c2DirectProps['task_specific']) && $c2DirectProps['task_specific'][0]['value'] === 'Beta';
    $c2ParentOk = compareParentProperties(['project_code' => [['value' => 'Alpha']]], $c2ParentProps);

    $tDirectProps = $noteT['properties'];
    $tParentProps = $noteT['parent_properties'] ?? null;
    $tStatusOk = isset($tDirectProps['status']) && $tDirectProps['status'][0]['value'] === 'TODO';
    $tParentOk = compareParentProperties([], $tParentProps);

    if ($c1StatusOk && $c1ParentOk && $c2StatusOk && $c2SpecificOk && $c2ParentOk && $tStatusOk && $tParentOk) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        if (!$c1StatusOk) echo "  Note C1 status incorrect. Expected TODO, Got: " . json_encode($c1DirectProps['status'] ?? null) . "\n";
        if (!$c1ParentOk) echo "  Note C1 parent_properties incorrect. Expected Alpha, Got: " . json_encode($c1ParentProps) . "\n";
        if (!$c2StatusOk) echo "  Note C2 status incorrect. Expected TODO, Got: " . json_encode($c2DirectProps['status'] ?? null) . "\n";
        if (!$c2SpecificOk) echo "  Note C2 task_specific incorrect. Expected Beta, Got: " . json_encode($c2DirectProps['task_specific'] ?? null) . "\n";
        if (!$c2ParentOk) echo "  Note C2 parent_properties incorrect. Expected Alpha, Got: " . json_encode($c2ParentProps) . "\n";
        if (!$tStatusOk) echo "  Note T status incorrect. Expected TODO, Got: " . json_encode($tDirectProps['status'] ?? null) . "\n";
        if (!$tParentOk) echo "  Note T parent_properties not empty. Got: " . json_encode($tParentProps) . "\n";
    }
}

function testSearchTasksWithoutParentProps() {
    echo __FUNCTION__ . ": ";
    global $testEntities, $baseApiUrl, $testDbPath;

    $queryUrl = $baseApiUrl . "/api/v1/search.php?tasks=TODO&include_parent_properties=false&DB_PATH_OVERRIDE=" . urlencode(realpath($testDbPath));
    $responseJson = @file_get_contents($queryUrl);

    if ($responseJson === false) {
        echo "FAIL - Could not fetch URL: $queryUrl\n"; return;
    }
    $response = json_decode($responseJson, true);

    if (!$response || !isset($response['status']) || $response['status'] !== 'success') {
        echo "FAIL - API error: " . ($response['message'] ?? $responseJson) . "\n"; return;
    }
    $results = $response['data']['results'];

    $noteC1 = findNoteInResults($results, $testEntities['note_c1_id']);
    $noteC2 = findNoteInResults($results, $testEntities['note_c2_id']);
    $noteT = findNoteInResults($results, $testEntities['note_t_id']);

    $allFound = $noteC1 && $noteC2 && $noteT;
    if (!$allFound) {
        echo "FAIL - Not all notes found.\n"; return;
    }

    $c1ParentOk = !isset($noteC1['parent_properties']) || empty($noteC1['parent_properties']);
    $c2ParentOk = !isset($noteC2['parent_properties']) || empty($noteC2['parent_properties']);
    $tParentOk = !isset($noteT['parent_properties']) || empty($noteT['parent_properties']);

    if ($c1ParentOk && $c2ParentOk && $tParentOk) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        if (!$c1ParentOk) echo "  Note C1 has parent_properties: " . json_encode($noteC1['parent_properties'] ?? null) . "\n";
        if (!$c2ParentOk) echo "  Note C2 has parent_properties: " . json_encode($noteC2['parent_properties'] ?? null) . "\n";
        if (!$tParentOk) echo "  Note T has parent_properties: " . json_encode($noteT['parent_properties'] ?? null) . "\n";
    }
}

function testSearchQueryWithParentProps() {
    echo __FUNCTION__ . ": ";
    global $testEntities, $baseApiUrl, $testDbPath;

    $queryUrl = $baseApiUrl . "/api/v1/search.php?q=Child%20task%20one&include_parent_properties=true&DB_PATH_OVERRIDE=" . urlencode(realpath($testDbPath));
    $responseJson = @file_get_contents($queryUrl);

    if ($responseJson === false) {
        echo "FAIL - Could not fetch URL: $queryUrl\n"; return;
    }
    $response = json_decode($responseJson, true);

    if (!$response || !isset($response['status']) || $response['status'] !== 'success') {
        echo "FAIL - API error: " . ($response['message'] ?? $responseJson) . "\n"; return;
    }
    $results = $response['data']['results'];
    $noteC1 = findNoteInResults($results, $testEntities['note_c1_id']);

    if (!$noteC1) {
        echo "FAIL - Note C1 not found for query 'Child task one'.\n"; return;
    }

    $c1ParentProps = $noteC1['parent_properties'] ?? null;
    $c1ParentOk = compareParentProperties(['project_code' => [['value' => 'Alpha']]], $c1ParentProps);

    if ($c1ParentOk) {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        echo "  Note C1 parent_properties incorrect for 'q' search. Expected Alpha, Got: " . json_encode($c1ParentProps) . "\n";
    }
}

function runAllSearchTests() {
    currentTestSetup();

    $functions = get_defined_functions();
    $userFunctions = $functions['user'];
    $testFunctions = [];
    foreach ($userFunctions as $funcName) {
        if (strpos($funcName, 'test') === 0) {
            $reflFunc = new ReflectionFunction($funcName);
            if ($reflFunc->getFileName() === __FILE__) {
                $testFunctions[] = $funcName;
            }
        }
    }

    echo "Running SearchParentProperties Tests...\n";
    foreach ($testFunctions as $testFunction) {
        if (is_callable($testFunction)) {
            call_user_func($testFunction);
        }
    }
    echo "\nSearch Tests finished.\n";

    currentTestTeardown();
}

// --- Entry point to run tests ---
runAllSearchTests();

?>
