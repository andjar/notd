<?php
/**
 * GET /api/v1/properties.php?entity_type=note|page&entity_id=X
 * The indexed properties of a note or page. Read-only: properties change by
 * editing the content they are written in.
 *   &include_hidden=1 also returns properties of weight 3 and above
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\DataManager;
use App\Ordering;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed. This is a read-only endpoint.', 405);
    return;
}

$type = $_GET['entity_type'] ?? '';
$id = (string)($_GET['entity_id'] ?? '');
$errors = [];
if ($type !== 'note' && $type !== 'page') {
    $errors['entity_type'] = 'Must be "note" or "page".';
}
if ($type === 'page' ? !ctype_digit($id) : !Ordering::isValidId($id)) {
    $errors['entity_id'] = 'Invalid value for entity_id.';
}
if ($errors) {
    ApiResponse::error('Invalid input parameters.', 400, $errors);
    return;
}

$pdo = get_db_connection();
$exists = $pdo->prepare($type === 'note' ? 'SELECT 1 FROM Notes WHERE id = ?' : 'SELECT 1 FROM Pages WHERE id = ?');
$exists->execute([$id]);
if (!$exists->fetchColumn()) {
    ApiResponse::error($type === 'note' ? 'Note not found' : 'Page not found', 404);
    return;
}

$dataManager = new DataManager($pdo);
$includeHidden = notd_query_flag('include_hidden');
ApiResponse::success($type === 'note'
    ? $dataManager->getNoteProperties($id, $includeHidden)
    : $dataManager->getPageProperties((int)$id, $includeHidden));
