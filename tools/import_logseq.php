<?php
/**
 * Imports a Logseq graph (the file-based Markdown format) into notd:
 * journals, pages, properties, block references and attachments.
 *
 *     php tools/import_logseq.php [options] <graph-folder>
 *
 * The graph folder is the one holding journals/, pages/, assets/ and logseq/.
 * See api/LogseqImport.php for exactly what is converted and how.
 *
 * Options:
 *   --dry-run            show what would be imported; change nothing
 *   --replace            replace the notes of pages that already exist in notd
 *                        (default: merge, i.e. keep them and add what is new;
 *                        running the import twice adds nothing the second time)
 *   --strict             roll everything back if any page fails
 *   --tags-as-links      #tag becomes [[tag]] (default: {tag::tag}, a tag that links to its page)
 *   --keep-tags          leave #tags as they are
 *   --rewrite-alias-links  [[alias]] becomes [[Page]] (default: kept; notd resolves aliases)
 *   --max-file-size=MB   skip attachments larger than this (default 10, 0 = no limit)
 *   --filename-format=F  triple-lowbar or legacy (default: from logseq/config.edn)
 *   --webhooks           send the webhooks the imported properties trigger
 *   --quiet              print only the summary and errors
 *   --json               print the full report as JSON
 *
 * Point NOTD_DB_PATH at another database to import there instead:
 *     NOTD_DB_PATH=/tmp/try.sqlite php tools/import_logseq.php ~/logseq
 *
 * Exit status: 0 when every page was imported, 1 when some failed, 2 on bad usage.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../api/bootstrap.php';

$options = ['mode' => 'merge'];
$quiet = false;
$json = false;
$graph = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif ($arg === '--replace') {
        $options['mode'] = 'replace';
    } elseif ($arg === '--strict') {
        $options['strict'] = true;
    } elseif ($arg === '--keep-tags') {
        $options['tags'] = 'keep';
    } elseif ($arg === '--tags-as-links') {
        $options['tags'] = 'link';
    } elseif ($arg === '--rewrite-alias-links') {
        $options['resolve_aliases'] = true;
    } elseif (preg_match('/^--max-file-size=(\d+(?:\.\d+)?)$/', $arg, $m)) {
        $options['max_file_size'] = (int)round((float)$m[1] * 1024 * 1024);
    } elseif (preg_match('/^--filename-format=(triple-lowbar|legacy)$/', $arg, $m)) {
        $options['filename_format'] = $m[1];
    } elseif ($arg === '--webhooks') {
        $options['webhooks'] = true;
    } elseif ($arg === '--quiet') {
        $quiet = true;
    } elseif ($arg === '--json') {
        $json = true;
        $quiet = true;
    } elseif ($arg === '-h' || $arg === '--help') {
        preg_match('#/\*\*(.*?)\*/#s', (string)file_get_contents(__FILE__), $doc);
        echo preg_replace('/^ ?\* ?/m', '', trim($doc[1])) . "\n";
        exit(0);
    } elseif ($arg !== '' && $arg[0] === '-') {
        fwrite(STDERR, "Unknown option: $arg (see --help)\n");
        exit(2);
    } elseif ($graph === null) {
        $graph = $arg;
    } else {
        fwrite(STDERR, "Give one graph folder (see --help)\n");
        exit(2);
    }
}
if ($graph === null) {
    fwrite(STDERR, "Usage: php tools/import_logseq.php [--dry-run] [--replace] <graph-folder>   (--help for more)\n");
    exit(2);
}

$options['log'] = static function (string $level, string $message) use ($quiet): void {
    if ($level === 'error') {
        fwrite(STDERR, "error: $message\n");
    } elseif (!$quiet) {
        fwrite(STDERR, ($level === 'warning' ? 'warning: ' : '') . "$message\n");
    }
};

try {
    $report = (new App\LogseqImport(get_db_connection(), $options))->run($graph);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed, nothing was changed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    $r = $report;
    echo ($r['dry_run'] ? "Dry run, nothing was changed. Would import:\n" : "Imported:\n")
        . sprintf("  pages        %d new, %d existing (%d journals)%s\n", $r['pages_created'], $r['pages_updated'], $r['journals'],
            $r['pages_empty'] ? ", {$r['pages_empty']} empty files skipped" : '')
        . sprintf("  notes        %d new, %d already there\n", $r['notes_created'], $r['notes_unchanged'])
        . sprintf("  attachments  %d files stored, %d links to files already stored\n", $r['attachments_stored'], $r['attachments_reused'])
        . (count($r['files_skipped']) ? sprintf("  skipped      %d files (see warnings)\n", count(array_unique($r['files_skipped']))) : '')
        . (count($r['warnings']) ? sprintf("  warnings     %d\n", count($r['warnings'])) : '')
        . (count($r['failed']) ? sprintf("  FAILED       %d pages: %s\n", count($r['failed']), implode(', ', array_column($r['failed'], 'file'))) : '');
}
exit($report['failed'] ? 1 : 0);
