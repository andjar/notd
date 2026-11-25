<?php
// GET /api/v1/changes?since=N&limit=500: what changed after change number N.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => Feed::since(Db::get(), (int) ($r->query['since'] ?? 0), (int) ($r->query['limit'] ?? 500))];
