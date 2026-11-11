<?php
/**
 * Imports Markdown outlines as pages:
 *
 *     php tools/import.php <file-or-folder> [...]
 *
 * Each .md file becomes a page named after the file ("Projects___Alpha.md" is
 * the page "Projects/Alpha", "2026_11_10.md" the journal page 2026-11-10).
 * Folders are read recursively, so a Logseq graph (its journals/ and pages/
 * folders) or the output of tools/export.php can be imported as it is.
 *
 * Notes are appended: importing into an existing page keeps what is there.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../api/bootstrap.php';

$sources = array_slice($argv, 1);
if (!$sources) {
    fwrite(STDERR, "Usage: php tools/import.php <file-or-folder> [...]\n");
    exit(1);
}

$files = [];
foreach ($sources as $source) {
    if (is_dir($source)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (preg_match('/\.(md|markdown)$/i', $path) && !preg_match('#/(assets|logseq|\.[^/]+)/#', $path)) {
                $files[] = $file->getPathname();
            }
        }
    } elseif (is_file($source)) {
        $files[] = $source;
    } else {
        fwrite(STDERR, "Not found: $source\n");
        exit(1);
    }
}
sort($files);

$markdown = new App\Markdown(get_db_connection());
$pages = 0;
$notes = 0;
foreach ($files as $file) {
    try {
        $result = $markdown->importPage(App\Markdown::pageName($file), (string)file_get_contents($file));
        $pages++;
        $notes += $result['notes'];
    } catch (Throwable $e) {
        fwrite(STDERR, "Skipped $file: " . $e->getMessage() . "\n");
    }
}
echo "Imported $notes notes into $pages pages\n";
