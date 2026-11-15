<?php
/**
 * Scenarios for logseq_import.test.php. Each runs in its own process with a
 * fresh database and uploads folder and returns data for the checks.
 */

use App\LogseqImport;

/** Every note's content, a map of note id => content, and more. */
function snapshot(PDO $pdo): array
{
    $notes = $pdo->query('SELECT N.id, N.content, N.collapsed, N.created_at, P.content AS parent FROM Notes N LEFT JOIN Notes P ON P.id = N.parent_note_id')->fetchAll(PDO::FETCH_ASSOC);
    $out = ['notes' => [], 'ids' => [], 'collapsed' => [], 'created' => [], 'children' => []];
    foreach ($notes as $n) {
        $out['notes'][] = $n['content'];
        $out['ids'][$n['id']] = $n['content'];
        if ($n['collapsed']) {
            $out['collapsed'][] = $n['content'];
        }
        if ($n['created_at'] < '2025') {
            $out['created'][$n['content']] = $n['created_at'];
        }
        if ($n['parent'] !== null) {
            $out['children'][$n['content']] = $n['parent'];
        }
    }
    $out['pages'] = $pdo->query('SELECT name, content FROM Pages')->fetchAll(PDO::FETCH_KEY_PAIR);
    $out['attachments'] = $pdo->query('SELECT name, path, type, note_id FROM Attachments')->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}

function counts(PDO $pdo): array
{
    $c = [];
    foreach (['Pages', 'Notes', 'Attachments', 'Properties'] as $t) {
        $c[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    return $c;
}

/** The outline of every page as text, ids left out. */
function outline(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, name FROM Pages ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $page) {
        // Stored file names are random, so a replaced attachment has a new one.
        $out[$page['name']] = preg_replace('/file=[^)]+/', 'file=…', (new App\Markdown($pdo))->exportPage((int)$page['id']));
    }
    return $out;
}

function copy_tree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $target = $to . substr($f->getPathname(), strlen($from));
        $f->isDir() ? @mkdir($target, 0777, true) : copy($f->getPathname(), $target);
    }
}

function count_files(string $dir): int
{
    return is_dir($dir) ? iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS))) : 0;
}

/** A fresh database without the welcome notes, so the imported pages are all there is. */
function clear(PDO $pdo): void
{
    $pdo->exec('DELETE FROM Notes');
    $pdo->exec('DELETE FROM Pages');
}

