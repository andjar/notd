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

class PageManager {
    private $pdo;
    private $dataManager;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->dataManager = new DataManager($pdo);
    }

    public function isJournalPage($name) {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) || strtolower($name) === 'journal';
    }

    private function addJournalProperty($pageId) {
        $stmt = $this->pdo->prepare("
            INSERT INTO Properties (page_id, name, value)
            VALUES (?, 'type', 'journal')
        ");
        $stmt->execute([$pageId]);
    }

    private function resolvePageAlias($pageData, $followAliases = true) {
        if (!$followAliases || !$pageData || empty($pageData['alias'])) {
            return $pageData;
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$pageData['alias']]);
            $aliasedPage = $stmt->fetch();
            
            if ($aliasedPage) {
                return $this->resolvePageAlias($aliasedPage, true);
            }
        } catch (PDOException $e) {
            error_log("Error resolving alias for page {$pageData['id']}: " . $e->getMessage());
        }
        
        return $pageData;
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $input = json_decode(file_get_contents('php://input'), true);

        switch ($method) {
            case 'GET':
                $this->handleGetRequest();
                break;
            case 'POST':
                $this->handlePostRequest($input);
                break;
            case 'PUT':
                $this->handlePutRequest($input);
                break;
            case 'DELETE':
                $this->handleDeleteRequest();
                break;
            default:
                ApiResponse::error('Method Not Allowed', 405);
        }
    }

    private function handleGetRequest() {
        $followAliases = !isset($_GET['follow_aliases']) || $_GET['follow_aliases'] !== '0';
        $include_details = isset($_GET['include_details']) && $_GET['include_details'] === '1';
        $include_internal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);

        if (isset($_GET['id'])) {
            $this->handleGetById($followAliases, $include_details, $include_internal);
        } elseif (isset($_GET['name'])) {
            $this->handleGetByName($followAliases, $include_details, $include_internal);
        } else {
            $this->handleGetAll($followAliases, $include_details, $include_internal);
        }
    }

    private function handleGetById($followAliases, $include_details, $include_internal) {
        $validationRules = ['id' => 'required|isPositiveInteger'];
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid page ID.', 400, $errors);
            return;
        }
        
        $pageId = (int)$_GET['id'];
        $pageData = $include_details ? 
            $this->dataManager->getPageWithNotes($pageId, $include_internal) :
            $this->dataManager->getPageDetailsById($pageId, $include_internal);

        if ($pageData) {
            $pageToResolve = $include_details ? $pageData['page'] : $pageData;
            
            if ($followAliases && !empty($pageToResolve['alias'])) {
                $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                $stmt->execute([$pageToResolve['alias']]);
                $aliasedPageInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($aliasedPageInfo) {
                    $pageData = $include_details ?
                        $this->dataManager->getPageWithNotes($aliasedPageInfo['id'], $include_internal) :
                        $this->dataManager->getPageDetailsById($aliasedPageInfo['id'], $include_internal);
                }
            }
            ApiResponse::success($pageData);
        } else {
            ApiResponse::error('Page not found', 404);
        }
    }

    private function handleGetByName($followAliases, $include_details, $include_internal) {
        $validationRules = ['name' => 'required|isNotEmpty'];
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid page name.', 400, $errors);
            return;
        }

        $pageName = Validator::sanitizeString($_GET['name']);

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$pageName]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($page) {
                $page['properties'] = $this->dataManager->getPageProperties($page['id'], $include_internal);
                
                if ($followAliases && !empty($page['alias'])) {
                    $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                    $stmt->execute([$page['alias']]);
                    $aliasedPageInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($aliasedPageInfo) {
                        $aliasedPageInfo['properties'] = $this->dataManager->getPageProperties($aliasedPageInfo['id'], $include_internal);
                        $page = $aliasedPageInfo;
                    }
                }
                ApiResponse::success($page);
            } else {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageName)) {
                    $this->createJournalPage($pageName, $include_internal);
                } else {
                    ApiResponse::error('Page not found', 404);
                }
            }
        } catch (PDOException $e) {
            ApiResponse::error('Database error.', 500, ['details' => $e->getMessage()]);
        }
    }

    private function handleGetAll($followAliases, $include_details, $include_internal) {
        $excludeJournal = isset($_GET['exclude_journal']) && $_GET['exclude_journal'] === '1';
        
        $sql = $excludeJournal ?
            "SELECT id, name, alias, updated_at FROM Pages WHERE NOT (name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' OR LOWER(name) = 'journal') ORDER BY updated_at DESC, name ASC" :
            "SELECT id, name, alias, updated_at FROM Pages ORDER BY updated_at DESC, name ASC";
        
        $stmt = $this->pdo->query($sql);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($followAliases) {
            $resolvedPages = [];
            $seenIds = [];
            foreach ($pages as $page) {
                $resolvedPage = $this->resolvePageAlias($page, true);
                if ($resolvedPage && !in_array($resolvedPage['id'], $seenIds)) {
                    $resolvedPages[] = $resolvedPage;
                    $seenIds[] = $resolvedPage['id'];
                } elseif (!$resolvedPage && !in_array($page['id'], $seenIds)) {
                    $resolvedPages[] = $page;
                    $seenIds[] = $page['id'];
                }
            }
            $pages = $resolvedPages;
        }

        if ($include_details) {
            $detailedPages = [];
            foreach ($pages as $page) {
                $pageDetail = $this->dataManager->getPageWithNotes($page['id'], $include_internal);
                if ($pageDetail) {
                    $detailedPages[] = $pageDetail;
                }
            }
            $pages = $detailedPages;
        }
        
        ApiResponse::success($pages);
    }

    private function handlePostRequest($input) {
        $validationRules = [
            'name' => 'required|isNotEmpty',
            'alias' => 'optional'
        ];
        $errors = Validator::validate($input, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid input for creating page.', 400, $errors);
            return;
        }

        $name = Validator::sanitizeString($input['name']);
        $alias = isset($input['alias']) ? Validator::sanitizeString($input['alias']) : null;
        
        try {
            $this->pdo->beginTransaction();
            
            $stmt_check = $this->pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt_check->execute([$name]);
            $existing_page = $stmt_check->fetch();

            if ($existing_page) {
                if ($this->isJournalPage($name)) {
                    $stmt_check_prop = $this->pdo->prepare("
                        SELECT 1 FROM Properties 
                        WHERE page_id = ? 
                        AND name = 'type' 
                        AND value = 'journal'
                    ");
                    $stmt_check_prop->execute([$existing_page['id']]);
                    if (!$stmt_check_prop->fetch()) {
                        $this->addJournalProperty($existing_page['id']);
                    }
                }
                $this->pdo->commit();
                ApiResponse::success($existing_page);
                return;
            }

            $stmt = $this->pdo->prepare("INSERT INTO Pages (name, alias, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$name, $alias]);
            $page_id = $this->pdo->lastInsertId();
            
            if ($this->isJournalPage($name)) {
                $this->addJournalProperty($page_id);
            }
            
            $stmt_new = $this->pdo->prepare("SELECT * FROM Pages WHERE id = ?");
            $stmt_new->execute([$page_id]);
            $newPage = $stmt_new->fetch();
            
            $this->pdo->commit();
            ApiResponse::success($newPage);
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            ApiResponse::error('Failed to create page: ' . $e->getMessage(), 500);
        }
    }

    private function handlePutRequest($input) {
        $validationRulesGET = ['id' => 'required|isPositiveInteger'];
        $errorsGET = Validator::validate($_GET, $validationRulesGET);
        if (!empty($errorsGET)) {
            ApiResponse::error('Invalid page ID in URL.', 400, $errorsGET);
            return;
        }
        $pageId = (int)$_GET['id'];

        if (!isset($input['name']) && !array_key_exists('alias', $input)) {
            ApiResponse::error('Either name or alias must be provided for update.', 400);
            return;
        }

        $validationRulesPUT = [
            'name' => 'optional|isNotEmpty',
            'alias' => 'optional'
        ];
        $errorsPUT = Validator::validate($input, $validationRulesPUT);
        if (!empty($errorsPUT)) {
            ApiResponse::error('Invalid input for updating page.', 400, $errorsPUT);
            return;
        }

        $fields_to_update = [];
        $params = [];
        
        if (isset($input['name'])) {
            $name = Validator::sanitizeString($input['name']);
            $fields_to_update[] = "name = ?";
            $params[] = $name;
        }
        
        if (array_key_exists('alias', $input)) {
            $alias = $input['alias'] !== null ? Validator::sanitizeString($input['alias']) : null;
            $fields_to_update[] = "alias = ?";
            $params[] = $alias;
        }
        
        if (empty($fields_to_update)) {
            ApiResponse::error('No valid fields to update provided (name or alias).', 400);
            return;
        }
        
        try {
            $this->pdo->beginTransaction();
            
            $stmt_check = $this->pdo->prepare("SELECT name FROM Pages WHERE id = ? FOR UPDATE");
            $stmt_check->execute([$pageId]);
            $current_page = $stmt_check->fetch();
            
            if (!$current_page) {
                $this->pdo->rollBack();
                ApiResponse::error('Page not found', 404);
                return;
            }
            
            if (isset($input['name']) && $input['name'] !== $current_page['name']) {
                $stmt_unique = $this->pdo->prepare("SELECT id FROM Pages WHERE LOWER(name) = LOWER(?) AND id != ?");
                $stmt_unique->execute([$name, $pageId]);
                if ($stmt_unique->fetch()) {
                    $this->pdo->rollBack();
                    ApiResponse::error('Page name already exists', 409);
                    return;
                }
            }
            
            $fields_to_update[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE Pages SET " . implode(', ', $fields_to_update) . " WHERE id = ?";
            $params[] = $pageId;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $stmt_updated = $this->pdo->prepare("SELECT * FROM Pages WHERE id = ?");
            $stmt_updated->execute([$pageId]);
            $updated_page = $stmt_updated->fetch();

            if ($updated_page) {
                $newName = $updated_page['name'];
                if ($this->isJournalPage($newName)) {
                    $stmt_check_prop = $this->pdo->prepare("SELECT 1 FROM Properties WHERE page_id = ? AND name = 'type' AND value = 'journal'");
                    $stmt_check_prop->execute([$pageId]);
                    if (!$stmt_check_prop->fetch()) {
                        $this->addJournalProperty($pageId);
                    }
                }
            }
            
            $this->pdo->commit();
            ApiResponse::success($updated_page);
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            ApiResponse::error('Failed to update page: ' . $e->getMessage(), 500);
        }
    }

    private function handleDeleteRequest() {
        $validationRules = ['id' => 'required|isPositiveInteger'];
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid page ID in URL.', 400, $errors);
            return;
        }
        $pageId = (int)$_GET['id'];
        
        try {
            $this->pdo->beginTransaction();
            
            $stmt_check = $this->pdo->prepare("SELECT id FROM Pages WHERE id = ? FOR UPDATE");
            $stmt_check->execute([$pageId]);
            if (!$stmt_check->fetch()) {
                $this->pdo->rollBack();
                ApiResponse::error('Page not found', 404);
                return;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM Pages WHERE id = ?");
            $stmt->execute([$pageId]);
            
            $this->pdo->commit();
            ApiResponse::success(['deleted_page_id' => $pageId]);
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            ApiResponse::error('Failed to delete page: ' . $e->getMessage(), 500);
        }
    }

    private function createJournalPage($pageName, $include_internal) {
        try {
            $this->pdo->beginTransaction();
            
            $insert_stmt = $this->pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)");
            $insert_stmt->execute([$pageName]);
            $pageId = $this->pdo->lastInsertId();
            
            $this->addJournalProperty($pageId);
            
            $this->pdo->commit();
            
            $newPage = $this->dataManager->getPageDetailsById($pageId, $include_internal);
            ApiResponse::success($newPage);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
                $stmt->execute([$pageName]);
                $page = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($page) {
                    $page['properties'] = $this->dataManager->getPageProperties($page['id'], $include_internal);
                    ApiResponse::success($page);
                } else {
                    ApiResponse::error('Failed to resolve page creation race condition.', 500, ['details' => $e->getMessage()]);
                }
            } else {
                ApiResponse::error('Database error.', 500, ['details' => $e->getMessage()]);
            }
        }
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$pageManager = new PageManager($pdo);
$pageManager->handleRequest();