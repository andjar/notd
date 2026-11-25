<?php
// Loads the server code for unit tests, and gives each test a fresh database.

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/server/http.php';
require_once dirname(__DIR__, 3) . '/server/db.php';
require_once dirname(__DIR__, 3) . '/server/nodes.php';

/** A fresh database in a temporary folder; removed when the test process ends. */
function fresh_db(): PDO
{
    $dir = sys_get_temp_dir() . '/notd-test-' . bin2hex(random_bytes(6));
    mkdir($dir);
    register_shutdown_function(function () use ($dir) {
        foreach (glob("$dir/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    });
    return Db::open("$dir/notd.sqlite");
}

function db_file(PDO $pdo): string
{
    return $pdo->query('PRAGMA database_list')->fetch()['file'];
}
