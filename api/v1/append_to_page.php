<?php
/**
 * POST /api/v1/append_to_page.php
 * {"page_name": "...", "notes": "text" | ["text", {"content": "...", "children": [...]}, ...]}
 *
 * Creates the page if needed and appends the notes at the end of its top level
 * (nested `children` become child notes). The simplest way for a script to
 * add notes; used by the RSS, mail and pomodoro extensions.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\DataManager;
use App\NoteOps;
use App\OpValidationException;
use App\PageException;
use App\PageStore;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed. Only POST is supported.', 405);
    return;
}

$input = notd_json_input();
if (!isset($input['page_name']) || !is_string($input['page_name']) || trim($input['page_name']) === '') {
    ApiResponse::error('page_name is required and must be a non-empty string.', 400);
    return;
}
if (!isset($input['notes'])) {
    ApiResponse::error('notes field is required.', 400);
    return;
}
$notes = is_string($input['notes']) ? [$input['notes']] : $input['notes'];
if (!is_array($notes) || !$notes) {
    ApiResponse::error('notes must be a string or a non-empty array of strings or {content} objects.', 400);
    return;
}

try {
    $pdo = get_db_connection();
    $pages = new PageStore($pdo);
    $dataManager = new DataManager($pdo);
    $existed = $pages->findByName(PageStore::normalizeName($input['page_name'])) !== null;

    [$pageId, $created] = notd_write($pdo, static function (PDO $pdo) use ($pages, $dataManager, $input, $notes) {
        $pageId = $pages->ensure($input['page_name']);
        return [$pageId, (new NoteOps($pdo, $dataManager))->appendTree($pageId, $notes)];
    });

    ApiResponse::success([
        'message' => ($existed ? 'Page retrieved' : 'Page created') . ' and notes appended successfully.',
        'page' => $dataManager->getPageById($pageId),
        'appended_notes' => $created,
    ]);
} catch (OpValidationException | PageException $e) {
    ApiResponse::error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('append_to_page.php failed: ' . $e->getMessage());
    ApiResponse::error('An error occurred: ' . $e->getMessage(), notd_is_busy($e) ? 503 : 500);
}
