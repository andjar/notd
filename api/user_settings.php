<?php
// Start output buffering
ob_start();

// Ensure the script is accessed via GET or POST
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!headers_sent()) {
        header('Content-Type: application/json'); // Set header even for method not allowed
        header('HTTP/1.1 405 Method Not Allowed');
    }
    echo json_encode(['error' => 'Method Not Allowed']);
    if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
    exit;
}

// Set content type for all valid responses
if (!headers_sent()) {
    header('Content-Type: application/json');
}

// --- START: Added getStandaloneDbConnection function ---
function getStandaloneDbConnection() {
    $db_path = __DIR__ . '/../db/notes.db';
    try {
        $db = new SQLite3($db_path);
        if (!$db) {
            throw new Exception('Failed to create SQLite3 object for path: ' . $db_path);
        }
        $db->busyTimeout(5000);
        return $db;
    } catch (Exception $e) {
        error_log("PHP Error in getStandaloneDbConnection: " . $e->getMessage() . " for DB path " . $db_path);
        if (ob_get_level() > 0) {
             ob_clean(); 
        }
        if (!headers_sent()) {
             header('HTTP/1.1 503 Service Unavailable'); 
        }
        echo json_encode(['error' => 'Database connection failed', 'detail' => $e->getMessage()]);
        if (ob_get_level() > 0 && ob_get_length() > 0) {
             ob_end_flush();
        } elseif (ob_get_level() > 0) {
             ob_end_clean();
        }
        exit;
    }
}
// --- END: Added getStandaloneDbConnection function ---

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['action'])) {
        echo json_encode(['error' => 'Action not specified for GET request']);
        if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
        exit;
    }
    $action = $_GET['action'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_REQUEST['action'])) { 
        echo json_encode(['error' => 'Action not specified for POST request']);
        if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
        exit;
    }
    $action = $_REQUEST['action'];
}

$db = null; 
try {
    $db = getStandaloneDbConnection();
} catch (Exception $e) {
    if (ob_get_level() > 0) ob_clean(); 
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    echo json_encode(['error' => 'Failed to connect to database (initialization main): ' . $e->getMessage()]);
    if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
    exit;
}

if ($action === 'get') {
    if (!isset($_GET['key'])) {
        echo json_encode(['error' => 'Key(s) not specified for get action']);
        if ($db) $db->close();
        if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
        exit;
    }
    $requested_keys = $_GET['key'];

    try {
        if (is_array($requested_keys)) {
            // Handle array of keys
            if (empty($requested_keys)) {
                echo json_encode(['success' => true, 'settings' => new stdClass()]); // Return empty object for empty key array
            } else {
                $placeholders = implode(',', array_fill(0, count($requested_keys), '?'));
                $stmt = $db->prepare("SELECT setting_key, setting_value FROM user_settings WHERE setting_key IN ($placeholders)");
                foreach ($requested_keys as $index => $k) {
                    $stmt->bindValue($index + 1, $k, SQLITE3_TEXT);
                }
                $result = $stmt->execute();
                $dbSettings = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $dbSettings[$row['setting_key']] = $row['setting_value'];
                }

                $outputSettings = [];
                foreach ($requested_keys as $requestedKey) {
                    if (isset($dbSettings[$requestedKey])) {
                        $outputSettings[$requestedKey] = $dbSettings[$requestedKey];
                    } else {
                        // Apply default value logic
                        $defaultValue = null;
                        $defaulted = false; // Flag to indicate if a default was applied
                        switch ($requestedKey) {
                            case 'toolbarVisible': $defaultValue = 'true'; $defaulted = true; break;
                            case 'leftSidebarCollapsed': $defaultValue = 'false'; $defaulted = true; break;
                            case 'rightSidebarCollapsed': $defaultValue = 'true'; $defaulted = true; break;
                            case 'customSQLQuery': $defaultValue = ''; $defaulted = true; break;
                            case 'queryExecutionFrequency': $defaultValue = 'manual'; $defaulted = true; break;
                        }
                        // For multi-key GET, only include if a value or default exists.
                        // The 'defaulted' flag isn't part of the multi-key response structure per requirements.
                        if ($defaultValue !== null) {
                             $outputSettings[$requestedKey] = $defaultValue;
                        }
                        // If a key has no DB value and no defined default, it's omitted from the response.
                    }
                }
                // Ensure empty $outputSettings becomes {} not []
                echo json_encode(['success' => true, 'settings' => empty($outputSettings) ? new stdClass() : $outputSettings]);
            }
        } else {
            // Handle single key (existing logic adapted)
            $single_key = (string)$requested_keys; // Cast to string just in case
            $stmt = $db->prepare('SELECT setting_value FROM user_settings WHERE setting_key = :key_str');
            $stmt->bindValue(':key_str', $single_key, SQLITE3_TEXT);
            $result = $stmt->execute();

            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                echo json_encode(['success' => true, 'key' => $single_key, 'value' => $row['setting_value']]);
            } else {
                // Key not found, return a default value
                $defaultValue = null;
                $defaulted = false;
                switch ($single_key) {
                    case 'toolbarVisible': $defaultValue = 'true'; $defaulted = true; break;
                    case 'leftSidebarCollapsed': $defaultValue = 'false'; $defaulted = true; break;
                    case 'rightSidebarCollapsed': $defaultValue = 'true'; $defaulted = true; break;
                    case 'customSQLQuery': $defaultValue = ''; $defaulted = true; break;
                    case 'queryExecutionFrequency': $defaultValue = 'manual'; $defaulted = true; break;
                }

                if ($defaultValue !== null) {
                    echo json_encode(['success' => true, 'key' => $single_key, 'value' => $defaultValue, 'defaulted' => $defaulted]);
                } else {
                    echo json_encode(['success' => false, 'key' => $single_key, 'message' => 'Setting not found and no default available']);
                }
            }
        }
    } catch (Exception $e) {
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
        }
        echo json_encode(['error' => 'Database error (get action): ' . $e->getMessage()]);
    }
    if ($db) $db->close();
    if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
    exit;

} elseif ($action === 'set') {
    // ... (SET action logic remains unchanged) ...
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (!headers_sent()) {
            header('HTTP/1.1 405 Method Not Allowed');
        }
        echo json_encode(['error' => 'Set action requires POST method']);
        if ($db) $db->close();
        if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['key']) || !isset($input['value'])) {
        echo json_encode(['error' => 'Key or value not specified for set action in JSON body']);
        if ($db) $db->close();
        if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
        exit;
    }
    $key = $input['key'];
    $value = $input['value'];

    try {
        $stmt = $db->prepare('INSERT OR REPLACE INTO user_settings (setting_key, setting_value) VALUES (:key, :value)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Setting saved']);
        } else {
            throw new Exception('Failed to save setting: ' . $db->lastErrorMsg());
        }
    } catch (Exception $e) {
        if (!headers_sent()) {
             header('HTTP/1.1 500 Internal Server Error');
        }
        echo json_encode(['error' => 'Database error (set action): ' . $e->getMessage()]);
    }
    if ($db) $db->close();
    if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
    exit;

} else {
    echo json_encode(['error' => 'Invalid action']);
    if ($db) $db->close();
    if (ob_get_level() > 0 && ob_get_length() > 0) ob_end_flush(); else if (ob_get_level() > 0) ob_end_clean();
    exit;
}

// Fallback: should have exited by now.
if (isset($db) && $db) {
    $db->close();
}
if (ob_get_level() > 0 && ob_get_length() > 0) {
    ob_end_flush();
} elseif (ob_get_level() > 0) {
    ob_end_clean();
}
// No closing ?> tag is recommended for files containing only PHP code.
