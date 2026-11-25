<?php
// Full-text search over titles and text with SQLite FTS5.
//
//   kitchen tiles      notes with words starting with "kitchen" and "tiles"
//   "kitchen tiles"    the exact phrase
//   tiles -bathroom    "tiles" but not "bathroom"

declare(strict_types=1);

const SEARCH_LIMIT_MAX = 200;

final class Search
{
    /** Keeps the search row of a node in line with it (an Ops listener). */
    public static function onChange(PDO $pdo, string $op, string $id): void
    {
        if ($op === 'create' || $op === 'edit' || $op === 'delete') {
            self::index($pdo, $id, $op);
        }
    }

    public static function index(PDO $pdo, string $id, string $op = 'edit'): void
    {
        $pdo->prepare('DELETE FROM search WHERE rowid = (SELECT n FROM nodes WHERE id = ?)')->execute([$id]);
        if ($op !== 'delete') {
            $pdo->prepare("INSERT INTO search (rowid, node_id, title, text) SELECT n, id, COALESCE(title, ''), text FROM nodes
                WHERE id = ? AND (text <> '' OR title IS NOT NULL)")->execute([$id]);
        }
    }

    public static function rebuild(PDO $pdo): void
    {
        $pdo->exec('DELETE FROM search');
        $pdo->exec("INSERT INTO search (rowid, node_id, title, text) SELECT n, id, COALESCE(title, ''), text FROM nodes WHERE text <> '' OR title IS NOT NULL");
    }

    /** Turns what a person typed into an FTS5 query; null when nothing is left to search for. */
    public static function ftsQuery(string $q): ?string
    {
        preg_match_all('/(-?)(?:"([^"]*)"|(\S+))/u', $q, $m, PREG_SET_ORDER);
        $want = [];
        $not = [];
        foreach ($m as $part) {
            $phrase = ($part[2] ?? '') !== '';
            $words = preg_split('/[^\p{L}\p{N}_]+/u', $phrase ? $part[2] : ($part[3] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            if (!$words) {
                continue;
            }
            $term = $phrase ? '"' . implode(' ', $words) . '"' : implode(' ', array_map(fn($w) => '"' . $w . '"*', $words));
            if ($part[1] === '-') {
                $not[] = $term;
            } else {
                $want[] = $term;
            }
        }
        if (!$want) {
            return null;
        }
        return implode(' ', $want) . implode('', array_map(fn($t) => " NOT $t", $not));
    }

    /** Matches, best first: the node, its page's title, and an HTML-safe snippet with <mark>s. */
    public static function query(PDO $pdo, string $q, int $limit = 50, int $offset = 0): array
    {
        $fts = self::ftsQuery($q);
        if ($fts === null) {
            return [];
        }
        $limit = max(1, min($limit, SEARCH_LIMIT_MAX));
        $st = $pdo->prepare("SELECT n.*, p.title AS page_title,
                snippet(search, 2, char(57344), char(57345), '…', 16) AS snip,
                snippet(search, 1, char(57344), char(57345), '…', 16) AS title_snip
            FROM search JOIN nodes n ON n.id = search.node_id LEFT JOIN nodes p ON p.id = n.page_id
            WHERE search MATCH ? ORDER BY bm25(search, 0, 10.0, 1.0), n.updated DESC LIMIT ? OFFSET ?");
        $st->execute([$fts, $limit, max(0, $offset)]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $snip = str_contains($row['snip'], "\u{E000}") || $row['title_snip'] === '' ? $row['snip'] : $row['title_snip'];
            $out[] = ['node' => Nodes::shape($row), 'pageTitle' => $row['page_title'],
                'snippet' => str_replace(["\u{E000}", "\u{E001}"], ['<mark>', '</mark>'], htmlspecialchars($snip, ENT_QUOTES))];
        }
        return $out;
    }
}
