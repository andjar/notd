<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../validator_utils.php';
require_once __DIR__ . '/../property_parser.php';

$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// "Smart Property Indexer" for Pages - This is the central logic for syncing content to the Properties table
if (!function_exists('_indexPagePropertiesFromContent')) {
    function _indexPagePropertiesFromContent($pdo, $pageId, $content) {
        if (empty(trim($content))) {
            $deleteSql = "DELETE FROM Properties WHERE page_id = ? AND weight < 4";
            $stmtDelete = $pdo->prepare($deleteSql);
            $stmtDelete->execute([$pageId]);
            return;
        };
        $replaceableWeights = [];
        foreach (PROPERTY_WEIGHTS as $weight => $config) {
            if ($config['update_behavior'] === 'replace') {
                $replaceableWeights[] = (int)$weight;
            }
        }
        if (!empty($replaceableWeights)) {
            $placeholders = str_repeat('?,', count($replaceableWeights) - 1) . '?';
            $deleteSql = "DELETE FROM Properties WHERE page_id = ? AND weight IN ($placeholders)";
            $stmtDelete = $pdo->prepare($deleteSql);
            $stmtDelete->execute(array_merge([$pageId], $replaceableWeights));
        }
        $propertyParser = new PropertyParser($pdo);
        $parsedProperties = $propertyParser->parsePropertiesFromContent($content);
        if (!empty($parsedProperties)) {
            $insertSql = "INSERT INTO Properties (page_id, name, value, weight) VALUES (?, ?, ?, ?)";
            $stmtInsert = $pdo->prepare($insertSql);
            foreach ($parsedProperties as $prop) {
                $stmtInsert->execute([$pageId, $prop['name'], (string)$prop['value'], $prop['weight']]);
            }
        }
    }
}

