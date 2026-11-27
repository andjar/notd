<?php
// Loads the server code for unit tests, and gives each test a fresh database.

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/server/core.php';

/** A fresh data folder (also set as NOTD_DATA_DIR) with a fresh database; removed when the test process ends. */
function fresh_db(): PDO
{
    $dir = sys_get_temp_dir() . '/notd-test-' . bin2hex(random_bytes(6));
    mkdir($dir);
    putenv("NOTD_DATA_DIR=$dir");
    register_shutdown_function(fn() => remove_tree($dir));
    return Db::open("$dir/notd.sqlite");
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function db_file(PDO $pdo): string
{
    return $pdo->query('PRAGMA database_list')->fetch()['file'];
}

function copy_tree(string $from, string $to): void
{
    mkdir($to, 0777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $target = $to . substr($f->getPathname(), strlen($from));
        $f->isDir() ? mkdir($target) : copy($f->getPathname(), $target);
    }
}
