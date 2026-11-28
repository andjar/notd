<?php
require_once dirname(__DIR__, 3) . '/tests/php/support/server.php';
require_once dirname(__DIR__) . '/lib/rss.php';

const FEEDS = __DIR__ . '/fixtures';
date_default_timezone_set('UTC');      // times below are read on this clock

test('RSS 2.0, Atom and RSS 1.0 are read, with dates, links and plain text', function () {
    $r = FeedReader::parse(file_get_contents(FEEDS . '/rss2.xml'));
    eq('Garden & Kitchen', $r['title']);
    eq(['post-3', 'post-2', 'post-1'], array_column($r['items'], 'id'));
    eq(['title' => 'Sowing [peas] in November', 'link' => 'https://example.org/peas', 'published' => '2026-11-25 08:00', 'summary' => 'Peas can go in now, under glass.'],
        array_diff_key($r['items'][0], ['id' => 1]));
    eq('Jam with {curly} bits.', $r['items'][1]['summary']);

    $a = FeedReader::parse(file_get_contents(FEEDS . '/atom.xml'));
    eq(['tag:example.net,2026:keys', 'Fractional keys', 'https://example.net/keys', '2026-11-26 10:00', 'How sort keys work.'], array_values($a['items'][0]));
    $d = FeedReader::parse(file_get_contents(FEEDS . '/rdf.xml'));
    eq(['Old school', 'https://example.com/one', '2026-11-24 11:00'], [$d['title'], $d['items'][0]['id'], $d['items'][0]['published']]);
    date_default_timezone_set('Europe/Oslo');
    eq('2026-11-25 09:00', FeedReader::parse(file_get_contents(FEEDS . '/rss2.xml'))['items'][0]['published'], 'on the server\'s clock');
    date_default_timezone_set('UTC');

    foreach ([file_get_contents(FEEDS . '/page.html'), '', 'not xml'] as $body) {
        throws(fn() => FeedReader::parse($body), RuntimeException::class, 'not an RSS or Atom feed');
    }
    throws(fn() => FeedReader::get('file:///etc/passwd'), RuntimeException::class, 'starts with http:// or https://');
});

/** A notebook with one feed block, and a fetcher that serves the fixtures. */
function feed_setup(): array
{
    $pdo = fresh_db();
    $page = new_id();
    $feed = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $page, 'title' => 'Feeds'],
        ['op' => 'create', 'id' => $feed, 'parent' => $page, 'text' => 'Garden {feed:: https://example.org/feed.xml}'],
        ['op' => 'create', 'parent' => $feed, 'text' => 'My own note under the feed']]);
    $files = new ArrayObject(['https://example.org/feed.xml' => 'rss2.xml']);
    $get = function (string $url) use ($files): string {
        if (!isset($files[$url])) {
            throw new RuntimeException('The server answered 404.');
        }
        return file_get_contents(FEEDS . '/' . $files[$url]);
    };
    return [$pdo, $feed, $get, $files];
}

$children = fn(PDO $pdo, string $id) => array_column(Nodes::children($pdo, $id), 'text');

test('new items go under the feed, newest first, each once, and a deleted one stays away', function () use ($children) {
    [$pdo, $feed, $get] = feed_setup();
    eq([$feed => 'https://example.org/feed.xml'], Rss::feeds($pdo));
    $r = Rss::refresh($pdo, false, null, $get);
    eq([['id' => $feed, 'url' => 'https://example.org/feed.xml', 'title' => 'Garden & Kitchen', 'added' => 3, 'error' => null]], $r);
    $texts = $children($pdo, $feed);
    eq("[Sowing (peas) in November](https://example.org/peas) {published:: 2026-11-25 08:00}\nPeas can go in now, under glass.", $texts[0]);
    eq("[Blåbærsyltetøy](https://example.org/jam) {published:: 2026-11-23 08:00}\nJam with (curly) bits.", $texts[1]);
    eq('[Older](https://example.org/older) {published:: 2026-11-20 08:00}', $texts[2]);
    eq('My own note under the feed', $texts[3]);
    eq(Rss::id($feed, 'post-3'), Nodes::children($pdo, $feed)[0]['id']);

    eq([], Rss::refresh($pdo, false, null, $get), 'not due again for an hour');
    eq(0, Rss::refresh($pdo, true, null, $get)[0]['added'], 'nothing new');
    Ops::apply($pdo, [['op' => 'delete', 'id' => Rss::id($feed, 'post-2')]]);
    eq(0, Rss::refresh($pdo, true, null, $get)[0]['added'], 'a deleted item is not added again');
    eq(3, count($children($pdo, $feed)));

    $q = Query::run($pdo, 'published>=2026-11-23');
    eq(['Sowing (peas) in November'], array_map(fn($n) => preg_replace('/^\[([^\]]+)\].*$/s', '$1', $n['text']), $q['nodes']));
});

