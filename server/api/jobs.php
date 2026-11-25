<?php
// GET /api/v1/jobs?limit=50: the job log, newest first.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => Jobs::recent(Db::get(), (int) ($r->query['limit'] ?? 50))];
