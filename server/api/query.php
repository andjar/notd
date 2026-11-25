<?php
// GET /api/v1/query?q=...: run a query (see server/query.php for the language).

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => Query::run(Db::get(), $r->string('q'))];
