<?php
// The RSS plugin, server side: a block with {feed:: https://...} is read every
// hour (rss_every, in minutes) by a job, and new items are added under it; see
// lib/rss.php. POST /api/v1/rss.refresh reads every feed (or {"id": ...}, one)
// now and says what it added.

declare(strict_types=1);

require_once __DIR__ . '/lib/rss.php';

return function (PluginApi $notd): void {
    $notd->jobHandler('rss.fetch', fn(array $payload): string => Rss::job());
    $notd->onChange(fn(PDO $pdo, string $op, string $id) => Rss::changed($pdo, $op, $id));
    $notd->endpoint('refresh', [
        'POST' => function (Request $r): array {
            $id = $r->body()['id'] ?? null;
            return ['feeds' => Rss::refresh(Db::get(), true, is_string($id) ? $id : null)];
        },
    ]);
};
