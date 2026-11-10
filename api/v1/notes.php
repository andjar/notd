<?php
/**
 * GET /api/v1/notes.php: read notes. Writes go to ops.php.
 *
 *   ?page_id=N   all notes of a page, flat, ordered by (sort_key, id)
 *   ?id=X        one note (&include_children=1 for its subtree,
 *                &include_parent_properties=1 for inherited properties)
 *   &include_internal=1 also returns properties of weight 3 and above
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\DataManager;
use App\Ordering;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('notes.php is read-only. Send writes to ops.php.', 405);
    return;
}

$dataManager = new DataManager(get_db_connection());
$includeInternal = notd_query_flag('include_internal');

if (isset($_GET['id'])) {
    $noteId = (string)$_GET['id'];
    if (!Ordering::isValidId($noteId)) {
        ApiResponse::error('Invalid note id', 400);
        return;
    }
    $note = notd_query_flag('include_children')
        ? $dataManager->getNoteWithChildren($noteId, $includeInternal, notd_query_flag('include_parent_properties'))
        : $dataManager->getNoteById($noteId, $includeInternal, notd_query_flag('include_parent_properties'));
    $note ? ApiResponse::success($note) : ApiResponse::error('Note not found', 404);
} elseif (isset($_GET['page_id'])) {
    ApiResponse::success($dataManager->getNotesByPageId((int)$_GET['page_id'], $includeInternal));
} else {
    ApiResponse::error('Missing required parameter: id or page_id', 400);
}
