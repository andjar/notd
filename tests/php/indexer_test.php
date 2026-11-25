<?php
require_once __DIR__ . '/support/server.php';

const ID1 = '01900000-0000-7000-8000-00000000000a';

cases('parse_text finds fields, links, tags, references and embeds', [
    'field'            => ['{rating:: 4}', [['rating', '4']], []],
    'key lower case'   => ['{Type::Movie}', [['type', 'Movie']], []],
    'two fields'       => ['{a::1} and {b::two words}', [['a', '1'], ['b', 'two words']], []],
    'empty value'      => ['{a:: }', [], []],
    'tags field'       => ['{tags:: film, [[Science Fiction]], #noir}', [['tags', 'film'], ['tags', 'Science Fiction'], ['tags', 'noir']],
                           [['tag', 'film'], ['tag', 'science fiction'], ['tag', 'noir']]],
    'alias field'      => ['{alias:: NYC, New York}', [['alias', 'NYC'], ['alias', 'New York']], []],
    'link'             => ['see [[Østfold]]', [], [['link', 'østfold']]],
    'link twice'       => ['[[A]] and [[a]]', [], [['link', 'a']]],
    'tag'              => ['#idea and #Ålesund/2026', [], [['tag', 'idea'], ['tag', 'ålesund/2026']]],
    'tag with spaces'  => ['#[[two words]]', [], [['tag', 'two words']]],
    'not a tag'        => ['C# and https://x.org/#anchor and &#39; and a#b', [], []],
    'ref'              => ['((' . ID1 . '))', [], [['ref', ID1]]],
    'ref upper case'   => ['((' . strtoupper(ID1) . '))', [], [['ref', ID1]]],
    'embed block'      => ['{{embed ((' . ID1 . '))}}', [], [['embed', ID1]]],
    'embed page'       => ['{{embed [[Reading List]]}}', [], [['embed', 'reading list']]],
    'inline code'      => ['`[[not]] {a::b}` [[yes]]', [], [['link', 'yes']]],
    'fenced code'      => ["```\n[[not]]\n#no\n```\n[[yes]]", [], [['link', 'yes']]],
    'unclosed fence'   => ["```\n[[not]]", [], []],
    'no newline field' => ["{a::b\n}", [], []],
], function (string $text, array $fields, array $links) {
    $parsed = parse_text($text);
    eq($fields, $parsed['fields'], 'fields');
    eq($links, $parsed['links'], 'links');
});

function idx_page(PDO $pdo, string $title): string
{
    $id = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $id, 'title' => $title]]);
    return $id;
}

test('creating and editing a block updates its index rows', function () {
    $pdo = fresh_db();
    $p = idx_page($pdo, 'Movies');
    $b = new_id();
    Ops::apply($pdo, [['op' => 'create', 'id' => $b, 'parent' => $p, 'text' => 'Dune {rating:: 4.5} [[Villeneuve]] #scifi']]);
    eq([['rating', '4.5', 4.5]], $pdo->query("SELECT key, value, num FROM fields WHERE node_id = '$b'")->fetchAll(PDO::FETCH_NUM));
    eq([['link', 'villeneuve'], ['tag', 'scifi']], $pdo->query("SELECT kind, target FROM links WHERE from_id = '$b' ORDER BY kind")->fetchAll(PDO::FETCH_NUM));
    Ops::apply($pdo, [['op' => 'edit', 'id' => $b, 'text' => 'Dune {rating:: great}']]);
    eq([['rating', 'great', null]], $pdo->query("SELECT key, value, num FROM fields WHERE node_id = '$b'")->fetchAll(PDO::FETCH_NUM));
    eq(0, (int) $pdo->query("SELECT COUNT(*) FROM links WHERE from_id = '$b'")->fetchColumn());
});

test('page text is indexed too, and deleting removes the rows', function () {
    $pdo = fresh_db();
    $p = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'NYC', 'text' => '{alias:: New York}']]);
    eq(['New York'], $pdo->query("SELECT value FROM fields WHERE node_id = '$p'")->fetchAll(PDO::FETCH_COLUMN));
    Ops::apply($pdo, [['op' => 'delete', 'id' => $p]]);
    eq(0, (int) $pdo->query('SELECT COUNT(*) FROM fields')->fetchColumn());
});

test('reindex rebuilds the index from text alone', function () {
    $pdo = fresh_db();
    $p = idx_page($pdo, 'P');
    Ops::apply($pdo, [['op' => 'create', 'parent' => $p, 'text' => '{a::1} [[X]]'], ['op' => 'create', 'parent' => $p, 'text' => '#t']]);
    $before = $pdo->query('SELECT node_id, key, value FROM fields ORDER BY 1, 2')->fetchAll() + $pdo->query('SELECT from_id, kind, target FROM links ORDER BY 1, 2')->fetchAll();
    $pdo->exec('DELETE FROM fields; DELETE FROM links; INSERT INTO links VALUES (\'' . $p . '\', \'link\', \'stale\')');
    eq(3, Indexer::reindex($pdo));
    $after = $pdo->query('SELECT node_id, key, value FROM fields ORDER BY 1, 2')->fetchAll() + $pdo->query('SELECT from_id, kind, target FROM links ORDER BY 1, 2')->fetchAll();
    eq($before, $after);
    eq(0, (int) $pdo->query("SELECT COUNT(*) FROM links WHERE target = 'stale'")->fetchColumn());
});

test('a link inside an ordinary field value is still a link', function () {
    eq([['link', 'villeneuve']], parse_text('{director:: [[Villeneuve]]}')['links']);
});
