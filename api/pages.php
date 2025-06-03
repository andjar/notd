<?php
require_once 'db_connect.php';

header('Content-Type: application/json');
$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Helper function to check if a page name is a journal page
function isJournalPage($name) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) || strtolower($name) === 'journal';
}

// Helper function to add journal property
function addJournalProperty($pdo, $pageId) {
    $stmt = $pdo->prepare("
        INSERT INTO Properties (page_id, name, value)
        VALUES (?, 'type', 'journal')
    ");
    $stmt->execute([$pageId]);
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        // Get page by ID
        $stmt = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $page = $stmt->fetch();
        
        if ($page) {
            echo json_encode(['success' => true, 'data' => $page]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Page not found']);
        }
    } elseif (isset($_GET['name'])) {
        // Get page by name
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$_GET['name']]);
            $page = $stmt->fetch();

            if ($page) {
                // Handle special case for journal pages
                $today_journal_name = date('Y-m-d');
                if (strtolower($_GET['name']) === strtolower($today_journal_name) || 
                    (strtolower($_GET['name']) === 'journal' && !$page)) {
                    
                    // Double-check if page exists (race condition prevention)
                    $stmt_check = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                    $stmt_check->execute([$_GET['name']]);
                    $existing_page = $stmt_check->fetch();
                    
                    if (!$existing_page) {
                        $insert_stmt = $pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)");
                        $insert_stmt->execute([$_GET['name']]);
                        $page_id = $pdo->lastInsertId();
                        
                        // Add journal property if it's a journal page
                        if (isJournalPage($_GET['name'])) {
                            addJournalProperty($pdo, $page_id);
                        }
                        
                        $stmt_new = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
                        $stmt_new->execute([$page_id]);
                        $page = $stmt_new->fetch();
                    } else {
                        $page = $existing_page;
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'data' => $page]);
            } else {
                // Try to create journal page if name matches pattern
                $today_journal_name_pattern = '/^\d{4}-\d{2}-\d{2}$/';
                if (preg_match($today_journal_name_pattern, $_GET['name']) || strtolower($_GET['name']) === 'journal') {
                    $insert_stmt = $pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)");
                    $insert_stmt->execute([$_GET['name']]);
                    $page_id = $pdo->lastInsertId();
                    
                    // Add journal property since this is definitely a journal page
                    addJournalProperty($pdo, $page_id);
                    
                    $stmt_new = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
                    $stmt_new->execute([$page_id]);
                    $page = $stmt_new->fetch();
                    
                    $pdo->commit();
                    echo json_encode(['success' => true, 'data' => $page]);
                } else {
                    $pdo->commit();
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Page not found']);
                }
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                // If it failed due to UNIQUE, re-fetch it
                $stmt_refetch = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                $stmt_refetch->execute([$_GET['name']]);
                $page = $stmt_refetch->fetch();
                echo json_encode(['success' => true, 'data' => $page]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to create page: ' . $e->getMessage()]);
            }
        }
    } else {
        // Get all pages
        $excludeJournal = isset($_GET['exclude_journal']) && $_GET['exclude_journal'] === '1';
        
        if ($excludeJournal) {
            // Exclude journal pages (date pattern YYYY-MM-DD or name 'journal')
            // Using SQLite GLOB pattern matching instead of REGEXP
            $stmt = $pdo->query("
                SELECT id, name, alias, updated_at 
                FROM Pages 
                WHERE NOT (name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' OR LOWER(name) = 'journal')
                ORDER BY updated_at DESC, name ASC
            ");
        } else {
            $stmt = $pdo->query("SELECT id, name, alias, updated_at FROM Pages ORDER BY updated_at DESC, name ASC");
        }
        $pages = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $pages]);
    }
} elseif ($method === 'POST') {
    if (!isset($input['name']) || empty(trim($input['name']))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Page name is required']);
        exit;
    }
    
    $name = trim($input['name']);
    $alias = isset($input['alias']) ? trim($input['alias']) : null;
    
    try {
        $pdo->beginTransaction();
        
        // Check if page already exists
        $stmt_check = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
        $stmt_check->execute([$name]);
        $existing_page = $stmt_check->fetch();

        if ($existing_page) {
            // If it's a journal page, ensure it has the journal property
            if (isJournalPage($name)) {
                $stmt_check_prop = $pdo->prepare("
                    SELECT 1 FROM Properties 
                    WHERE page_id = ? 
                    AND name = 'type' 
                    AND value = 'journal'
                ");
                $stmt_check_prop->execute([$existing_page['id']]);
                if (!$stmt_check_prop->fetch()) {
                    addJournalProperty($pdo, $existing_page['id']);
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'data' => $existing_page]);
            exit;
        }

        // Create new page
        $stmt = $pdo->prepare("INSERT INTO Pages (name, alias, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$name, $alias]);
        $page_id = $pdo->lastInsertId();
        
        // Add journal property if it's a journal page
        if (isJournalPage($name)) {
            addJournalProperty($pdo, $page_id);
        }
        
        $stmt_new = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
        $stmt_new->execute([$page_id]);
        $newPage = $stmt_new->fetch();
        
        $pdo->commit();
        echo json_encode(['success' => true, 'data' => $newPage]);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create page: ' . $e->getMessage()]);
        exit;
    }
} elseif ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Page ID is required']);
        exit;
    }
    
    $page_id = (int)$_GET['id'];
    $fields_to_update = [];
    $params = [];
    
    if (isset($input['name'])) {
        $name = trim($input['name']);
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Page name cannot be empty']);
            exit;
        }
        $fields_to_update[] = "name = ?";
        $params[] = $name;
    }
    
    if (array_key_exists('alias', $input)) {
        $alias = trim($input['alias']);
        $fields_to_update[] = "alias = ?";
        $params[] = $alias ?: null;
    }
    
    if (empty($fields_to_update)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No fields to update provided (name or alias)']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if page exists and get current name
        $stmt_check = $pdo->prepare("SELECT name FROM Pages WHERE id = ? FOR UPDATE");
        $stmt_check->execute([$page_id]);
        $current_page = $stmt_check->fetch();
        
        if (!$current_page) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Page not found']);
            exit;
        }
        
        // If name is being changed, check for uniqueness
        if (isset($input['name']) && $input['name'] !== $current_page['name']) {
            $stmt_unique = $pdo->prepare("SELECT id FROM Pages WHERE LOWER(name) = LOWER(?) AND id != ?");
            $stmt_unique->execute([$name, $page_id]);
            if ($stmt_unique->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Page name already exists']);
                exit;
            }
        }
        
        $fields_to_update[] = "updated_at = CURRENT_TIMESTAMP";
        $sql = "UPDATE Pages SET " . implode(', ', $fields_to_update) . " WHERE id = ?";
        $params[] = $page_id;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $stmt_updated = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
        $stmt_updated->execute([$page_id]);
        $updated_page = $stmt_updated->fetch();

        // After successful rename, check if the new name makes it a journal page
        if ($updated_page) {
            $newName = $updated_page['name'];
            if (isJournalPage($newName)) {
                $stmt_check_prop = $pdo->prepare("SELECT 1 FROM Properties WHERE page_id = ? AND name = 'type' AND value = 'journal'");
                $stmt_check_prop->execute([$page_id]);
                if (!$stmt_check_prop->fetch()) {
                    addJournalProperty($pdo, $page_id);
                }
            } else {
                // Optional: If it was a journal page and now it is not, remove the property
                // For now, we don't remove it automatically to prevent accidental data loss if renaming is temporary.
                // $stmt_remove_prop = $pdo->prepare("DELETE FROM Properties WHERE page_id = ? AND name = 'type' AND value = 'journal'");
                // $stmt_remove_prop->execute([$page_id]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'data' => $updated_page]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update page: ' . $e->getMessage()]);
    }
} elseif ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Page ID is required']);
        exit;
    }
    
    $page_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if page exists
        $stmt_check = $pdo->prepare("SELECT id FROM Pages WHERE id = ? FOR UPDATE");
        $stmt_check->execute([$page_id]);
        if (!$stmt_check->fetch()) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Page not found']);
            exit;
        }
        
        // Delete the page (cascade delete will handle notes and properties)
        $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
        $stmt->execute([$page_id]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'data' => ['deleted_page_id' => $page_id]]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete page: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
}