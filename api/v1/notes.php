<?php
/**
 * GET /api/v1/notes.php?page_id=N           all notes of a page (flat, ordered by sort_key, id)
 * GET /api/v1/notes.php?id=X[&include_children=1]
 *
 * Notes are read-only here; every write goes through ops.php.
 */

namespace App;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../Ordering.php';
require_once __DIR__ . '/../response_utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('notes.php is read-only. Send writes to ops.php.', 405);
    return;
}

$pdo = get_db_connection();
$dataManager = new DataManager($pdo);
$includeInternal = (bool)filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
$includeParentProperties = (bool)filter_input(INPUT_GET, 'include_parent_properties', FILTER_VALIDATE_BOOLEAN);
$includeChildren = (bool)filter_input(INPUT_GET, 'include_children', FILTER_VALIDATE_BOOLEAN);

try {
    if (isset($_GET['id'])) {
        $noteId = (string)$_GET['id'];
        if (!Ordering::isValidId($noteId)) {
            ApiResponse::error('Invalid note id', 400);
            return;
        }
        $note = $includeChildren
            ? $dataManager->getNoteWithChildren($noteId, $includeInternal, $includeParentProperties)
            : $dataManager->getNoteById($noteId, $includeInternal, $includeParentProperties);
        if ($note) {
            ApiResponse::success($note);
        } else {
            ApiResponse::error('Note not found', 404);
        }
    } elseif (isset($_GET['page_id'])) {
        ApiResponse::success($dataManager->getNotesByPageId((int)$_GET['page_id'], $includeInternal));
    } else {
        ApiResponse::error('Missing required parameter: id or page_id', 400);
    }
} catch (\Exception $e) {
    error_log("notes.php GET failed: " . $e->getMessage());
    ApiResponse::error('An error occurred while fetching data: ' . $e->getMessage(), 500);
}
