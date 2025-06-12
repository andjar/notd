<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/setup_db.php';

// Helper for creating a NOTE and its properties during setup
if (!function_exists('_create_note_and_index_properties')) {
    function _create_note_and_index_properties(PDO $pdo, int $page_id, string $content, int $order_index) {
        require_once __DIR__ . '/property_parser.php';
        $stmt_insert_note = $pdo->prepare("INSERT INTO Notes (page_id, content, order_index) VALUES (?, ?, ?)");
        $stmt_insert_note->execute([$page_id, $content, $order_index]);
        $noteId = $pdo->lastInsertId();
        if (!$noteId) throw new Exception("Failed to create note record for welcome note.");
        $propertyParser = new PropertyParser($pdo);
        $parsedProperties = $propertyParser->parsePropertiesFromContent($content);
        if (!empty($parsedProperties)) {
            $stmt_insert_prop = $pdo->prepare("INSERT INTO Properties (note_id, name, value, weight) VALUES (?, ?, ?, ?)");
            foreach ($parsedProperties as $prop) {
                $stmt_insert_prop->execute([$noteId, $prop['name'], (string)$prop['value'], $prop['weight']]);
            }
        }
    }
}

// --- NEW HELPER ---
// Helper for creating a PAGE and its properties during setup
if (!function_exists('_create_page_and_index_properties')) {
    function _create_page_and_index_properties(PDO $pdo, string $name, ?string $content = null) {
        require_once __DIR__ . '/property_parser.php';
        $stmt_create_page = $pdo->prepare("INSERT INTO Pages (name, content) VALUES (?, ?)");
        $stmt_create_page->execute([$name, $content]);
        $pageId = $pdo->lastInsertId();
        if (!$pageId) throw new Exception("Failed to create page record for '$name'.");

        if ($content) {
            $propertyParser = new PropertyParser($pdo);
            $parsedProperties = $propertyParser->parsePropertiesFromContent($content);
            if (!empty($parsedProperties)) {
                $stmt_insert_prop = $pdo->prepare("INSERT INTO Properties (page_id, name, value, weight) VALUES (?, ?, ?, ?)");
                foreach ($parsedProperties as $prop) {
                    $stmt_insert_prop->execute([$pageId, $prop['name'], (string)$prop['value'], $prop['weight']]);
                }
            }
        }
        return $pageId;
    }
}


if (!function_exists('log_db_error')) {
    function log_db_error($message, $context = []) {
        error_log(date('Y-m-d H:i:s') . " [DB] " . $message . " " . json_encode($context));
    }
}

function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $db_path = DB_PATH;
        $db_dir = dirname($db_path);
        if (!is_dir($db_dir) && !mkdir($db_dir, 0777, true)) {
            throw new Exception("Failed to create database directory: $db_dir");
        }
        
        $pdo = new PDO('sqlite:' . $db_path, null, null, ['ATTR_ERRMODE' => PDO::ERRMODE_EXCEPTION, 'ATTR_DEFAULT_FETCH_MODE' => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'");
        if ($stmt->fetch() === false) {
            $lock_file = $db_path . '.setup.lock';
            $lock_fp = fopen($lock_file, 'w+');
            if ($lock_fp && flock($lock_fp, LOCK_EX)) {
                try {
                    if ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'")->fetch() === false) {
                        log_db_error("Running database setup...");
                        run_database_setup($pdo);
                        
                        $welcome_notes_path = __DIR__ . '/../assets/template/page/welcome_notes.json';
                        if (file_exists($welcome_notes_path)) {
                            log_db_error("Adding welcome notes...");
                            $pdo->beginTransaction();
                            try {
                                // --- THIS IS THE FIX ---
                                // Create page for today WITH content, using the new helper
                                $todays_page_name = date('Y-m-d');
                                $page_content = "{type::journal}";
                                $page_id = _create_page_and_index_properties($pdo, $todays_page_name, $page_content);
                                
                                // Add welcome notes from JSON to this new page
                                $notes_json = file_get_contents($welcome_notes_path);
                                $notes_to_insert = json_decode($notes_json, true);
                                if (is_array($notes_to_insert)) {
                                    $order_index = 1;
                                    foreach ($notes_to_insert as $note_content) {
                                        _create_note_and_index_properties($pdo, $page_id, $note_content, $order_index++);
                                    }
                                }
                                $pdo->commit();
                                log_db_error("Welcome notes added successfully.");
                            } catch (Exception $e) {
                                $pdo->rollBack();
                                log_db_error("Failed to add welcome notes: " . $e->getMessage());
                                throw $e;
                            }
                        }
                    }
                } finally {
                    flock($lock_fp, LOCK_UN);
                    fclose($lock_fp);
                    @unlink($lock_file);
                }
            }
        }
        return $pdo;
    } catch (Exception $e) {
        log_db_error("CRITICAL DATABASE SETUP FAILED: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'A critical error occurred during database setup.', 'details' => $e->getMessage()]);
        exit;
    }
}