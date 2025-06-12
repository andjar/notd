<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php'; // Include the new response utility
require_once __DIR__ . '/../data_manager.php';   // Include the new DataManager
require_once __DIR__ . '/../validator_utils.php'; // Include the new Validator
// Required for property indexing
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/properties.php';


// header('Content-Type: application/json'); // Will be handled by ApiResponse
$pdo = get_db_connection();
$dataManager = new DataManager($pdo); // Instantiate DataManager
$method = $_SERVER['REQUEST_METHOD'];
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// New "Smart Property Indexer" for Pages
if (!function_exists('_indexPagePropertiesFromContent')) {
    function _indexPagePropertiesFromContent($pdo, $pageId, $content) {
        // 1. Clear existing 'replaceable' properties for the page.
        $deleteSql = "DELETE FROM Properties WHERE page_id = ? AND weight < 4";
        $stmtDelete = $pdo->prepare($deleteSql);
        $stmtDelete->execute([$pageId]);

        // 2. Parse new properties from content.
        $propertyParser = new PropertyParser($pdo);
        $parsedProperties = $propertyParser->parsePropertiesFromContent($content);

        // 3. Save all parsed properties.
        foreach ($parsedProperties as $prop) {
            $name = $prop['name'];
            $value = (string)$prop['value'];
            $isInternal = determinePropertyInternalStatus($name, $value);
            
            _updateOrAddPropertyAndDispatchTriggers(
                $pdo,
                'page',
                $pageId,
                $name,
                $value,
                $isInternal,
                false
            );
        }
    }
}