test('a feed that cannot be read says why; a new address is read at once', function () use ($children) {
    [$pdo, $feed, $get, $files] = feed_setup();
    Ops::apply($pdo, [['op' => 'edit', 'id' => $feed, 'text' => 'Garden {feed:: https://example.org/gone.xml}']]);
    $r = Rss::refresh($pdo, false, null, $get);
    eq('The server answered 404.', $r[0]['error']);
    eq('The server answered 404.', Rss::state($pdo, $feed)['error']);
    eq(1, count($children($pdo, $feed)));
    $files['https://example.org/atom.xml'] = 'atom.xml';
    Ops::apply($pdo, [['op' => 'edit', 'id' => $feed, 'text' => 'Garden {feed:: https://example.org/atom.xml}']]);
    eq(1, Rss::refresh($pdo, false, null, $get)[0]['added'], 'a changed address is due at once');
    eq(null, Rss::state($pdo, $feed)['error']);
});

test('the first read adds only the newest items; reading goes on through a job', function () {
    [$pdo, $feed, $get] = feed_setup();
    $many = '<rss><channel><title>Many</title>';
    for ($i = 1; $i <= 30; $i++) {
        $many .= "<item><title>Item $i</title><link>https://example.org/$i</link><pubDate>" . date('r', strtotime("2026-10-01 +$i days")) . '</pubDate></item>';
    }
    $r = Rss::refresh($pdo, true, null, fn() => $many . '</channel></rss>');
    eq(Rss::FIRST, $r[0]['added']);
    eq('[Item 30](https://example.org/30) {published:: 2026-10-31 00:00}', Nodes::children($pdo, $feed)[0]['text']);

    $jobs = fn() => $pdo->query("SELECT kind, status, run_after > " . now_ms() . " AS later FROM jobs WHERE key = 'rss.fetch'")->fetchAll(PDO::FETCH_NUM);
    Rss::changed($pdo, 'edit', $feed);                      // the feed block changed: read now
    eq([['rss.fetch', 'waiting', 0]], $jobs());
    $pdo->exec("DELETE FROM jobs");
    Rss::changed($pdo, 'edit', Nodes::children($pdo, $feed)[0]['id']);   // any other change: make sure reading goes on
    eq([['rss.fetch', 'waiting', 1]], $jobs());
});

test('an item without a link or a title, and a long text, still make a tidy block', function () {
    eq("Item: Notice {published:: 2026-11-27 10:30}\nShort.", Rss::text(['title' => 'Notice', 'link' => '', 'published' => '2026-11-27 10:30', 'summary' => 'Short.']));
    eq('[https://x.org/a b](https://x.org/a%20b) {published:: ' . date('Y-m-d H:i') . '}', Rss::text(['title' => '', 'link' => 'https://x.org/a b', 'published' => '', 'summary' => '']));
    $long = Rss::text(['title' => 'T', 'link' => 'https://x.org', 'published' => '', 'summary' => str_repeat('word ', 100)]);
    ok(mb_strlen(explode("\n", $long)[1]) <= Rss::SUMMARY + 1 && str_ends_with($long, 'word…'), $long);
});
