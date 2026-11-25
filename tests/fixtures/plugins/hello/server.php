<?php
// Counts changes in meta, so a test can see that server code runs (and does not in safe mode).
return function (PluginApi $notd): void {
    $notd->onChange(function (PDO $pdo, string $op, string $id): void {
        $pdo->exec("INSERT INTO meta (key, value) VALUES ('hello_count', '1')
            ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + 1");
    });
};