if (!class_exists('PageManager')) {
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
            ON CONFLICT(page_id, name) DO UPDATE SET value = excluded.value
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
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if ($method === 'POST' && isset($input['_method'])) {
            switch (strtoupper($input['_method'])) {
                case 'PUT':
                    $this->handlePostUpdate($input);
                    break;
                case 'DELETE':
                    $this->handlePostDelete($input);
                    break;
                default:
                    ApiResponse::error('Invalid _method specified', 400);
            }
            return;
        }

        switch ($method) {
            case 'GET':
                $this->handleGetRequest();
                break;
            case 'POST':
                $this->handlePostRequest($input);
                break;
            default:
                ApiResponse::error('Method Not Allowed', 405);
        }
    }

    private function handleGetRequest() {
        // ... [GET LOGIC REMAINS UNCHANGED] ...
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
        $pageId = (int)$_GET['id'];
        $pageData = $include_details ? 
            $this->dataManager->getPageWithNotes($pageId, $include_internal) :
            $this->dataManager->getPageDetailsById($pageId, $include_internal);
        if (!$pageData) ApiResponse::error('Page not found', 404);

        $pageToResolve = $include_details ? $pageData['page'] : $pageData;
        if ($followAliases && !empty($pageToResolve['alias'])) {
            $stmt = $this->pdo->prepare("SELECT id FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$pageToResolve['alias']]);
            $aliasedPageInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($aliasedPageInfo) {
                $pageData = $include_details ?
                    $this->dataManager->getPageWithNotes($aliasedPageInfo['id'], $include_internal) :
                    $this->dataManager->getPageDetailsById($aliasedPageInfo['id'], $include_internal);
            }
        }
        ApiResponse::success($pageData);
    }

    private function handleGetByName($followAliases, $include_details, $include_internal) {
        $pageName = Validator::sanitizeString($_GET['name']);
        $page = $this->dataManager->getPageDetailsByName($pageName, $include_internal);

        if ($page) {
            if ($followAliases && !empty($page['alias'])) {
                $aliasedPage = $this->dataManager->getPageDetailsByName($page['alias'], $include_internal);
                if ($aliasedPage) $page = $aliasedPage;
            }
            ApiResponse::success($page);
        } else {
            // Auto-create page if not found by name
            $this->handlePostRequest(['name' => $pageName, 'content' => '']);
        }
    }

    private function handleGetAll($followAliases, $include_details, $include_internal) {
        $excludeJournal = isset($_GET['exclude_journal']) && $_GET['exclude_journal'] === '1';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM Pages" . ($excludeJournal ? " WHERE NOT (name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' OR LOWER(name) = 'journal')" : "");
        $totalCount = $this->pdo->query($countSql)->fetchColumn();

        $sql = "SELECT * FROM Pages" . ($excludeJournal ? " WHERE NOT (name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' OR LOWER(name) = 'journal')" : "") . " ORDER BY updated_at DESC, name ASC LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$perPage, $offset]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Post-processing for aliases, details, and properties
        $processedPages = array_map(function ($p) use ($followAliases, $include_details, $include_internal) {
            if ($followAliases && !empty($p['alias'])) {
                $resolved = $this->dataManager->getPageDetailsByName($p['alias'], $include_internal);
                if ($resolved) $p = $resolved;
            }
            if ($include_details) {
                return $this->dataManager->getPageWithNotes($p['id'], $include_internal);
            }
            $p['properties'] = $this->dataManager->getPageProperties($p['id'], $include_internal);
            return $p;
        }, $pages);

        $totalPages = ceil($totalCount / $perPage);
        ApiResponse::success([
            'data' => $processedPages,
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total_items' => (int)$totalCount, 'total_pages' => $totalPages]
        ]);
    }

    private function handlePostRequest($input) {
        $validationRules = ['name' => 'required|isNotEmpty', 'alias' => 'optional', 'content' => 'optional|isString'];
        $errors = Validator::validate($input, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid input for creating page.', 400, ['validation_errors' => $errors]);
        }

        $name = Validator::sanitizeString($input['name']);
        $alias = isset($input['alias']) ? Validator::sanitizeString($input['alias']) : null;
        $content = $input['content'] ?? null;
        
        try {
            $this->pdo->beginTransaction();
            
            $stmt_check = $this->pdo->prepare("SELECT id FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt_check->execute([$name]);
            if ($existing_page = $stmt_check->fetch()) {
                $this->pdo->commit();
                $pageData = $this->dataManager->getPageDetailsById($existing_page['id'], true);
                ApiResponse::success($pageData);
                return;
            }

            $stmt = $this->pdo->prepare("INSERT INTO Pages (name, alias, content, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$name, $alias, $content]);
            $page_id = $this->pdo->lastInsertId();

            if ($this->isJournalPage($name)) {
                $this->addJournalProperty($page_id);
            }
            
            if ($content) {
                _indexPagePropertiesFromContent($this->pdo, $page_id, $content);
            }

            $this->pdo->commit();
            
            $newPage = $this->dataManager->getPageDetailsById($page_id, true);
            ApiResponse::success($newPage, 201);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            ApiResponse::error('Failed to create page: ' . $e->getMessage(), 500);
        }
    }

    private function handlePostUpdate($input) {
        $pageId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($input['id']) ? (int)$input['id'] : null);
        if (!$pageId) ApiResponse::error('Page ID is required for update', 400);

        unset($input['_method'], $input['id']);
        $validationRules = ['name' => 'optional|isNotEmpty', 'alias' => 'optional', 'content' => 'optional|isString'];
        $errors = Validator::validate($input, $validationRules);
        if (!empty($errors)) ApiResponse::error('Invalid input for updating page.', 400, $errors);

        try {
            $this->pdo->beginTransaction();
            
            $stmt_check = $this->pdo->prepare("SELECT name FROM Pages WHERE id = ?");
            $stmt_check->execute([$pageId]);
            if (!$stmt_check->fetch()) {
                $this->pdo->rollBack();
                ApiResponse::error('Page not found', 404);
                return;
            }
            
            $fields_to_update = [];
            $params = [];
            if (isset($input['name'])) {
                $fields_to_update[] = "name = ?";
                $params[] = Validator::sanitizeString($input['name']);
            }
            if (array_key_exists('alias', $input)) {
                $fields_to_update[] = "alias = ?";
                $params[] = $input['alias'] !== null ? Validator::sanitizeString($input['alias']) : null;
            }
            if (array_key_exists('content', $input)) {
                $fields_to_update[] = "content = ?";
                $params[] = $input['content'];
            }

            if (empty($fields_to_update)) {
                $this->pdo->rollBack();
                ApiResponse::error('No valid fields to update provided.', 400);
                return;
            }

            $fields_to_update[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE Pages SET " . implode(', ', $fields_to_update) . " WHERE id = ?";
            $params[] = $pageId;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if (array_key_exists('content', $input)) {
                _indexPagePropertiesFromContent($this->pdo, $pageId, $input['content']);
            }
            
            $this->pdo->commit();
            
            $updated_page = $this->dataManager->getPageDetailsById($pageId, true);
            ApiResponse::success($updated_page);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            ApiResponse::error('Failed to update page: ' . $e->getMessage(), 500);
        }
    }

    private function handlePostDelete($input) {
        $pageId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($input['id']) ? (int)$input['id'] : null);
        if (!$pageId) ApiResponse::error('Page ID is required for deletion', 400);

        try {
            $this->pdo->beginTransaction();
            $stmt_check = $this->pdo->prepare("SELECT id FROM Pages WHERE id = ?");
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
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            ApiResponse::error('Failed to delete page: ' . $e->getMessage(), 500);
        }
    }
}
}

$pageManager = new PageManager($pdo);
$pageManager->handleRequest();