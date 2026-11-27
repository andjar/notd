<?php
require_once __DIR__ . '/support/server.php';
require_once dirname(__DIR__, 2) . '/tools/lib/import.php';

const GRAPH = __DIR__ . '/../fixtures/logseq-graph';

function page_md(PDO $pdo, string $title): string
{
    $r = Read::page($pdo, $title);
    return Markdown::page($r['page'], $r['blocks']);
}

test('a notd export imports into an empty notebook and exports to the same bytes', function () {
    $pdo = fresh_db();
    $p = new_id();
    $a = new_id();
    $tmp = tempnam(sys_get_temp_dir(), 'notd');
    file_put_contents($tmp, 'attached');
    $file = Files::store($pdo, $tmp, 'a.txt');
    Ops::apply($pdo, [
        ['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'Ålesund: notes?', 'text' => "{alias:: Aalesund}\n- dash line"],
        ['op' => 'create', 'id' => $a, 'parent' => $p, 'text' => "first\nsecond line\n\n```\n- not a bullet\n```"],
        ['op' => 'create', 'parent' => $a, 'text' => 'child', 'collapsed' => true],
        ['op' => 'create', 'parent' => $p, 'text' => "ref (({$a})) and [file](file:{$file['id']})"],
        ['op' => 'create', 'kind' => 'page', 'title' => '2026-11-25'],
    ]);
    $j = Nodes::byTitle($pdo, '2026-11-25')['id'];
    Ops::apply($pdo, [['op' => 'create', 'parent' => $j, 'text' => 'TODO journal entry [[Ålesund: notes?]]']]);
    $first = sys_get_temp_dir() . '/notd-rt1-' . bin2hex(random_bytes(4));
    $second = sys_get_temp_dir() . '/notd-rt2-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => [remove_tree($first), remove_tree($second)]);
    Markdown::export($pdo, $first);

    $fresh = fresh_db();
    $s = Import::folder($fresh, $first);
    eq([2, 4, 1, []], [$s['pages'], $s['blocks'], $s['files'], $s['warnings']]);
    Markdown::export($fresh, $second);
    $list = fn($d) => array_map(fn($f) => substr($f, strlen($d)), array_merge(glob("$d/*/*.md"), glob("$d/files/*/*/*")));
    eq($list($first), $list($second));
    foreach ($list($first) as $rel) {
        eq(file_get_contents($first . $rel), file_get_contents($second . $rel), $rel);
    }
    eq($a, Read::node($fresh, $a)['node']['id'], 'referenced ids are kept');
});

test('a Logseq graph imports: titles, namespaces, journals, properties, tasks', function () {
    $pdo = fresh_db();
    $s = Import::folder($pdo, GRAPH);
    $titles = array_column(Read::pages($pdo), 'title');
    foreach (['2023-11-13', '2023-11-14', 'Project Alpha', 'Projects/Alpha/Notes', 'Questions: what?', 'Weird (x) name', 'Ola Nordmann', 'windows', 'hand written'] as $t) {
        ok(in_array($t, $titles, true), "page $t in " . implode(', ', $titles));
    }
    ok(!in_array('ignored', $titles, true), 'logseq/bak is skipped');
    $alpha = Read::page($pdo, 'pa');
    eq('Project Alpha', $alpha['page']['title'], 'found by alias');
    eq("{alias:: Alpha, PA}\n{tags:: project, [[Big Topic]]}", $alpha['page']['text']);
    eq('## Overview with heading', $alpha['blocks'][0]['text']);
    ok(str_contains($alpha['blocks'][1]['text'], "```python\n- this is not a bullet\n# neither is this #notatag\n```\nafter the fence"), $alpha['blocks'][1]['text']);
    eq('real child after code', $alpha['blocks'][2]['text']);
    eq("{status:: active}\n{owner:: [[Ola Nordmann]]}\nBody line with key-looking text: not:: a property", $alpha['blocks'][4]['text']);
    eq("{type:: person}\n{role:: tester}", Read::page($pdo, 'Ola Nordmann')['page']['text'], 'first block of properties becomes page text');
    ok(str_contains(json_encode($s['warnings'], JSON_UNESCAPED_UNICODE), 'orgpage.org'), 'org files reported');
});

test('Logseq journals: task words, schedules, logbooks, ids, collapsed, journal links, assets', function () {
    $pdo = fresh_db();
    Import::folder($pdo, GRAPH);
    $j = Read::page($pdo, '2023-11-14')['blocks'];
    eq("TODO Write the report {priority:: A}\n{scheduled:: 2023-11-14}", $j[0]['text']);
    eq('6553a1b2-0000-4000-8000-000000000001', $j[0]['id']);
    ok(str_starts_with($j[1]['text'], "DOING Reviewing [[Project Alpha]]") && str_contains($j[1]['text'], '{deadline:: 2023-11-20}'), $j[1]['text']);
    eq(['DONE Shipped it', 'CANCELLED Old idea', 'WAITING On hold'], [$j[2]['text'], $j[3]['text'], $j[4]['text']]);
    ok(preg_match('/^Screenshot !\[image\.png\]\(file:[0-9a-f-]{36}\)$/', $j[5]['text']) === 1, $j[5]['text']);
    eq(substr($j[5]['text'], -37), substr($j[6]['text'], -37), 'the same image is one file');
    ok(str_contains($j[7]['text'], '](file:') && !str_contains($j[7]['text'], 'assets/'), $j[7]['text']);
    ok(str_contains($j[8]['text'], '../assets/nope.png'), 'a missing file keeps its link');
    ok(preg_match('/^Drawing \[2023-11-01-10-00-00\.excalidraw\]\(file:[0-9a-f-]{36}\)$/', $j[9]['text']) === 1, $j[9]['text']);
    $links = end($j);
    $prev = $j[count($j) - 3];
    eq('Link to yesterday [[2023-11-13]] and a page with brackets [[Weird (x) name]]', $prev['text']);
    $collapsed = $j[count($j) - 2];
    eq(['collapsed parent', true], [$collapsed['text'], $collapsed['collapsed']]);
    eq('hidden child', $links['text']);
    eq([['page' => Read::page($pdo, '2023-11-13')['page']['id']]], array_map(fn($g) => ['page' => $g['page']['id']], Read::backlinks($pdo, '6553a1b2-0000-4000-8000-000000000001')));
});

test('BOM, CRLF and tab indents; duplicate ids get a new id; plain text before bullets', function () {
    $pdo = fresh_db();
    $s = Import::folder($pdo, GRAPH);
    eq(['BOM and CRLF file', 'child with CRLF'], array_column(Read::page($pdo, 'windows')['blocks'], 'text'));
    eq(2, count(Read::page($pdo, 'dupes')['blocks']));
    ok(str_contains(implode("\n", $s['warnings']), 'used twice'), 'duplicate reported');
    $hw = Read::page($pdo, 'hand written');
    eq('Some text before any bullet', $hw['page']['text']);
    eq(['star bullets only', 'nested star'], array_column($hw['blocks'], 'text'));
    eq($hw['blocks'][0]['id'], $hw['blocks'][1]['parent']);
});

test('importing into an existing page adds the blocks at the end', function () {
    $pdo = fresh_db();
    $p = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'windows'], ['op' => 'create', 'parent' => $p, 'text' => 'mine']]);
    Import::folder($pdo, GRAPH);
    eq(['mine', 'BOM and CRLF file', 'child with CRLF'], array_column(Read::page($pdo, 'windows')['blocks'], 'text'));
});

