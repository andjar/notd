<?php

namespace App;

// api/v1/pages.php

/**
 * Endpoint for all Page-related operations.
 * This file acts as a router, delegating all logic to the DataManager.
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../PatternProcessor.php';
require_once __DIR__ . '/../response_utils.php';

if (!function_exists('_indexPropertiesFromContent')) {
    function _indexPropertiesFromContent($pdo, $entityType, $entityId, $content) {
        // For pages, we don't have the 'encrypted' property check or 'internal' flag update as for notes.

        // Instantiate the pattern processor with the existing PDO connection to avoid database locks
        $patternProcessor = new \App\PatternProcessor($pdo);

        // Process the content to extract properties and potentially modified content
        // Pass $pdo in context for handlers that might need it directly.
        $processedData = $patternProcessor->processContent($content, $entityType, $entityId, ['pdo' => $pdo]);
        
        $parsedProperties = $processedData['properties'];

        // Save all extracted/generated properties using the processor's save method
        // This method should handle deleting old 'replaceable' properties and inserting/updating new ones.
        // It will also handle property triggers.
        if (!empty($parsedProperties)) {
            $patternProcessor->saveProperties($parsedProperties, $entityType, $entityId);
        } else {
            // If no properties are parsed from content, we might still need to clear existing replaceable ones.
            // This relies on PatternProcessor.saveProperties (or a dedicated method there)
            // to handle clearing properties when an empty set is passed.
            // For now, assuming saveProperties handles this.
            // A potential explicit call: $patternProcessor->clearReplaceableProperties($entityType, $entityId);
        }
        // Note: No 'internal' flag update logic here as it's specific to Notes.
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

$pdo = get_db_connection();
$dataManager = new \App\DataManager($pdo);
// $propertyParser = new PropertyParser($pdo); // Removed global instantiation

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['name'])) {
                $pageName = $_GET['name'];
                $page = $dataManager->getPageByName($pageName);
                if (!$page) {
                    // Page does not exist, so create it.
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO Pages (name, content) VALUES (:name, :content)");
                    $stmt->execute([':name' => $pageName, ':content' => null]);
                    $pdo->commit();
                    $page = $dataManager->getPageByName($pageName); // Re-fetch the newly created page
                }
                \App\ApiResponse::success($page, 200);
            } elseif (isset($_GET['id'])) {
                $page = $dataManager->getPageById((int)$_GET['id']);
                if ($page) {
                    \App\ApiResponse::success($page);
                } else {
                    \App\ApiResponse::error('Page not found', 404);
                }
            } elseif (isset($_GET['date'])) {
                $pages = $dataManager->getPagesByDate($_GET['date']);
                \App\ApiResponse::success($pages);
            } else {
                $page = $_GET['page'] ?? 1;
                $per_page = $_GET['per_page'] ?? 20;
                $options = [
                    'exclude_journal' => isset($_GET['exclude_journal'])
                ];
                $result = $dataManager->getPages((int)$page, (int)$per_page, $options);
                
                // This now returns the correct, non-double-wrapped structure
                \App\ApiResponse::success($result['data'], 200, ['pagination' => $result['pagination']]);
            }
            break;

        case 'POST': // Create
            $name = $input['name'] ?? null;
            $content = $input['content'] ?? null;

            if (!$name) {
                \App\ApiResponse::error('Page name is required.', 400);
            }
            if ($dataManager->getPageByName($name)) {
                \App\ApiResponse::error('Page with this name already exists.', 409);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO Pages (name, content) VALUES (:name, :content)");
            $stmt->execute([':name' => $name, ':content' => $content]);
            $pageId = $pdo->lastInsertId();
            
            if ($content) {
                _indexPropertiesFromContent($pdo, 'page', $pageId, $content);
            }
            $pdo->commit();

            $newPage = $dataManager->getPageById($pageId);
            \App\ApiResponse::success($newPage, 201);
            break;

        case 'PUT': // Update
            $pageId = $input['id'] ?? null;
            if (!$pageId) {
                \App\ApiResponse::error('Page ID is required for update.', 400);
            }

            // Fetch existing page to ensure it exists
            $page = $dataManager->getPageById($pageId);
            if (!$page) {
                \App\ApiResponse::error('Page not found.', 404);
            }

            $newName = $input['name'] ?? $page['name'];
            $newContent = $input['content'] ?? $page['content'];
            
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE Pages SET name = :name, content = :content, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':name' => $newName, ':content' => $newContent, ':id' => $pageId]);

            // Re-index properties if content changed
            if (array_key_exists('content', $input)) {
                _indexPropertiesFromContent($pdo, 'page', $pageId, $newContent);
            }
            $pdo->commit();
            
            $updatedPage = $dataManager->getPageById($pageId);
            \App\ApiResponse::success($updatedPage);
            break;

        case 'DELETE':
            $pageId = $input['id'] ?? null;
            if (!$pageId) {
                \App\ApiResponse::error('Page ID is required for deletion.', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = :id");
            $stmt->execute([':id' => $pageId]);

            if ($stmt->rowCount() > 0) {
                \App\ApiResponse::success(['deleted_page_id' => $pageId]);
            } else {
                \App\ApiResponse::error('Page not found.', 404);
            }
            break;

        default:
            \App\ApiResponse::error('Method not supported.', 405);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Let the global exception handler in config.php format the error
    throw $e;
}