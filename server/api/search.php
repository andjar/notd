<?php
// GET /api/v1/search?q=...&limit=50&offset=0: full-text search.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return [
    'GET' => fn(Request $r): array => Search::query(Db::get(), $r->string('q'),
        (int) ($r->query['limit'] ?? 50), (int) ($r->query['offset'] ?? 0)),
];
