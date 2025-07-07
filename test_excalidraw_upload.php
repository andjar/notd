<?php

// Test script for excalidraw multiple attachment upload

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_error.log'); // Log errors to a local file

// echo "Starting Excalidraw Upload Test...\n"; // Commented out
error_log("Starting Excalidraw Upload Test...");


error_log("[Test Script] Phase 1: Script Start");

// Define UPLOADS_DIR if not defined (adjust path as necessary for testing)
if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', __DIR__ . '/uploads_test_dir');
}

$year = date('Y');
$month = date('m');
$testUploadPath = UPLOADS_DIR . '/' . $year . '/' . $month;

if (!file_exists($testUploadPath)) {
    if (!mkdir($testUploadPath, 0777, true)) {
        error_log("CRITICAL: Failed to create test upload directory: " . $testUploadPath . ". Test may fail.");
    } else {
        error_log("Created test upload directory: " . $testUploadPath);
    }
}
error_log("[Test Script] Phase 2: After UPLOADS_DIR setup");

$tmpDir = sys_get_temp_dir();
$pngTmpName = tempnam($tmpDir, 'test_png_');
if ($pngTmpName) file_put_contents($pngTmpName, 'dummy png content'); else error_log("Failed to create temp png file");
$jsonTmpName = tempnam($tmpDir, 'test_json_');
if ($jsonTmpName) file_put_contents($jsonTmpName, '{"type": "excalidraw"}'); else error_log("Failed to create temp json file");

$_POST['note_id'] = '123';

$_FILES['attachmentFile'] = [
    'name' => ['excalidraw_test_01.png', 'excalidraw_test_01.excalidraw'],
    'type' => ['image/png', 'application/json'],
    'tmp_name' => [$pngTmpName, $jsonTmpName],
    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
    'size' => [$pngTmpName ? strlen('dummy png content') : 0, $jsonTmpName ? strlen('{"type": "excalidraw"}') : 0]
];
// error_log("[Test Script] Phase 3: After \$_POST and \$_FILES simulation. POST: " . print_r($_POST, true) . " FILES: " . print_r($_FILES, true));
// Using individual error_log for POST and FILES to avoid potential issues with print_r in single log call
error_log("[Test Script] Phase 3: After \$_POST and \$_FILES simulation.");
error_log("[Test Script] POST data: " . print_r($_POST, true));
error_log("[Test Script] FILES data: " . print_r($_FILES, true));


