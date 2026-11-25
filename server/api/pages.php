<?php
// GET /api/v1/pages: every page title with its aliases.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => Read::pages(Db::get())];
