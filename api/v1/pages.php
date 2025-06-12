<?php
// api/v1/pages.php

/**
 * Endpoint for all Page-related operations.
 * This file acts as a router, delegating all logic to the DataManager.
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/../response_utils.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

$pdo = get_db_connection();
$dataManager = new DataManager($pdo);
$propertyParser = new PropertyParser($pdo);

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['name'])) {
                $page = $dataManager->getPageByName($_GET['name']);
                if ($page) {
                    ApiResponse::success($page);
                } else {
                    ApiResponse::error('Page not found', 404);
                }
            } elseif (isset($_GET['id'])) {
                $page = $dataManager->getPageById((int)$_GET['id']);
                if ($page) {
                    ApiResponse::success($page);
                } else {
                    ApiResponse::error('Page not found', 404);
                }
            } else {
                $page = $_GET['page'] ?? 1;
                $per_page = $_GET['per_page'] ?? 20;
                $options = [
                    'exclude_journal' => isset($_GET['exclude_journal'])
                ];
                $result = $dataManager->getPages((int)$page, (int)$per_page, $options);
                
                // This now returns the correct, non-double-wrapped structure
                ApiResponse::success($result['data'], 200, ['pagination' => $result['pagination']]);
            }
            break;

        case 'POST': // Create
            $name = $input['name'] ?? null;
            $content = $input['content'] ?? null;

            if (!$name) {
                ApiResponse::error('Page name is required.', 400);
            }
            if ($dataManager->getPageByName($name)) {
                ApiResponse::error('Page with this name already exists.', 409);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO Pages (name, content) VALUES (:name, :content)");
            $stmt->execute([':name' => $name, ':content' => $content]);
            $pageId = $pdo->lastInsertId();
            
            if ($content) {
                $propertyParser->syncProperties('page', $pageId, $content);
            }
            $pdo->commit();

            $newPage = $dataManager->getPageById($pageId);
            ApiResponse::success($newPage, 201);
            break;

        case 'PUT': // Update
            $pageId = $input['id'] ?? null;
            if (!$pageId) {
                ApiResponse::error('Page ID is required for update.', 400);
            }

            // Fetch existing page to ensure it exists
            $page = $dataManager->getPageById($pageId);
            if (!$page) {
                ApiResponse::error('Page not found.', 404);
            }

            $newName = $input['name'] ?? $page['name'];
            $newContent = $input['content'] ?? $page['content'];
            
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE Pages SET name = :name, content = :content, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':name' => $newName, ':content' => $newContent, ':id' => $pageId]);

            // Re-index properties if content changed
            if (array_key_exists('content', $input)) {
                $propertyParser->syncProperties('page', $pageId, $newContent);
            }
            $pdo->commit();
            
            $updatedPage = $dataManager->getPageById($pageId);
            ApiResponse::success($updatedPage);
            break;

        case 'DELETE':
            $pageId = $input['id'] ?? null;
            if (!$pageId) {
                ApiResponse::error('Page ID is required for deletion.', 400);
            }
            $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = :id");
            $stmt->execute([':id' => $pageId]);

            if ($stmt->rowCount() > 0) {
                ApiResponse::success(['deleted_page_id' => $pageId]);
            } else {
                ApiResponse::error('Page not found.', 404);
            }
            break;

        default:
            ApiResponse::error('Method not supported.', 405);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Let the global exception handler in config.php format the error
    throw $e;
}