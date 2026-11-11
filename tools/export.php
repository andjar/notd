<?php
/**
 * Exports every page as a Markdown file, in the folder layout Logseq uses:
 *
 *     php tools/export.php <target-folder>
 *
 *     <target>/journals/2026_11_10.md      journal pages
 *     <target>/pages/Projects___Alpha.md   other pages ("/" in a name becomes "___")
 *     <target>/assets/...                  a copy of the uploaded files
 *
 * The result opens as a Logseq graph and can be read back with tools/import.php.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../api/bootstrap.php';

$target = $argv[1] ?? null;
if (!$target) {
    fwrite(STDERR, "Usage: php tools/export.php <target-folder>\n");
    exit(1);
}
foreach (['journals', 'pages'] as $dir) {
    if (!is_dir("$target/$dir") && !mkdir("$target/$dir", 0777, true)) {
        fwrite(STDERR, "Cannot create $target/$dir\n");
        exit(1);
    }
}

$pdo = get_db_connection();
$markdown = new App\Markdown($pdo);
$count = 0;
foreach ($pdo->query('SELECT id, name FROM Pages WHERE active = 1 ORDER BY name') as $page) {
    $dir = App\PageStore::isJournalName($page['name']) ? 'journals' : 'pages';
    file_put_contents("$target/$dir/" . App\Markdown::fileName($page['name']), $markdown->exportPage((int)$page['id']));
    $count++;
}

$files = 0;
$uploads = realpath(UPLOADS_DIR);
if ($uploads !== false) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($uploads) + 1);
        $dest = "$target/assets/$relative";
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0777, true);
        }
        copy($file->getPathname(), $dest);
        $files++;
    }
}
echo "Exported $count pages and $files files to $target\n";