$pdo = null;
if (!class_exists('PDO')) {
    error_log("[Test Script] PDO class does not exist. Defining Mock PDO.");
    class PDO {
        public function __construct($dsn, $username = null, $password = null, $options = null) {}
        public function prepare($statement) { $s = new MockPDOStatement($this); $s->queryString = $statement; return $s; }
        public function beginTransaction() {} public function commit() {} public function rollBack() {}
        public function lastInsertId() { return rand(1, 1000); } public function inTransaction() { return false; }
        public function errorCode() { return '00000'; } public function errorInfo() { return ['00000', null, null]; }
        public function query($query) { $s = new MockPDOStatement($this); $s->queryString = $query; return $s;}
        public function exec($query) {return 0;}
    }
    class MockPDOStatement {
        private $pdo; public $queryString;
        public function __construct(PDO $pdo) { $this->pdo = $pdo; }
        public function execute($params = null) { return true; }
        public function fetch($fetch_style = null) {
            if (strpos($this->queryString, "SELECT id, page_id FROM Notes WHERE id = ?") !== false) return ['id' => ($_POST['note_id'] ?? '1'), 'page_id' => '1'];
            if (strpos($this->queryString, "SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'") !== false) return ['name'=>'Pages'];
            if (strpos($this->queryString, "SELECT id FROM Pages WHERE id = 1") !==false) return ['id'=>1];
            if (strpos($this->queryString, "SELECT id FROM Notes WHERE id = ?") !==false) return ['id'=>$_POST['note_id']];
            return null;
        }
        public function fetchAll($fetch_style = null) { return []; }
    }
    function get_db_connection_mock_only() {
        error_log('[Test Script] Using Mock PDO connection (class PDO did not exist).');
        return new PDO('sqlite::memory:');
    }
    $pdo = get_db_connection_mock_only();
} else {
    error_log("[Test Script] PDO class exists.");
    if (file_exists(__DIR__ . '/api/db_connect.php')) {
        error_log("[Test Script] Phase 4a: Before requiring db_connect.php");
        require_once __DIR__ . '/api/db_connect.php';
        error_log("[Test Script] Phase 4b: After requiring db_connect.php");

        if (function_exists('get_db_connection')) {
            $pdo_test_conn = get_db_connection();
            error_log("[Test Script] Phase 4c: After calling get_db_connection() from db_connect.php. Is PDO? " . ($pdo_test_conn instanceof \PDO ? 'Yes' : 'No'));
            if ($pdo_test_conn instanceof \PDO) {
                error_log("[Test Script] Phase 4e: Successfully got actual PDO connection.");
                // echo "Using actual PDO connection via db_connect.php.\n"; // Commented out
                error_log("Using actual PDO connection via db_connect.php.");
                $pdo = $pdo_test_conn;
            } else {
                error_log("[Test Script] Phase 4d: get_db_connection() did not return PDO. Using basic mock.");
                if ($pdo === null) { $pdo = new PDO('sqlite::memory:'); }
            }
        } else {
            error_log("[Test Script] ERROR: get_db_connection function does not exist after including db_connect.php. Using basic mock.");
            $pdo = new PDO('sqlite::memory:');
        }
    } else {
        error_log("[Test Script] ERROR: db_connect.php not found. Using basic mock PDO.");
        $pdo = new PDO('sqlite::memory:');
    }
}
error_log("[Test Script] Phase 5: After DB Setup block. Is PDO object set? " . ($pdo instanceof \PDO ? 'Yes' : 'No'));

error_log("[Test Script] Phase 5a: Before requiring response_utils.php");
require_once __DIR__ . '/api/response_utils.php';
error_log("[Test Script] Phase 5b: After requiring response_utils.php");
error_log("[Test Script] Phase 5c: Before requiring validator_utils.php");
require_once __DIR__ . '/api/validator_utils.php';
error_log("[Test Script] Phase 5d: After requiring validator_utils.php");

if (!defined('DB_PATH')) { define('DB_PATH', __DIR__ . '/db/database.sqlite'); error_log("[Test Script] Defined DB_PATH as fallback."); }
if (!defined('LOG_PATH')) { define('LOG_PATH', __DIR__ . '/logs'); error_log("[Test Script] Defined LOG_PATH."); }

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
error_log("[Test Script] Phase 6: REQUEST_METHOD is: " . ($_SERVER['REQUEST_METHOD'] ?? 'NOT SET'));

require_once __DIR__ . '/api/v1/attachments.php';
error_log("[Test Script] Phase 7: After including attachments.php (it was likely already included by auto-prepend)");

if ($pdo === null) {
    error_log("[Test Script] CRITICAL ERROR: PDO object is null before instantiating AttachmentManager.");
    if (class_exists('PDO')) { $pdo = new PDO('sqlite::memory:'); } else { die("PDO class not found, cannot mock for AttachmentManager.");}
}
error_log("[Test Script] Phase 8: About to instantiate AttachmentManager. Is PDO object valid? " . ($pdo instanceof \PDO ? 'Yes' : 'No'));

