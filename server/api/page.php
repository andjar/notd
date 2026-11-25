<?php
// GET /api/v1/page?title=... or ?id=...: a page and all its blocks.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return [
    'GET' => function (Request $r): array {
        $id = $r->query['id'] ?? null;
        $title = $r->query['title'] ?? null;
        if (!is_string($id) && !is_string($title)) {
            throw new HttpError(400, 'bad_request', 'Give "title" or "id".');
        }
        return Read::page(Db::get(), is_string($title) ? $title : null, is_string($id) ? $id : null);
    },
];
