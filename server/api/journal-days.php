<?php
// GET /api/v1/journal-days?month=YYYY-MM: the days of a month whose journal has text.
// GET /api/v1/journal-days?before=YYYY-MM-DD&limit=10: the latest such days before a date, newest first.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => isset($r->query['before'])
    ? Read::journalDaysBefore(Db::get(), $r->string('before'), (int) ($r->query['limit'] ?? 10))
    : Read::journalDays(Db::get(), $r->string('month'))];
