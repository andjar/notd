<?php
/**
 * GET /api/v1/export.php?page=Name (or ?page_id=N)
 * The page as a Markdown outline (text/markdown). &download=1 offers it as a file.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\Markdown;
use App\PageStore;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Only GET is supported.', 405);
    return;
}
$pdo = get_db_connection();
$pages = new PageStore($pdo);
$page = isset($_GET['page_id']) ? $pages->findById((int)$_GET['page_id']) : $pages->findByName(trim((string)($_GET['page'] ?? '')));
if (!$page) {
    ApiResponse::error('Page not found', 404);
    return;
}
header('Content-Type: text/markdown; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (notd_query_flag('download')) {
    $file = Markdown::fileName($page['name']);
    header('Content-Disposition: attachment; filename="' . addcslashes(preg_replace('/[^\x20-\x7e]/', '_', $file), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($file));
}
echo (new Markdown($pdo))->exportPage((int)$page['id']);
