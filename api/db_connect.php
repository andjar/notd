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
                            
                            log_db_error("Loading initial page template...");
                            $template_path = __DIR__ . '/../assets/template/page/notd_setup.php';
                            if (file_exists($template_path)) {
                                $template_content = file_get_contents($template_path);
                                $notes = json_decode($template_content, true);
                                if (is_array($notes)) {
                                    $stmt_insert = $pdo->prepare("INSERT INTO Notes (page_id, title, content, created_at) SELECT id, ?, ?, datetime('now') FROM Pages WHERE name = 'Journal'");
                                    foreach ($notes as $note) {
                                        $stmt_insert->execute([$note['title'], $note['content']]);
                                    }
                                }
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