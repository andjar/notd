<?php
// FILE: api/db_connect.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/setup_db.php';

if (!function_exists('log_db_error')) {
    function log_db_error($message, $context = []) {
        $logMessage = date('Y-m-d H:i:s') . " [DB] " . $message;
        if (!empty($context)) {
            $logMessage .= " Context: " . json_encode($context);
        }
        error_log($logMessage);
    }
}

function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $db_path = DB_PATH;
    $db_dir = dirname($db_path);
    if (!is_dir($db_dir)) {
        if (!mkdir($db_dir, 0777, true)) {
            throw new Exception("Failed to create database directory: $db_dir");
        }
    }

    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'");
        $setup_needed = ($stmt->fetch() === false);

        if ($setup_needed) {
            log_db_error("Database appears empty. Running initial setup...");
            run_database_setup($pdo); // This runs the schema.sql
            
            // --- Welcome Notes Injection Logic ---
            $welcome_notes_path = __DIR__ . '/../assets/template/page/welcome_notes.json';
            if (file_exists($welcome_notes_path)) {
                $todays_page_name = date('Y-m-d');
                $page_id = null;

                // Check if today's page already exists.
                $stmt_check_page = $pdo->prepare("SELECT id FROM Pages WHERE name = ?");
                $stmt_check_page->execute([$todays_page_name]);
                $page = $stmt_check_page->fetch();

                if ($page) {
                    $page_id = $page['id'];
                } else {
                    // Page doesn't exist, create it.
                    log_db_error("Creating initial journal page for today: " . $todays_page_name);
                    $stmt_create_page = $pdo->prepare("INSERT INTO Pages (name) VALUES (?)");
                    $stmt_create_page->execute([$todays_page_name]);
                    $page_id = $pdo->lastInsertId();
                    // Add the 'journal' type property
                    $stmt_add_prop = $pdo->prepare("INSERT INTO Properties (page_id, name, value, colon_count) VALUES (?, 'type', 'journal', 2)");
                    $stmt_add_prop->execute([$page_id]);
                }

                if ($page_id) {
                    log_db_error("Loading welcome notes into page ID: " . $page_id);
                    $notes_json = file_get_contents($welcome_notes_path);
                    $notes_to_insert = json_decode($notes_json, true);

                    if (is_array($notes_to_insert)) {
                        $stmt_insert_note = $pdo->prepare("INSERT INTO Notes (page_id, content, order_index) VALUES (?, ?, ?)");
                        $order_index = 0;
                        foreach ($notes_to_insert as $note_content) {
                            if (is_string($note_content) && !empty(trim($note_content))) {
                                $stmt_insert_note->execute([$page_id, $note_content, $order_index]);
                                $order_index++;
                            }
                        }
                        log_db_error("Successfully inserted " . ($order_index) . " welcome notes.");
                    }
                }
            } else {
                log_db_error("Welcome notes template file not found. Skipping.");
            }
        }

        return $pdo;

    } catch (PDOException $e) {
        log_db_error("Database connection failed: " . $e->getMessage());
        // In a web context, you would handle this gracefully. For setup, exiting is okay.
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed.', 'details' => $e->getMessage()]);
        exit;
    }
}