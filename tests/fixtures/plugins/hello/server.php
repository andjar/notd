<?php
// Test plugin: counts changes, indexes "!!" as {urgent::yes}, and adds the filter "urgent:".
return function (PluginApi $notd): void {
    $notd->onChange(function (PDO $pdo, string $op, string $id): void {
        $pdo->exec("INSERT INTO meta (key, value) VALUES ('hello_count', '1')
            ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + 1");
    });
    $notd->indexer(fn(string $text): array => str_contains($text, '!!') ? [['urgent', 'yes']] : []);
    $notd->filter('urgent', fn(string $op, string $value): array => [
        ($value === 'yes' ? '' : 'NOT ') . "n.id IN (SELECT node_id FROM fields WHERE key = 'urgent')", [],
    ]);
};
