<?php
require_once 'config.php';
require_once 'api/uuid_utils.php';

use App\UuidUtils;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>UUID System Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test { margin: 10px 0; padding: 10px; border: 1px solid #ccc; }
        .pass { background-color: #d4edda; border-color: #c3e6cb; }
        .fail { background-color: #f8d7da; border-color: #f5c6cb; }
        .error { background-color: #fff3cd; border-color: #ffeaa7; }
    </style>
</head>
<body>
    <h1>UUID System Test</h1>
    
    <?php
    $tests = [];
    
    try {
        // Test 1: Generate UUIDs
        $uuid1 = UuidUtils::generateUuidV7();
        $uuid2 = UuidUtils::generateUuidV7();
        $tests[] = [
            'name' => 'UUID Generation',
            'status' => ($uuid1 !== $uuid2) ? 'pass' : 'fail',
            'message' => "Generated UUID 1: $uuid1<br>Generated UUID 2: $uuid2<br>UUIDs are different: " . ($uuid1 !== $uuid2 ? "PASS" : "FAIL")
        ];
        
        // Test 2: Validate UUIDs
        $tests[] = [
            'name' => 'UUID Validation',
            'status' => (UuidUtils::isValidUuidV7($uuid1) && UuidUtils::isValidUuidV7($uuid2) && !UuidUtils::isValidUuidV7("invalid-uuid")) ? 'pass' : 'fail',
            'message' => "UUID 1 is valid: " . (UuidUtils::isValidUuidV7($uuid1) ? "PASS" : "FAIL") . "<br>" .
                        "UUID 2 is valid: " . (UuidUtils::isValidUuidV7($uuid2) ? "PASS" : "FAIL") . "<br>" .
                        "Invalid UUID test: " . (!UuidUtils::isValidUuidV7("invalid-uuid") ? "PASS" : "FAIL")
        ];
        
        // Test 3: Check UUID format
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $tests[] = [
            'name' => 'UUID Format',
            'status' => (preg_match($uuidPattern, $uuid1) && preg_match($uuidPattern, $uuid2)) ? 'pass' : 'fail',
            'message' => "UUID 1 format: " . (preg_match($uuidPattern, $uuid1) ? "PASS" : "FAIL") . "<br>" .
                        "UUID 2 format: " . (preg_match($uuidPattern, $uuid2) ? "PASS" : "FAIL")
        ];
        
        // Test 4: Extract timestamp
        $timestamp1 = UuidUtils::extractTimestamp($uuid1);
        $timestamp2 = UuidUtils::extractTimestamp($uuid2);
        $tests[] = [
            'name' => 'Timestamp Extraction',
            'status' => ($timestamp1 !== $timestamp2) ? 'pass' : 'fail',
            'message' => "UUID 1 timestamp: $timestamp1<br>UUID 2 timestamp: $timestamp2<br>Timestamps are different: " . ($timestamp1 !== $timestamp2 ? "PASS" : "FAIL")
        ];
        
        // Test 5: Check looksLikeUuid function
        $tests[] = [
            'name' => 'looksLikeUuid Function',
            'status' => (UuidUtils::looksLikeUuid($uuid1) && UuidUtils::looksLikeUuid($uuid2) && !UuidUtils::looksLikeUuid("not-a-uuid")) ? 'pass' : 'fail',
            'message' => "UUID 1 looks like UUID: " . (UuidUtils::looksLikeUuid($uuid1) ? "PASS" : "FAIL") . "<br>" .
                        "UUID 2 looks like UUID: " . (UuidUtils::looksLikeUuid($uuid2) ? "PASS" : "FAIL") . "<br>" .
                        "Invalid string looks like UUID: " . (!UuidUtils::looksLikeUuid("not-a-uuid") ? "PASS" : "FAIL")
        ];
        
        // Test 6: Test database connection and schema
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('Pages', 'Notes')");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $tests[] = [
                'name' => 'Database Schema',
                'status' => (in_array('Pages', $tables) && in_array('Notes', $tables)) ? 'pass' : 'fail',
                'message' => "Required tables found: " . implode(', ', $tables)
            ];
        } catch (Exception $e) {
            $tests[] = [
                'name' => 'Database Schema',
                'status' => 'error',
                'message' => "Database error: " . $e->getMessage()
            ];
        }
        
    } catch (Exception $e) {
        $tests[] = [
            'name' => 'System Test',
            'status' => 'error',
            'message' => "Error: " . $e->getMessage()
        ];
    }
    
    foreach ($tests as $test) {
        echo '<div class="test ' . $test['status'] . '">';
        echo '<h3>' . $test['name'] . '</h3>';
        echo '<p>' . $test['message'] . '</p>';
        echo '</div>';
    }
    ?>
    
    <div class="test">
        <h3>Summary</h3>
        <p>If all tests show PASS, the UUID system is working correctly.</p>
        <p><strong>Note:</strong> You may need to recreate your database with the new schema for full functionality.</p>
    </div>
</body>
</html> 