return [
    'parsing' => function () {
        $c = [];
        $o = LogseqImport::parseOutline("- a\n  ```\n  - b\n  ```\n\t- c\n- d");
        $c['fenced dash lines stay in their block'] = $o['blocks'][0]['text'] === "a\n```\n- b\n```" && $o['blocks'][0]['children'][0]['text'] === 'c';
        $o = LogseqImport::parseOutline("- a\n    - b\n        - c\n    - d");
        $c['space indentation'] = $o['blocks'][0]['children'][0]['children'][0]['text'] === 'c' && $o['blocks'][0]['children'][1]['text'] === 'd';
        $o = LogseqImport::parseOutline("- TODO x\n  SCHEDULED: <2024-01-02 Tue> DEADLINE: <2024-01-05 Fri 10:30>\n  :LOGBOOK:\n  CLOCK: [x]\n  :END:\n  id:: 65000000-0000-4000-8000-000000000001\n  body");
        $b = $o['blocks'][0];
        $c['planning, drawer, id, body'] = $b['text'] === "TODO x\nbody" && $b['planning'] === [['scheduled', '2024-01-02'], ['deadline', '2024-01-05 10:30']] && $b['id'] === '65000000-0000-4000-8000-000000000001';
        $o = LogseqImport::parseOutline("---\ntitle: Front\ntags: a\n---\n- x");
        $c['YAML front matter'] = $o['props'] === [['title', 'Front'], ['tags', 'a']];
        $o = LogseqImport::parseOutline("- a\n  text:: not a property because it comes after\n  more\n  key:: also text");
        $c['properties only directly after the first line'] = $o['blocks'][0]['props'] === [['text', 'not a property because it comes after']] && $o['blocks'][0]['text'] === "a\nmore\nkey:: also text";
        $o = LogseqImport::parseOutline("- a\n\t- b\n## Heading\nid:: 65000000-0000-4000-8000-000000000002\n- c\n  ## not a block");
        $c['an unbulleted heading starts a top-level block'] = count($o['blocks']) === 3 && $o['blocks'][1]['text'] === '## Heading'
            && $o['blocks'][1]['id'] === '65000000-0000-4000-8000-000000000002' && $o['blocks'][2]['text'] === "c\n## not a block";
        $o = LogseqImport::parseOutline("- \n- x");
        $c['empty block'] = $o['blocks'][0]['text'] === '' && $o['blocks'][1]['text'] === 'x';

        $date = static fn($f, $t) => LogseqImport::matchDate(LogseqImport::compileDateFormat($f), $t);
        $c['MMM do, yyyy'] = $date('MMM do, yyyy', 'Nov 1st, 2023') === '2023-11-01' && $date('MMM do, yyyy', 'Sep 22nd, 2021') === '2021-09-22';
        $c['EEEE, MMMM do, yyyy'] = $date('EEEE, MMMM do, yyyy', 'Wednesday, November 1st, 2023') === '2023-11-01';
        $c['dd.MM.yyyy and quoted literals'] = $date('dd.MM.yyyy', '01.11.2023') === '2023-11-01' && $date("yyyy'W'MM", '2023W11') === null;
        $c['yyyy_MM_dd'] = $date('yyyy_MM_dd', '2023_02_29') === null && $date('yyyy_MM_dd', '2024_02_29') === '2024-02-29';
        $c['not a date'] = $date('MMM do, yyyy', 'Project Alpha') === null;
        return ['checks' => $c];
    },

    'fresh' => function ($run, PDO $pdo) {
        clear($pdo);
        $report = $run();
        return ['report' => $report] + snapshot($pdo);
    },

    'options' => function ($run, PDO $pdo) {
        clear($pdo);
        $run(['tags' => 'link', 'resolve_aliases' => true]);
        return snapshot($pdo);
    },

    'rerun' => function ($run, PDO $pdo, string $tmp, string $fixture) {
        $run();
        $before = counts($pdo);
        $second = $run();
        $after = counts($pdo);
        $files = count_files("$tmp/uploads");

        // Edit a copy: one block changed, one added.
        copy_tree($fixture, "$tmp/graph");
        $file = "$tmp/graph/pages/Projects___Alpha___Notes.md";
        file_put_contents($file, "- Child page of namespace, edited\n- links to [[PA]] alias\n- a new block\n");
        $edited = $run([], "$tmp/graph");
        $welcome = (int)$pdo->query("SELECT COUNT(*) FROM Notes WHERE content LIKE '## Welcome to notd%'")->fetchColumn();
        return ['second' => $second, 'counts_before' => $before, 'counts_after' => $after, 'files' => $files, 'edited' => $edited, 'welcome_kept' => $welcome === 1];
    },

    'replace' => function ($run, PDO $pdo, string $tmp, string $fixture) {
        clear($pdo);
        $run();
        $first = outline($pdo);
        $report = $run(['mode' => 'replace']);
        $same = outline($pdo) === $first;
        $files = count_files("$tmp/uploads");

        copy_tree($fixture, "$tmp/graph");
        file_put_contents("$tmp/graph/pages/windows.md", "- edited\n");
        $run(['mode' => 'replace'], "$tmp/graph");
        $id = (int)$pdo->query("SELECT id FROM Pages WHERE name = 'windows'")->fetchColumn();
        $page = array_values(array_filter(explode("\n", (new App\Markdown($pdo))->exportPage($id))));
        return ['report' => $report, 'same_outline' => $same, 'files' => $files, 'edited_page' => $page];
    },

    'dry' => function ($run, PDO $pdo) {
        $before = counts($pdo);
        $report = $run(['dry_run' => true]);
        return ['report' => $report, 'changed' => counts($pdo) !== $before];
    },

    'failure' => function ($run, PDO $pdo) {
        clear($pdo);
        $report = $run();
        $failed = (int)$pdo->query("SELECT COUNT(*) FROM Pages WHERE name = '2023-11-14'")->fetchColumn();
        return ['report' => $report, 'has_failed_page' => $failed > 0, 'other_pages' => (int)$pdo->query('SELECT COUNT(*) FROM Pages')->fetchColumn()];
    },

    'strict' => function ($run, PDO $pdo) {
        clear($pdo);
        $threw = false;
        try {
            $run(['strict' => true]);
        } catch (Throwable $e) {
            $threw = true;
        }
        return ['threw' => $threw, 'pages' => (int)$pdo->query('SELECT COUNT(*) FROM Pages')->fetchColumn()];
    },
];
