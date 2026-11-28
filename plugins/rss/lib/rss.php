<?php
// Feeds in notes. A block with a {feed:: https://...} field is a feed: its new
// items are added under it, newest first, each once. What has been seen is
// kept per feed in the notebook (meta "rss:<block id>"), so an item you delete
// does not come back. The first read adds only the newest few.
//
// Each item says when it was published ({published:: 2026-11-28 14:05}), so it
// can be shown by day or by hour: that is how the feed is shown (a {view:: …}
// on the feed block, see the guide), not how the items are kept.

declare(strict_types=1);

require_once __DIR__ . '/feed.php';

final class Rss
{
    public const FIRST = 20;       // items added the first time a feed is read
    public const MAX = 50;         // items added in one read
    public const SEEN = 1000;      // item ids remembered per feed
    public const SUMMARY = 280;    // characters of an item's text kept

    /** Seconds between reads (rss_every in the config, in minutes; default 60). */
    public static function every(): int
    {
        return max(5, (int) Config::get('rss_every', '60')) * 60;
    }

    /** The feeds in the notebook: [block id => address], oldest block first. */
    public static function feeds(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT f.node_id, f.value FROM fields f JOIN nodes n ON n.id = f.node_id WHERE f.key = 'feed' ORDER BY n.created") as $r) {
            $out[$r['node_id']] ??= trim($r['value']);
        }
        return $out;
    }

    /**
     * Reads the feeds that are due (all with $force; one block's with $only) and adds their new items.
     * Returns a list of ['id', 'url', 'title', 'added', 'error'] for the feeds read.
     */
    public static function refresh(PDO $pdo, bool $force = false, ?string $only = null, ?callable $get = null): array
    {
        $get ??= [FeedReader::class, 'get'];
        $results = [];
        foreach (self::feeds($pdo) as $id => $url) {
            if ($only !== null && $id !== $only) {
                continue;
            }
            $state = self::state($pdo, $id);
            if (!$force && $state['url'] === $url && $state['fetched'] > now_ms() - (self::every() - 60) * 1000) {
                continue;
            }
            try {
                $feed = FeedReader::parse($get($url));
                $added = self::add($pdo, $id, $url, $feed, $state);
                $results[] = ['id' => $id, 'url' => $url, 'title' => $feed['title'], 'added' => $added, 'error' => null];
            } catch (Throwable $e) {
                self::save($pdo, $id, ['url' => $url, 'fetched' => now_ms(), 'error' => $e->getMessage()] + $state);
                $results[] = ['id' => $id, 'url' => $url, 'title' => $state['title'], 'added' => 0, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /** Adds the items not seen before under the feed's block, newest first. Returns how many. */
    private static function add(PDO $pdo, string $feedId, string $url, array $feed, array $state): int
    {
        $first = $state['url'] !== $url;
        $seen = array_flip($state['seen']);
        $new = [];
        foreach ($feed['items'] as $item) {
            $id = self::id($feedId, $item['id']);
            if (!isset($seen[$id]) && !isset($new[$id])) {
                $new[$id] = $item;
            }
        }
        uasort($new, fn($a, $b) => strcmp($b['published'], $a['published']));     // stable: undated items keep the feed's order
        $new = array_slice($new, 0, $first ? self::FIRST : self::MAX, true);
        $new = array_filter($new, fn($id) => Nodes::get($pdo, $id) === null, ARRAY_FILTER_USE_KEY);
        if ($new) {
            $st = $pdo->prepare('SELECT MIN(sort_key) FROM nodes WHERE parent_id = ?');
            $st->execute([$feedId]);
            $keys = SortKeys::nBetween(null, $st->fetchColumn() ?: null, count($new));
            $ops = [];
            foreach (array_keys($new) as $i => $id) {
                $ops[] = ['op' => 'create', 'id' => $id, 'parent' => $feedId, 'sort' => $keys[$i], 'text' => self::text($new[$id])];
            }
            Ops::apply($pdo, $ops);
        }
        $ids = array_map(fn($item) => self::id($feedId, $item['id']), $feed['items']);
        self::save($pdo, $feedId, ['url' => $url, 'fetched' => now_ms(), 'title' => $feed['title'], 'error' => null,
            'seen' => array_slice(array_values(array_unique([...$ids, ...$state['seen']])), 0, self::SEEN)]);
        return count($new);
    }

    /** An item as a block: "[Title](link) {published:: 2026-11-27 14:05}" (when read, for an item without a date), then the start of its text. */
    public static function text(array $item): string
    {
        $title = trim(str_replace(['[', ']', '{', '}'], ['(', ')', '(', ')'], $item['title'])) ?: $item['link'] ?: '(no title)';
        $line = $item['link'] !== '' ? '[' . $title . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $item['link']) . ')' : "Item: $title";
        $line .= ' {published:: ' . ($item['published'] ?: date('Y-m-d H:i')) . '}';
        $summary = str_replace(['{', '}'], ['(', ')'], $item['summary']);
        if ($summary !== '' && $summary !== $item['title']) {
            if (mb_strlen($summary) > self::SUMMARY) {
                $summary = rtrim(preg_replace('/\s+\S*$/u', '', mb_substr($summary, 0, self::SUMMARY)) ?? '', ' ,.;:') . '…';
            }
            $line .= "\n" . $summary;
        }
        return $line;
    }

    /** The same id every time for the same item of the same feed block, in the UUID layout (version 8). */
    public static function id(string $feedId, string $guid): string
    {
        $h = sha1("rss\0$feedId\0$guid");
        $h[12] = '8';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /** What is known about a feed: ['url', 'fetched' (ms), 'title', 'error', 'seen' => ids]. */
    public static function state(PDO $pdo, string $feedId): array
    {
        $st = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $st->execute(["rss:$feedId"]);
        $s = json_decode((string) $st->fetchColumn(), true);
        return (is_array($s) ? $s : []) + ['url' => null, 'fetched' => 0, 'title' => '', 'error' => null, 'seen' => []];
    }

    private static function save(PDO $pdo, string $feedId, array $state): void
    {
        Db::write($pdo, fn(PDO $pdo) => $pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')
            ->execute(["rss:$feedId", json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]));
    }

    /** After a change: a new or changed feed is read straight away; otherwise make sure reading goes on. */
    public static function changed(PDO $pdo, string $op, string $id): void
    {
        if ($op !== 'delete') {
            $st = $pdo->prepare('SELECT text FROM nodes WHERE id = ?');
            $st->execute([$id]);
            if (stripos((string) $st->fetchColumn(), '{feed::') !== false) {
                Jobs::queue($pdo, 'rss.fetch', [], 'rss.fetch');
                return;
            }
        }
        $waiting = $pdo->query("SELECT 1 FROM jobs WHERE key = 'rss.fetch' AND status IN ('waiting', 'running') LIMIT 1")->fetchColumn();
        if (!$waiting && $pdo->query("SELECT 1 FROM fields WHERE key = 'feed' LIMIT 1")->fetchColumn()) {
            Jobs::queue($pdo, 'rss.fetch', [], 'rss.fetch', 60);
        }
    }

    /** The job: read what is due, then come back later while there are feeds. */
    public static function job(): string
    {
        $pdo = Db::get();
        $results = self::refresh($pdo);
        if (self::feeds($pdo)) {
            Jobs::queue($pdo, 'rss.fetch', [], 'rss.fetch', self::every());
        }
        return implode('; ', array_map(fn($r) => $r['url'] . ': ' . ($r['error'] ?? "{$r['added']} new"), $results)) ?: 'No feed was due.';
    }
}
