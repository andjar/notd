<?php
/**
 * POST /api/v1/append_to_page.php
 * {"page_name": "...", "notes": "text" | [{"content": "...", "children": [...]}, ...]}
 *
 * Creates the page if needed and appends the notes at the end of its top level
 * (nested `children` become child notes). Used by the RSS and mail extensions.
 */

namespace App;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../validator_utils.php';
require_once __DIR__ . '/../NoteOps.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed. Only POST is supported.', 405);
    return;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['page_name']) || !is_string($input['page_name']) || trim($input['page_name']) === '') {
    ApiResponse::error('page_name is required and must be a non-empty string.', 400);
    return;
}
if (!isset($input['notes'])) {
    ApiResponse::error('notes field is required.', 400);
    return;
}
$notesInput = is_string($input['notes']) ? [['content' => $input['notes']]] : $input['notes'];
if (!is_array($notesInput) || empty($notesInput)) {
    ApiResponse::error('notes must be a string or a non-empty array of {content} objects.', 400);
    return;
}

$pageName = \Validator::sanitizeString($input['page_name']);
$pdo = get_db_connection();
$dataManager = new DataManager($pdo);

try {
    $pdo->beginTransaction();

    $page = $dataManager->getPageDetailsByName($pageName, false);
    if ($page) {
        $pageId = (int)$page['id'];
    } else {
        $pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)")->execute([$pageName]);
        $pageId = (int)$pdo->lastInsertId();
    }

    $noteOps = new NoteOps($pdo, $dataManager);
    $created = [];

    // Breadth-first so each level is appended after its parent exists.
    $queue = [[null, $notesInput]];
    while ($queue) {
        [$parentId, $items] = array_shift($queue);
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['content']) || !is_string($item['content'])) {
                throw new \InvalidArgumentException("Each note must be an object with a 'content' string.");
            }
        }
        $ops = $noteOps->appendCreateOps($pageId, $items, $parentId);
        $result = $noteOps->apply($ops);
        $created = array_merge($created, $result['notes']);
        foreach ($items as $k => $item) {
            if (!empty($item['children']) && is_array($item['children'])) {
                $queue[] = [$ops[$k]['id'], $item['children']];
            }
        }
    }

    $pdo->commit();
    ApiResponse::success([
        'message' => ($page ? 'Page retrieved' : 'Page created') . ' and notes appended successfully.',
        'page' => $dataManager->getPageDetailsById($pageId, false),
        'appended_notes' => $created,
    ]);
} catch (\InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ApiResponse::error($e->getMessage(), 400);
} catch (\Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("append_to_page.php failed: " . $e->getMessage());
    ApiResponse::error('An error occurred: ' . $e->getMessage(), 500);
}
