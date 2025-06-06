<?php
require_once 'db_connect.php';
require_once 'response_utils.php'; // Include the new response utility
require_once 'data_manager.php';   // Include the new DataManager
require_once 'validator_utils.php'; // Include the new Validator

// header('Content-Type: application/json'); // Will be handled by ApiResponse
$pdo = get_db_connection();
$dataManager = new DataManager($pdo); // Instantiate DataManager
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

// Helper function to resolve page alias
function resolvePageAlias($pdo, $pageData, $followAliases = true) {
    if (!$followAliases || !$pageData || empty($pageData['alias'])) {
        return $pageData;
    }
    
    try {
        // Follow the alias
        $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
        $stmt->execute([$pageData['alias']]);
        $aliasedPage = $stmt->fetch();
        
        if ($aliasedPage) {
            // Recursively resolve in case the aliased page also has an alias
            return resolvePageAlias($pdo, $aliasedPage, true);
        }
    } catch (PDOException $e) {
        error_log("Error resolving alias for page {$pageData['id']}: " . $e->getMessage());
    }
    
    // Return original page if alias resolution fails
    return $pageData;
}

if ($method === 'GET') {
    $followAliases = !isset($_GET['follow_aliases']) || $_GET['follow_aliases'] !== '0';
    $include_details = isset($_GET['include_details']) && $_GET['include_details'] === '1';
    // Default include_internal to false if not provided. This will be used for page props, notes, and note props.
    $include_internal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
    
    if (isset($_GET['id'])) {
        $validationRules = ['id' => 'required|isPositiveInteger'];
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid page ID.', 400, $errors);
            exit;
        }
        $pageId = (int)$_GET['id']; // Validated
        
        // If include_details is true, fetch page with notes and properties
        if ($include_details) {
            $pageData = $dataManager->getPageWithNotes($pageId, $include_internal);
        } else {
            // Otherwise, just fetch page details (including its direct properties)
            $pageData = $dataManager->getPageDetailsById($pageId, $include_internal);
        }

        if ($pageData) {
            // Alias resolution should ideally be part of DataManager or handled carefully here.
            // For now, let's assume getPageDetailsById or getPageWithNotes returns the primary page data.
            // If $pageData is the full structure from getPageWithNotes, alias is on $pageData['page'].
            // If $pageData is from getPageDetailsById, alias is on $pageData.
            $pageToResolve = $include_details ? $pageData['page'] : $pageData;
            
            if ($followAliases && !empty($pageToResolve['alias'])) {
                 // If following aliases and an alias exists, we need to fetch the aliased page.
                 // This might mean another call to DataManager for the aliased page name/ID.
                 // This part needs careful consideration on how alias resolution integrates with DataManager.
                 // For simplicity, let's resolve it here based on the fetched page's alias.
                $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                $stmt->execute([$pageToResolve['alias']]);
                $aliasedPageInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($aliasedPageInfo) {
                    // If alias found, fetch details for the aliased page instead
                    if ($include_details) {
                        $pageData = $dataManager->getPageWithNotes($aliasedPageInfo['id'], $include_internal);
                    } else {
                        $pageData = $dataManager->getPageDetailsById($aliasedPageInfo['id'], $include_internal);
                    }
                }
                // If alias not found, $pageData remains the original page
            }
            ApiResponse::success($pageData);
        } else {
            ApiResponse::error('Page not found', 404);
        }
    } elseif (isset($_GET['name'])) {
        // Get page by name
        // $_GET['name'] is already a string, isNotEmpty can be used if it cannot be empty.
        // For looking up a page by name, an empty name is likely an invalid request.
        $validationRules = ['name' => 'required|isNotEmpty'];
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid page name.', 400, $errors);
            exit;
        }
        $pageName = Validator::sanitizeString($_GET['name']); // Sanitized

        try {
            // First, try to fetch the page by name. DataManager should handle this.
            // Let's assume getPageDetailsByName combines fetching page and its properties.
            // If DataManager doesn't have this, we can construct it.
            // For now, we manually query and then use DataManager for properties.
            $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$pageName]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($page) {
                // Page exists. Now, fetch its properties.
                $page['properties'] = $dataManager->getPageProperties($page['id'], $include_internal);
                
                // Handle alias resolution
                if ($followAliases && !empty($page['alias'])) {
                    $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                    $stmt->execute([$page['alias']]);
                    $aliasedPageInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($aliasedPageInfo) {
                        $aliasedPageInfo['properties'] = $dataManager->getPageProperties($aliasedPageInfo['id'], $include_internal);
                        $page = $aliasedPageInfo; // Replace original page with aliased one
                    }
                }
                ApiResponse::success($page);
            } else {
                // Page not found. Check if it's a date-based name for auto-creation.
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageName)) {
                    $pdo->beginTransaction();
                    
                    // Create the page
                    $insert_stmt = $pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)");
                    $insert_stmt->execute([$pageName]);
                    $pageId = $pdo->lastInsertId();
                    
                    // Add the 'type: journal' property
                    addJournalProperty($pdo, $pageId);
                    
                    $pdo->commit();
                    
                    // Fetch the complete new page details
                    $newPage = $dataManager->getPageDetailsById($pageId, $include_internal);
                    ApiResponse::success($newPage);
                } else {
                    // Not a date-based name and page doesn't exist.
                    ApiResponse::error('Page not found', 404);
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Handle unique constraint violation, which indicates a race condition.
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                // The page was likely created by another process between our SELECT and INSERT.
                // We can try fetching it again.
                $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                $stmt->execute([$pageName]);
                $page = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($page) {
                    $page['properties'] = $dataManager->getPageProperties($page['id'], $include_internal);
                    ApiResponse::success($page);
                } else {
                    // This case is unlikely but possible if the other process rolled back.
                    ApiResponse::error('Failed to resolve page creation race condition.', 500, ['details' => $e->getMessage()]);
                }
            } else {
                ApiResponse::error('Database error.', 500, ['details' => $e->getMessage()]);
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
        $pages = $stmt->fetchAll(); // These are the basic page objects

        // Apply alias resolution FIRST if requested
        // This block for "get all pages" needs significant refactoring if DataManager is to be used per page.
        // DataManager is designed for single page/note lookups or notes for a single page.
        // A "get all pages with details" would be very inefficient if calling DataManager::getPageWithNotes for each page.
        // For now, this existing logic for "get all pages" will remain largely unchanged,
        // as DataManager doesn't have a bulk "getAllPagesWithDetails" method.
        // The `formatProperties` helper can be removed from here if DataManager's formatting is used consistently.

        // Keep existing logic for fetching all pages
        if ($excludeJournal) {
            $stmt = $pdo->query("
                SELECT id, name, alias, updated_at 
                FROM Pages 
                WHERE NOT (name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' OR LOWER(name) = 'journal')
                ORDER BY updated_at DESC, name ASC
            ");
        } else {
            $stmt = $pdo->query("SELECT id, name, alias, updated_at FROM Pages ORDER BY updated_at DESC, name ASC");
        }
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($followAliases) {
             $resolvedPages = [];
             $seenIds = [];
             foreach ($pages as $page) {
                 $resolvedPage = resolvePageAlias($pdo, $page, true); // Resolve alias
                 if ($resolvedPage && !in_array($resolvedPage['id'], $seenIds)) {
                     $resolvedPages[] = $resolvedPage;
                     $seenIds[] = $resolvedPage['id'];
                 } elseif (!$resolvedPage && !in_array($page['id'], $seenIds)) { 
                     // If alias resolution led to null (e.g. alias points to non-existent page), keep original if not seen
                     $resolvedPages[] = $page;
                     $seenIds[] = $page['id'];
                 }
             }
             $pages = $resolvedPages;
        }

        if ($include_details) {
            $detailedPages = [];
            foreach ($pages as $page) {
                // Use DataManager to get details for each page
                // This could be inefficient for many pages.
                $pageDetail = $dataManager->getPageWithNotes($page['id'], $include_internal);
                if ($pageDetail) {
                    $detailedPages[] = $pageDetail;
                }
            }
            $pages = $detailedPages;
        }
        ApiResponse::success($pages);
    }
} elseif ($method === 'POST') {
    $validationRules = [
        'name' => 'required|isNotEmpty',
        'alias' => 'optional' // Alias can be empty or null, just needs to be a string if provided
    ];
    $errors = Validator::validate($input, $validationRules);
    if (!empty($errors)) {
        ApiResponse::error('Invalid input for creating page.', 400, $errors);
        exit;
    }

    $name = Validator::sanitizeString($input['name']); // Validated and sanitized
    $alias = isset($input['alias']) ? Validator::sanitizeString($input['alias']) : null;
    
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
            ApiResponse::success($existing_page);
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
        ApiResponse::success($newPage);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        ApiResponse::error('Failed to create page: ' . $e->getMessage(), 500);
        exit;
    }
} elseif ($method === 'PUT') {
    $validationRulesGET = ['id' => 'required|isPositiveInteger'];
    $errorsGET = Validator::validate($_GET, $validationRulesGET);
    if (!empty($errorsGET)) {
        ApiResponse::error('Invalid page ID in URL.', 400, $errorsGET);
        exit;
    }
    $pageId = (int)$_GET['id']; // Validated

    // For PUT, at least one of name or alias must be provided.
    // This specific logic is harder to express in the generic Validator::validate directly.
    if (!isset($input['name']) && !array_key_exists('alias', $input)) {
         ApiResponse::error('Either name or alias must be provided for update.', 400);
         exit;
    }

    $validationRulesPUT = [
        'name' => 'optional|isNotEmpty', // Name cannot be empty if provided
        'alias' => 'optional' // Alias can be empty string if provided
    ];
    $errorsPUT = Validator::validate($input, $validationRulesPUT);
     if (!empty($errorsPUT)) {
        ApiResponse::error('Invalid input for updating page.', 400, $errorsPUT);
        exit;
    }

    $fields_to_update = [];
    $params = [];
    
    if (isset($input['name'])) {
        $name = Validator::sanitizeString($input['name']); // Validated and sanitized
        $fields_to_update[] = "name = ?";
        $params[] = $name;
    }
    
    if (array_key_exists('alias', $input)) { // array_key_exists allows for empty string or null for alias
        $alias = $input['alias'] !== null ? Validator::sanitizeString($input['alias']) : null;
        $fields_to_update[] = "alias = ?";
        $params[] = $alias; // Use the potentially null alias
    }
    
    // This check should be redundant if we ensure at least one field is passed earlier.
    // However, keeping it as a safeguard.
    if (empty($fields_to_update)) {
        ApiResponse::error('No valid fields to update provided (name or alias).', 400);
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
            ApiResponse::error('Page not found', 404);
            exit;
        }
        
        // If name is being changed, check for uniqueness
        if (isset($input['name']) && $input['name'] !== $current_page['name']) {
            $stmt_unique = $pdo->prepare("SELECT id FROM Pages WHERE LOWER(name) = LOWER(?) AND id != ?");
            $stmt_unique->execute([$name, $page_id]);
            if ($stmt_unique->fetch()) {
                $pdo->rollBack();
                ApiResponse::error('Page name already exists', 409);
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
        ApiResponse::success($updated_page);
    } catch (PDOException $e) {
        $pdo->rollBack();
        ApiResponse::error('Failed to update page: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'DELETE') {
    $validationRules = ['id' => 'required|isPositiveInteger'];
    $errors = Validator::validate($_GET, $validationRules);
    if (!empty($errors)) {
        ApiResponse::error('Invalid page ID in URL.', 400, $errors);
        exit;
    }
    $pageId = (int)$_GET['id']; // Validated
    
    try {
        $pdo->beginTransaction();
        
        // Check if page exists
        $stmt_check = $pdo->prepare("SELECT id FROM Pages WHERE id = ? FOR UPDATE");
        $stmt_check->execute([$pageId]);
        if (!$stmt_check->fetch()) {
            $pdo->rollBack();
            ApiResponse::error('Page not found', 404);
            exit;
        }
        
        // Delete the page (cascade delete will handle notes and properties)
        $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
        $stmt->execute([$pageId]);
        
        $pdo->commit();
        ApiResponse::success(['deleted_page_id' => $pageId]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        ApiResponse::error('Failed to delete page: ' . $e->getMessage(), 500);
    }
} else {
    ApiResponse::error('Method Not Allowed', 405);
}