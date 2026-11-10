<?php
/**
 * /api/v1/pages.php
 *
 *   GET ?name=N            the page with that name (created if it does not exist)
 *   GET ?id=N              one page
 *   GET ?date=YYYY-MM-DD   pages with a {date::...} property of that day
 *   GET                    list, newest first (?page, ?per_page, ?exclude_journal)
 *   POST   {name, content?}          create
 *   PUT    {id, name?, content?}     update; a new name renames the page and rewrites links to it
 *   DELETE {id}                      delete the page and its notes
 *
 * PUT and DELETE may be sent as POST with "_method" in the body.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\DataManager;
use App\PageException;
use App\PageStore;

$pdo = get_db_connection();
$dataManager = new DataManager($pdo);
$pages = new PageStore($pdo);
$input = notd_json_input();

try {
    switch (notd_request_method()) {
        case 'GET':
            if (isset($_GET['name'])) {
                $id = $pages->ensure((string)$_GET['name']);
                ApiResponse::success($dataManager->getPageById($id));
            } elseif (isset($_GET['id'])) {
                $page = $dataManager->getPageById((int)$_GET['id']);
                $page ? ApiResponse::success($page) : ApiResponse::error('Page not found', 404);
            } elseif (isset($_GET['date'])) {
                ApiResponse::success($dataManager->getPagesByDate((string)$_GET['date']));
            } else {
                $result = $dataManager->getPages(
                    max(1, (int)($_GET['page'] ?? 1)),
                    max(1, min(5000, (int)($_GET['per_page'] ?? 20))),
                    ['exclude_journal' => isset($_GET['exclude_journal'])]
                );
                ApiResponse::success($result['data'], 200, ['pagination' => $result['pagination']]);
            }
            break;

        case 'POST':
            $content = isset($input['content']) ? (string)$input['content'] : null;
            $id = $pages->create($input['name'] ?? null, $content);
            ApiResponse::success($dataManager->getPageById($id), 201);
            break;

        case 'PUT':
            if (empty($input['id'])) {
                ApiResponse::error('Page ID is required for update.', 400);
            }
            $changes = array_intersect_key($input, ['name' => 1, 'content' => 1]);
            $result = $pages->update((int)$input['id'], $changes);
            $page = $dataManager->getPageById((int)$input['id']);
            if ($result['renamed']) {
                $page['renamed'] = $result['renamed'];
                $page['notes_updated'] = $result['notes_updated'];
            }
            ApiResponse::success($page);
            break;

        case 'DELETE':
            if (empty($input['id'])) {
                ApiResponse::error('Page ID is required for deletion.', 400);
            }
            $pages->delete((int)$input['id']);
            ApiResponse::success(['deleted_page_id' => (int)$input['id']]);
            break;

        default:
            ApiResponse::error('Method not supported.', 405);
    }
} catch (PageException $e) {
    ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
}
