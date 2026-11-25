<?php
// GET /api/v1/journal-days?month=YYYY-MM: the days of a month whose journal has text.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => Read::journalDays(Db::get(), $r->string('month'))];