test('importing the same graph again adds nothing, keeps edits made since, and adds only what is new', function () {
    $pdo = fresh_db();
    $first = Import::folder($pdo, GRAPH);
    $before = page_md($pdo, 'Project Alpha');
    $edited = Read::page($pdo, 'hand written')['blocks'][0];
    Ops::apply($pdo, [['op' => 'edit', 'id' => $edited['id'], 'text' => 'changed in notd']]);
    $again = Import::folder($pdo, GRAPH);
    eq(0, $again['blocks'], 'no block twice');
    eq($before, page_md($pdo, 'Project Alpha'));
    eq('changed in notd', Read::node($pdo, $edited['id'])['node']['text'], 'an edit made in notd stays');
    eq(1, substr_count(implode("\n", array_column(Read::page($pdo, 'notd/favourites')['blocks'], 'text')), '[[Project Alpha]]'), 'favourite once');
    ok($first['blocks'] > 30, (string) $first['blocks']);

    $copy = sys_get_temp_dir() . '/notd-graph-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => remove_tree($copy));
    copy_tree(GRAPH, $copy);
    file_put_contents("$copy/pages/hand written.md", "\n- a block written in Logseq later", FILE_APPEND);
    $third = Import::folder($pdo, $copy);
    eq(1, $third['blocks']);
    eq('a block written in Logseq later', end(Read::page($pdo, 'hand written')['blocks'])['text']);
});

test('Logseq extras: favourites, quotes, legacy file names, other date formats, queries, files outside the graph', function () {
    $pdo = fresh_db();
    Import::folder($pdo, GRAPH);
    eq(['[[Project Alpha]]'], array_column(Read::page($pdo, 'notd/favourites')['blocks'], 'text'), 'favourites from config.edn');
    ok(in_array('> Quoted text', array_column(Read::page($pdo, 'Project Alpha')['blocks'], 'text'), true), 'a #+BEGIN_QUOTE block is a quote');

    $g = sys_get_temp_dir() . '/notd-graph-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => remove_tree($g));
    mkdir("$g/logseq", 0777, true);
    mkdir("$g/journals");
    mkdir("$g/pages");
    file_put_contents("$g/logseq/config.edn", "{:journal/page-title-format \"dd.MM.yyyy\" ;; Norwegian\n :journal/file-name-format \"yyyy-MM-dd\"\n :favorites []}");
    file_put_contents("$g/journals/2024-01-05.md", "- TODO [#B] Ring [[06.01.2024]]\n- {{query (task TODO)}}\n- #+BEGIN_SRC php\n  echo 1;\n  #+END_SRC\n- outside [x](../../secret.txt)");
    file_put_contents("$g/pages/Projects.Alpha.md", "- legacy name\n  logseq.order-list-type:: number");
    file_put_contents(dirname($g) . '/secret.txt', 'not for notd');
    $s = Import::folder($pdo, $g);
    $day = Read::page($pdo, '2024-01-05')['blocks'];
    eq('TODO Ring [[2024-01-06]] {priority:: B}', $day[0]['text']);
    eq('`{{query (task TODO)}}`', $day[1]['text']);
    eq("```php\necho 1;\n```", $day[2]['text']);
    eq('outside [x](../../secret.txt)', $day[3]['text'], 'nothing outside the graph is read');
    eq(['legacy name'], array_column(Read::page($pdo, 'Projects/Alpha')['blocks'], 'text'), 'A.B.md is A/B in a legacy graph');
    ok(str_contains(implode("\n", $s['warnings']), 'Logseq query'), 'queries reported');
});
