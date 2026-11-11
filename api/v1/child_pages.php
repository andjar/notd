<?php
/**
 * GET /api/v1/child_pages.php?namespace=Name
 * Pages directly below a page in the namespace hierarchy (Name/Child, not Name/Child/Deeper).
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\DataManager;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Only GET method is accepted.', 405);
    return;
}
$namespace = trim((string)($_GET['namespace'] ?? ''));
if ($namespace === '') {
    ApiResponse::error('Namespace parameter is required.', 400);
    return;
}
ApiResponse::success((new DataManager(get_db_connection()))->getChildPages($namespace));
