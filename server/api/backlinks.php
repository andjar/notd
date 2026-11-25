<?php
// GET /api/v1/backlinks?id=...: blocks that link to a page or refer to a block, grouped by page.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => Read::backlinks(Db::get(), $r->string('id'))];