// Pre-insert note if using actual DB
if (strpos(get_class($pdo), 'Mock') === false) {
    try {
        $noteIdToTest = $_POST['note_id'] ?? '123';
        $pageCheckStmt = $pdo->query("SELECT id FROM Pages WHERE id = 1");
        if (!$pageCheckStmt || !$pageCheckStmt->fetch()) {
            $pdo->exec("INSERT OR IGNORE INTO Pages (id, name, title, content, created_at, updated_at) VALUES (1, 'test_page_for_attachments', 'Test Page', 'Test Content', '2024-01-01 00:00:00', '2024-01-01 00:00:00')");
            error_log("[Test Script] Attempted to insert dummy page with ID 1.");
        }

        $stmt = $pdo->prepare("SELECT id FROM Notes WHERE id = ?");
        $stmt->execute([$noteIdToTest]);
        if (!$stmt->fetch()) {
            $insertNoteStmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index, created_at, updated_at) VALUES (?, 1, 'Test note content', 1, datetime('now'), datetime('now'))");
            $insertNoteStmt->execute([$noteIdToTest]);
            error_log("[Test Script] Inserted dummy note with ID {$noteIdToTest} for testing into actual DB.");
        } else {
            error_log("[Test Script] Note with ID {$noteIdToTest} already exists in actual DB.");
        }
    } catch (Exception $e) {
        error_log("[Test Script] ERROR setting up test note in actual DB: " . $e->getMessage());
    }
}


ob_start();
$attachmentManager = new App\AttachmentManager($pdo);
error_log("[Test Script] Phase 9: AttachmentManager instantiated.");
$attachmentManager->handleRequest();
error_log("[Test Script] Phase 10: AttachmentManager handleRequest called.");
$output = ob_get_clean();
error_log("[Test Script] Phase 11: Output captured. Output: " . $output);

// These echos are fine as they are after ob_get_clean() and for test script's own output
echo "--- AttachmentManager Output ---\n";
echo $output . "\n";
echo "--- End AttachmentManager Output ---\n";

echo "\n--- Verification ---\n";
$expectedPngFilename = 'excalidraw_test_01.png';
$expectedJsonFilename = 'excalidraw_test_01.excalidraw';
$pngFound = false;
$jsonFound = false;

if (file_exists($testUploadPath) && is_dir($testUploadPath)) {
    $uploadedFiles = scandir($testUploadPath);
    if ($uploadedFiles) {
        foreach ($uploadedFiles as $file) {
            if (strpos($file, $expectedPngFilename) !== false) $pngFound = true;
            if (strpos($file, $expectedJsonFilename) !== false) $jsonFound = true;
        }
    } else {
         error_log("[Test Script] Verification: Failed to scandir {$testUploadPath}.");
    }
} else {
    error_log("[Test Script] Verification: Test upload path {$testUploadPath} does not exist or is not a directory.");
}


if ($pngFound && $jsonFound) {
    echo "SUCCESS: Both PNG and JSON files appear to be saved in the uploads directory.\n";
    error_log("[Test Script] VERIFICATION SUCCESS: Both files found in {$testUploadPath}.");
} else {
    echo "ERROR: One or both files were not found in the uploads directory.\n";
    error_log("[Test Script] VERIFICATION ERROR: Files not found. PNG found: " . ($pngFound?'yes':'no') . ", JSON found: " . ($jsonFound?'yes':'no') . " in {$testUploadPath}");
}

$decodedOutput = json_decode($output, true);
if ($decodedOutput && isset($decodedOutput['status']) && $decodedOutput['status'] === 'success' && isset($decodedOutput['data']['attachments']) && count($decodedOutput['data']['attachments']) === 2) {
    echo "SUCCESS: AttachmentManager API response indicates success with 2 attachments.\n";
    error_log("[Test Script] VERIFICATION SUCCESS: API response indicates 2 attachments.");
    foreach ($decodedOutput['data']['attachments'] as $att) {
        error_log("[Test Script] Saved attachment details: Name: {$att['name']}, Type: {$att['type']}, Path: {$att['path']}");
    }
} else {
    echo "ERROR: AttachmentManager API response does not indicate success or correct number of attachments.\n";
    error_log("[Test Script] VERIFICATION ERROR: API response incorrect. Raw output: " . $output);
}

if ($pngTmpName && file_exists($pngTmpName)) unlink($pngTmpName);
if ($jsonTmpName && file_exists($jsonTmpName)) unlink($jsonTmpName);

// echo "\nExcalidraw Upload Test Finished.\n"; // Commented out
error_log("Excalidraw Upload Test Finished.");
?>