if (!class_exists('PageManager')) {
    class PageManager {
        private $pdo;
        private $dataManager;

        public function __construct($pdo, $dataManager) {
            $this->pdo = $pdo;
            $this->dataManager = $dataManager;
        }

        public function handleRequest() {
            $method = $_SERVER['REQUEST_METHOD'];
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            if ($method === 'POST' && isset($input['_method'])) {
                switch (strtoupper($input['_method'])) {
                    case 'PUT': $this->handleUpdate($input); break;
                    case 'DELETE': $this->handleDelete($input); break;
                    default: ApiResponse::error('Invalid _method specified', 400);
                }
                return;
            }

            switch ($method) {
                case 'GET': $this->handleGet(); break;
                case 'POST': $this->handleCreate($input); break;
                default: ApiResponse::error('Method Not Allowed', 405);
            }
        }
        
        private function handleGet() {
            if (isset($_GET['id'])) {
                $pageId = (int)$_GET['id'];
                $page = $this->dataManager->getPageDetailsById($pageId, true);
                if ($page) ApiResponse::success($page);
                else ApiResponse::error('Page not found', 404);
            } else {
                $page = max(1, (int)($_GET['page'] ?? 1));
                $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
                $offset = ($page - 1) * $perPage;

                $countSql = "SELECT COUNT(*) FROM Pages WHERE active = 1";
                $totalCount = $this->pdo->query($countSql)->fetchColumn();
                
                $sql = "SELECT * FROM Pages WHERE active = 1 ORDER BY updated_at DESC, name ASC LIMIT ? OFFSET ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$perPage, $offset]);
                $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $pageIds = array_column($pages, 'id');
                if (!empty($pageIds)) {
                    $propertiesByPageId = $this->dataManager->getPagePropertiesForPageIds($pageIds);
                    foreach($pages as &$p) {
                        $p['properties'] = $propertiesByPageId[$p['id']] ?? [];
                    }
                }

                $totalPages = ceil($totalCount / $perPage);
                ApiResponse::success([
                    'data' => $pages,
                    'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total_items' => (int)$totalCount, 'total_pages' => $totalPages]
                ]);
            }
        }
        
                // This method handles new page creation
                private function handleCreate($input) {
                    $errors = Validator::validate($input, ['name' => 'required|isNotEmpty']);
                    if (!empty($errors)) ApiResponse::error('Validation failed', 400, ['details' => $errors]);
                    
                    $name = Validator::sanitizeString($input['name']);
                    // It explicitly looks for a 'content' field in the JSON payload.
                    $content = $input['content'] ?? null;
        
                    try {
                        $this->pdo->beginTransaction();
                        
                        $stmtCheck = $this->pdo->prepare("SELECT id FROM Pages WHERE LOWER(name) = LOWER(?)");
                        $stmtCheck->execute([$name]);
                        if ($existingPage = $stmtCheck->fetch()) {
                            $this->pdo->rollBack();
                            ApiResponse::error('Page with this name already exists', 409, ['id' => $existingPage['id']]);
                            return;
                        }
        
                        // The page is inserted into the database WITH its content.
                        $stmt = $this->pdo->prepare("INSERT INTO Pages (name, content) VALUES (?, ?)");
                        $stmt->execute([$name, $content]);
                        $pageId = $this->pdo->lastInsertId();
        
                        // If content was provided, the property indexer is immediately called.
                        // This processes the content and populates the Properties table in the same transaction.
                        if ($content) {
                            _indexPagePropertiesFromContent($this->pdo, $pageId, $content);
                        }
        
                        $this->pdo->commit();
                        
                        $newPage = $this->dataManager->getPageDetailsById($pageId, true);
                        ApiResponse::success($newPage, 201);
                    } catch (PDOException $e) {
                        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                        ApiResponse::error('Failed to create page: ' . $e->getMessage(), 500);
                    }
                }
        
        private function handleUpdate($input) {
            $pageId = $input['id'] ?? null;
            if (!$pageId) ApiResponse::error('Page ID is required for update', 400);

            try {
                $this->pdo->beginTransaction();

                $setClauses = []; $params = []; $contentWasUpdated = false;
                if (isset($input['name'])) { $setClauses[] = "name = ?"; $params[] = Validator::sanitizeString($input['name']); }
                if (array_key_exists('content', $input)) { $setClauses[] = "content = ?"; $params[] = $input['content']; $contentWasUpdated = true; }
                if (empty($setClauses)) { $this->pdo->rollBack(); ApiResponse::error('No valid fields to update', 400); return; }

                $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
                $sql = "UPDATE Pages SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $params[] = $pageId;
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);

                if ($contentWasUpdated) _indexPagePropertiesFromContent($this->pdo, $pageId, $input['content']);
                
                $this->pdo->commit();
                $updatedPage = $this->dataManager->getPageDetailsById($pageId, true);
                ApiResponse::success($updatedPage);
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                ApiResponse::error('Failed to update page: ' . $e->getMessage(), 500);
            }
        }
        
        private function handleDelete($input) {
            $pageId = $input['id'] ?? null;
            if (!$pageId) ApiResponse::error('Page ID is required for deletion', 400);

            try {
                $stmt = $this->pdo->prepare("DELETE FROM Pages WHERE id = ?");
                $stmt->execute([$pageId]);
                if ($stmt->rowCount() > 0) ApiResponse::success(['deleted_page_id' => (int)$pageId]);
                else ApiResponse::error('Page not found', 404);
            } catch (PDOException $e) {
                ApiResponse::error('Failed to delete page: ' . $e->getMessage(), 500);
            }
        }
    }
}

class ExtendedDataManager extends DataManager {
    public function __construct(PDO $pdo) {
        parent::__construct($pdo);
    }

    public function getPagePropertiesForPageIds(array $pageIds) {
        if (empty($pageIds)) return [];
        $placeholders = str_repeat('?,', count($pageIds) - 1) . '?';
        $sql = "SELECT page_id, name, value, weight, created_at FROM Properties WHERE page_id IN ($placeholders) AND active = 1 ORDER BY page_id, created_at ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($pageIds);
        $allProps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_fill_keys($pageIds, []);
        foreach($allProps as $prop) {
            $result[$prop['page_id']][$prop['name']][] = [
                'value' => $prop['value'],
                'weight' => (int)$prop['weight'],
                'created_at' => $prop['created_at']
            ];
        }
        return $result;
    }
}

// Bootstrapping the request handling
$extendedDataManager = new ExtendedDataManager($pdo);
$pageManager = new PageManager($pdo, $extendedDataManager);
$pageManager->handleRequest();