<?php

namespace App;

// Start output buffering to prevent header issues
ob_start();

// Disable error handlers before including config.php to prevent header issues
set_error_handler(null);
set_exception_handler(null);

// api/v1/pages.php

/**
 * Endpoint for all Page-related operations.
 * This file acts as a router, delegating all logic to the DataManager.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../UuidUtils.php';

use App\UuidUtils;

$method = $_SERVER['REQUEST_METHOD'];
// Disable global error handlers
set_error_handler(null);
set_exception_handler(null);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);

    switch ($method) {
        case 'GET':
            if (isset($_GET['name'])) {
                $pageName = $_GET['name'];
                $page = $dataManager->getPageByName($pageName);
                if (!$page) {
                    // Page does not exist, so create it.
                    $pdo->beginTransaction();
                    $pageId = \App\UuidUtils::generateUuidV7();
                    $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (:id, :name, :content)");
                    $stmt->execute([':id' => $pageId, ':name' => $pageName, ':content' => null]);
                    $pdo->commit();
                    $page = $dataManager->getPageByName($pageName); // Re-fetch the newly created page
                }
                \App\ApiResponse::success($page, 200);
            } elseif (isset($_GET['id'])) {
                $page = $dataManager->getPageById($_GET['id']); // Handle both UUIDs and integers
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
            $pageId = \App\UuidUtils::generateUuidV7();
            $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (:id, :name, :content)");
            $stmt->execute([':id' => $pageId, ':name' => $name, ':content' => $content]);
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
            $pdo->commit();

            $updatedPage = $dataManager->getPageById($pageId);
            \App\ApiResponse::success($updatedPage);
            break;

        case 'DELETE':
            $pageId = $input['id'] ?? null;
            if (!$pageId) {
                \App\ApiResponse::error('Page ID is required for deletion.', 400);
            }

            // Fetch existing page to ensure it exists
            $page = $dataManager->getPageById($pageId);
            if (!$page) {
                \App\ApiResponse::error('Page not found.', 404);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE Pages SET active = 0 WHERE id = :id");
            $stmt->execute([':id' => $pageId]);
            $pdo->commit();

            \App\ApiResponse::success(null, 204);
            break;

        default:
            \App\ApiResponse::error('Method not allowed', 405);
            break;
    }
} catch (Exception $e) {
    \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
}

// End output buffering and send the response
ob_end_flush();