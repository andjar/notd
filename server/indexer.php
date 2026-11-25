<?php
// The indexer: reads fields, links, tags, references and embeds out of a node's
// text into the fields and links tables. The text stays the truth; reindex()
// rebuilds everything from it.
//
//   {key::value}          a field (key in lower case); tags and alias hold lists: {tags:: a, [[b c]]}
//   [[Page]]              a link to a page, by title
//   #tag  #[[two words]]  a tag, which is also a link to the page of that name
//   ((id))                a reference to a block
//   {{embed ((id))}}      an embedded block; {{embed [[Page]]}} an embedded page
//   ![name](file:id)      a stored file, shown or linked (also [name](file:id))
//
// Nothing inside `code` or ``` fenced code ``` counts.

declare(strict_types=1);

const LIST_FIELDS = ['tags', 'alias'];
const UUID_RE = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

/** @return array{fields: list<array{0: string, 1: string}>, links: list<array{0: string, 1: string}>} */
function parse_text(string $text): array
{
    $text = preg_replace(['/^(```|~~~)[^\n]*\n.*?(^\1[ \t]*$|\z)/ms', '/(`+)(?!`).*?(?<!`)\1(?!`)/s'], ' ', $text) ?? $text;
    $fields = [];
    $links = [];
    $add = function (string $kind, string $target) use (&$links): void {
        $target = in_array($kind, ['ref', 'embed-block', 'file'], true) ? strtolower($target) : title_key($target);
        if ($target !== '') {
            $links[$kind . "\0" . $target] = [$kind === 'embed-block' ? 'embed' : $kind, $target];
        }
    };

    preg_match_all('/\{([A-Za-z0-9_.-]+)::([^}\n]*)\}/u', $text, $m, PREG_SET_ORDER);
    foreach ($m as [, $key, $value]) {
        $key = strtolower($key);
        $values = in_array($key, LIST_FIELDS, true) ? split_list($value) : [trim($value)];
        foreach ($values as $v) {
            if ($v === '') {
                continue;
            }
            $fields[] = [$key, $v];
            if ($key === 'tags') {
                $add('tag', $v);
            }
        }
    }
    $text = preg_replace('/\{(?:tags|alias)::[^}\n]*\}/iu', ' ', $text) ?? $text; // their items are not also links
    preg_match_all('/\{\{embed\s+\(\((' . UUID_RE . ')\)\)\s*\}\}/i', $text, $m);
    foreach ($m[1] as $id) {
        $add('embed-block', $id);
    }
    preg_match_all('/\{\{embed\s+\[\[([^\]\n]+)\]\]\s*\}\}/u', $text, $m);
    foreach ($m[1] as $title) {
        $add('embed', $title);
    }
    $text = preg_replace('/\{\{embed\s[^}]*\}\}/u', ' ', $text) ?? $text;
    preg_match_all('/(?<![\w#&\/])#(?:\[\[([^\]\n]+)\]\]|([\p{L}\p{N}_][\p{L}\p{N}_\/-]*))/u', $text, $m, PREG_SET_ORDER);
    foreach ($m as $match) {
        $add('tag', ($match[1] ?? '') !== '' ? $match[1] : $match[2]);
    }
    preg_match_all('/(?<!#)\[\[([^\]\n]+)\]\]/u', $text, $m);
    foreach ($m[1] as $title) {
        $add('link', $title);
    }
    preg_match_all('/\]\(file:(' . UUID_RE . ')\)/i', $text, $m);
    foreach ($m[1] as $id) {
        $add('file', $id);
    }
    preg_match_all('/\(\((' . UUID_RE . ')\)\)/i', $text, $m);
    foreach ($m[1] as $id) {
        $add('ref', $id);
    }
    return ['fields' => $fields, 'links' => array_values($links)];
}

/** "a, [[b c]], #d" => ['a', 'b c', 'd'] */
function split_list(string $value): array
{
    $out = [];
    foreach (explode(',', $value) as $item) {
        $item = trim($item);
        if (preg_match('/^#?\[\[(.*)\]\]$/su', $item, $mm)) {
            $item = $mm[1];
        }
        $item = trim(ltrim($item, '#'));
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

final class Indexer
{
    /** Plugins' indexers: fn(string $text, array $node): list of [key, value] fields to add. */
    public static array $extra = [];

    /** Rebuilds the index rows of one node from its text (and a page's title is not indexed here). */
    public static function node(PDO $pdo, string $id): void
    {
        $pdo->prepare('DELETE FROM fields WHERE node_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM links WHERE from_id = ?')->execute([$id]);
        $st = $pdo->prepare('SELECT text FROM nodes WHERE id = ?');
        $st->execute([$id]);
        $text = $st->fetchColumn();
        if (!is_string($text) || $text === '') {
            return;
        }
        $parsed = parse_text($text);
        foreach (self::$extra as $name => $fn) {
            try {
                foreach ($fn($text) as [$key, $value]) {
                    $parsed['fields'][] = [strtolower((string) $key), (string) $value];
                }
            } catch (Throwable $e) {
                error_log("notd: indexer $name: " . $e->getMessage());
            }
        }
        $field = $pdo->prepare('INSERT INTO fields (node_id, key, value, value_key, num) VALUES (?, ?, ?, ?, ?)');
        foreach ($parsed['fields'] as [$key, $value]) {
            $field->execute([$id, $key, $value, title_key($value), is_numeric($value) ? (float) $value : null]);
        }
        $link = $pdo->prepare('INSERT INTO links (from_id, kind, target) VALUES (?, ?, ?)');
        foreach ($parsed['links'] as [$kind, $target]) {
            $link->execute([$id, $kind, $target]);
        }
    }

    /** Rebuilds the whole index; returns the number of nodes read. */
    public static function reindex(PDO $pdo): int
    {
        return Db::write($pdo, function (PDO $pdo): int {
            $pdo->exec('DELETE FROM fields');
            $pdo->exec('DELETE FROM links');
            $ids = $pdo->query("SELECT id FROM nodes WHERE text <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                self::node($pdo, $id);
            }
            Search::rebuild($pdo);
            return (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
        });
    }

    /** The Ops listener: index after a create or an edit. */
    public static function onChange(PDO $pdo, string $op, string $id): void
    {
        if ($op === 'create' || $op === 'edit') {
            self::node($pdo, $id);
        }
    }
}
