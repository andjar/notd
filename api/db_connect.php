<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/setup_db.php'; // Make the setup function available globally

function log_db_error($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . " [DB] " . $message;
    if (!empty($context)) {
        $logMessage .= " Context: " . json_encode($context);
    }
    error_log($logMessage);
}

function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $max_retries = 5;
    $retry_delay = 200000; // 200ms
    $attempt = 0;
    
    while ($attempt < $max_retries) {
        try {
            $db_path = DB_PATH;
            $attempt++;
            
            $db_dir = dirname($db_path);
            if (!is_dir($db_dir)) {
                if (!mkdir($db_dir, 0777, true)) {
                    throw new Exception("Failed to create database directory: $db_dir");
                }
            }
            
            $pdo = new PDO('sqlite:' . $db_path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
            
            $pdo->exec('PRAGMA busy_timeout = 5000;');
            $pdo->exec('PRAGMA foreign_keys = ON;');
            $pdo->exec('PRAGMA journal_mode = WAL;');
            $pdo->exec('PRAGMA synchronous = NORMAL;');

            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'");
            $setup_needed = ($stmt->fetch(PDO::FETCH_ASSOC) === false);

            if ($setup_needed) {
                $lock_file = $db_path . '.setup.lock';
                $lock_fp = fopen($lock_file, 'w+');

                if ($lock_fp && flock($lock_fp, LOCK_EX)) {
                    try {
                        // Re-check inside the lock to prevent race conditions
                        $stmt_check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'");
                        if ($stmt_check->fetch(PDO::FETCH_ASSOC) === false) {
                            log_db_error("Running setup script...");
                            run_database_setup($pdo); // Call the setup function
                            
                            // Load welcome notes if it's a fresh setup
                            log_db_error("Checking for welcome notes...");
                            $welcome_notes_path = __DIR__ . '/../assets/template/page/welcome_notes.json';
                            if (file_exists($welcome_notes_path)) {
                                // Create a page with today's date for welcome notes.
                                $todays_date_page_name = date('Y-m-d');

                                // We can ensure it's there by trying to fetch it.
                                $stmt_check_page = $pdo->prepare("SELECT id FROM Pages WHERE name = ?");
                                $stmt_check_page->execute([$todays_date_page_name]);
                                $page = $stmt_check_page->fetch(PDO::FETCH_ASSOC);
                                $page_id = null;

                                if ($page) {
                                    $page_id = $page['id'];
                                } else {
                                    // If a page for today doesn't exist, create it.
                                    try {
                                        $stmt_create_page = $pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)");
                                        $stmt_create_page->execute([$todays_date_page_name]);
                                        $page_id = $pdo->lastInsertId();
                                        if ($page_id) {
                                            // Add journal property
                                            $stmt_add_prop = $pdo->prepare("INSERT INTO Properties (page_id, name, value) VALUES (?, 'type', 'journal')");
                                            $stmt_add_prop->execute([$page_id]);
                                            log_db_error("Created page '" . $todays_date_page_name . "' with ID: " . $page_id . " for welcome notes.");
                                        }
                                    } catch (PDOException $e) {
                                        log_db_error("Could not create page '" . $todays_date_page_name . "' for welcome notes: " . $e->getMessage());
                                    }
                                }

                                if ($page_id) {
                                    // Check if welcome notes have already been added to this page to prevent duplicates
                                    $stmt_check_welcome_tag = $pdo->prepare("SELECT 1 FROM Properties WHERE page_id = ? AND name = 'welcome_notes_added' AND value = 'true'");
                                    $stmt_check_welcome_tag->execute([$page_id]);
                                    if ($stmt_check_welcome_tag->fetch(PDO::FETCH_ASSOC)) {
                                        log_db_error("Welcome notes already added to " . $todays_date_page_name . " page. Skipping.");
                                    } else {
                                        log_db_error("Loading welcome notes from JSON...");
                                        $notes_json = file_get_contents($welcome_notes_path);
                                        $notes_to_insert = json_decode($notes_json, true); // This will now be an array of strings

                                        if (is_array($notes_to_insert)) {
                                            // Note: No 'title' column in the INSERT statement anymore
                                            $stmt_insert_note = $pdo->prepare(
                                                "INSERT INTO Notes (page_id, content, order_index, created_at, updated_at) 
                                                 VALUES (?, ?, ?, datetime('now'), datetime('now'))"
                                            );
                                            $order_index = 1; 
                                            $stmt_max_order = $pdo->prepare("SELECT MAX(order_index) as max_order FROM Notes WHERE page_id = ?");
                                            $stmt_max_order->execute([$page_id]);
                                            $max_order_result = $stmt_max_order->fetch(PDO::FETCH_ASSOC);
                                            if ($max_order_result && $max_order_result['max_order'] !== null) {
                                                $order_index = $max_order_result['max_order'] + 1;
                                            }

                                            foreach ($notes_to_insert as $note_content) { // $note_content is now a string
                                                if (is_string($note_content) && !empty(trim($note_content))) { // Check if it's a non-empty string
                                                    $stmt_insert_note->execute([
                                                        $page_id,
                                                        $note_content, // Use the string directly as content
                                                        $order_index
                                                    ]);
                                                    $order_index++;
                                                    // Adjust logging if desired, e.g., log a snippet or just "Inserted welcome note"
                                                    $log_content_snippet = substr($note_content, 0, 50) . (strlen($note_content) > 50 ? "..." : "");
                                                    log_db_error("Inserted welcome note starting with: " . $log_content_snippet);
                                                }
                                            }
                                            // Add a property to mark that welcome notes have been added
                                            $stmt_mark_added = $pdo->prepare("INSERT INTO Properties (page_id, name, value) VALUES (?, 'welcome_notes_added', 'true')");
                                            $stmt_mark_added->execute([$page_id]);
                                            log_db_error("Finished adding welcome notes to " . $todays_date_page_name . " page.");
                                        } else {
                                            log_db_error("Failed to decode welcome notes JSON or it's not an array.");
                                        }
                                    }
                                } else {
                                    log_db_error("Page with today's date '" . $todays_date_page_name . "' ID not found or created. Cannot add welcome notes.");
                                }
                            } else {
                                log_db_error("Welcome notes file not found at: " . $welcome_notes_path);
                            }
                        }
                    } finally {
                        flock($lock_fp, LOCK_UN);
                        fclose($lock_fp);
                        @unlink($lock_file);
                    }
                } else {
                    if ($lock_fp) fclose($lock_fp);
                    throw new PDOException("Could not acquire setup lock.", "HY000");
                }
            }
            
            return $pdo; // Return on success
            
        } catch (PDOException $e) {
            log_db_error("Database PDOException", ['attempt' => $attempt, 'error' => $e->getMessage()]);
            if ($attempt < $max_retries) {
                usleep($retry_delay);
                continue;
            }
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => 'Database connection failed.', 'details' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            log_db_error("Database connection Exception", ['error' => $e->getMessage()]);
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => 'A critical error occurred during database setup.', 'details' => $e->getMessage()]);
            exit;
        }
    }
    
    log_db_error("Failed to establish database connection after all attempts.");
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => 'Failed to connect to the database after multiple attempts.']);
    exit;
}