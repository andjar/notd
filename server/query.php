<?php
// The query language, compiled to parameterised SQL.
//
//   type:movie rating>=4 -has:watched sort:-rating limit:20
//   tag:scifi OR tag:fantasy "space opera" group:type
//
// Filters: key:value, key!=value, key>N (>=, <, <=; numbers compare as numbers,
// anything else as text, so ISO dates work), has:key, and the built-in keys
// kind:, page:, tag:, links:, created> and updated> (dates). Free words and
// "phrases" search the text. A leading - negates a filter; OR joins the filters
// on either side of it. Options: sort:key or sort:-key (repeatable; also title,
// text, created, updated), limit:N,
// group:key; view: and columns: are passed back untouched for whoever shows the result.

declare(strict_types=1);

const QUERY_LIMIT = 100;
const QUERY_LIMIT_MAX = 1000;
/** Keys that are options, not field filters. */
const QUERY_OPTIONS = ['sort', 'limit', 'group', 'view', 'columns'];

final class Query
{
    /** Parses a query into ['where' => sql, 'params' => [], 'order' => sql, 'orderParams' => [], 'limit' => n, 'group' => ?key, 'options' => []]. */
    public static function compile(string $q): array
    {
        preg_match_all('/(-?)(?:([\p{L}\p{N}_.-]+)(!=|>=|<=|:|>|<))?("[^"]*"|\S+)/u', $q, $m, PREG_SET_ORDER);
        $conds = [];
        $joinNext = false;
        $order = [];
        $orderParams = [];
        $limit = QUERY_LIMIT;
        $group = null;
        $options = [];
        $words = [];
        foreach ($m as [, $neg, $key, $opr, $value]) {
            $quoted = strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"');
            $value = $quoted ? substr($value, 1, -1) : $value;
            if ($key === '' && $value === 'OR' && !$quoted) {
                if (!$conds) {
                    throw new HttpError(400, 'bad_query', 'OR needs a filter before it.');
                }
                $joinNext = true;
                continue;
            }
            $key = strtolower($key);
            if ($opr === ':' && in_array($key, QUERY_OPTIONS, true)) {
                if ($key === 'sort') {
                    [$sql, $params] = self::sortTerm($value);
                    $order[] = $sql;
                    array_push($orderParams, ...$params);
                } elseif ($key === 'limit') {
                    if (!ctype_digit($value) || (int) $value < 1) {
                        throw new HttpError(400, 'bad_query', 'limit must be a positive whole number.');
                    }
                    $limit = min((int) $value, QUERY_LIMIT_MAX);
                } elseif ($key === 'group') {
                    $group = strtolower($value);
                } else {
                    $options[$key] = $value;
                }
                continue;
            }
            if ($key === '') {
                $cond = self::text($value, $quoted);
            } else {
                $cond = self::filter($key, $opr, $value);
            }
            if ($neg === '-') {
                $cond = ['NOT (' . $cond[0] . ')', $cond[1]];
            }
            if ($joinNext) {
                $prev = array_pop($conds);
                $cond = ['((' . $prev[0] . ') OR (' . $cond[0] . '))', [...$prev[1], ...$cond[1]]];
                $joinNext = false;
            }
            $conds[] = $cond;
        }
        if ($joinNext) {
            throw new HttpError(400, 'bad_query', 'OR needs a filter after it.');
        }
        if (!$conds) {
            throw new HttpError(400, 'bad_query', 'A query needs at least one filter or word.');
        }
        $order[] = 'n.updated DESC';
        return [
            'where' => implode(' AND ', array_map(fn($c) => $c[0], $conds)),
            'params' => array_merge(...array_map(fn($c) => $c[1], $conds)),
            'order' => implode(', ', $order), 'orderParams' => $orderParams,
            'limit' => $limit, 'group' => $group, 'options' => $options,
        ];
    }

    /** Runs a query: ['nodes' => [...]] or, with group:, ['groups' => [['value' =>, 'nodes' => []]]]; plus 'options'. */
    public static function run(PDO $pdo, string $q): array
    {
        $c = self::compile($q);
        $st = $pdo->prepare("SELECT n.*, p.title AS page_title FROM nodes n LEFT JOIN nodes p ON p.id = n.page_id
            WHERE {$c['where']} ORDER BY {$c['order']} LIMIT " . $c['limit']);
        $st->execute([...$c['params'], ...$c['orderParams']]);
        $rows = $st->fetchAll();
        $fields = self::fieldsOf($pdo, array_column($rows, 'id'));
        $nodes = array_map(fn($row) => Nodes::shape($row) + ['pageTitle' => $row['page_title'], 'fields' => $fields[$row['id']] ?? (object) []], $rows);
        if ($c['group'] === null) {
            return ['nodes' => $nodes, 'options' => (object) $c['options']];
        }
        $groups = [];
        foreach ($nodes as $node) {
            $values = is_array($node['fields']) ? ($node['fields'][$c['group']] ?? ['']) : [''];
            foreach ($values as $v) {
                $k = title_key($v);
                $groups[$k] ??= ['value' => $v === '' ? null : $v, 'nodes' => []];
                $groups[$k]['nodes'][] = $node;
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        if (isset($groups[''])) {
            $none = $groups[''];
            unset($groups['']);
            $groups[''] = $none;
        }
        return ['groups' => array_values($groups), 'options' => (object) $c['options']];
    }

    /** [sql, params] for one filter. */
    private static function filter(string $key, string $opr, string $value): array
    {
        $exists = fn(string $extra, array $p) => ['n.id IN (SELECT f.node_id FROM fields f WHERE f.key = ?' . $extra . ')', $p];
        switch ($key) {
            case 'has':
                return $exists('', [strtolower($value)]);
            case 'kind':
                self::onlyColon($key, $opr);
                return ['n.kind = ?', [strtolower($value)]];
            case 'page':
                self::onlyColon($key, $opr);
                return ["n.kind <> 'page' AND n.page_id IN (SELECT id FROM nodes WHERE title_key = ?)", [title_key($value)]];
            case 'tag':
            case 'links':
                self::onlyColon($key, $opr);
                $kinds = $key === 'tag' ? "'tag'" : "'link', 'tag'";
                return ["n.id IN (SELECT l.from_id FROM links l WHERE l.kind IN ($kinds) AND l.target = ?)", [title_key(ltrim($value, '#'))]];
            case 'created':
            case 'updated':
                $ms = strtotime($value . (strlen($value) === 10 ? ' 00:00:00' : ''));
                if ($ms === false || $opr === ':' && strlen($value) !== 10) {
                    throw new HttpError(400, 'bad_query', "$key needs a date like 2026-11-25.");
                }
                if ($opr === ':') {
                    return ["(n.$key >= ? AND n.$key < ?)", [$ms * 1000, ($ms + 86400) * 1000]];
                }
                return ["n.$key " . self::sqlOp($opr) . ' ?', [$ms * 1000]];
        }
        if ($opr === ':' || $opr === '!=') {
            $cond = $exists(' AND f.value_key = ?', [$key, title_key($value)]);
            return $opr === ':' ? $cond : ['NOT ' . $cond[0], $cond[1]];
        }
        if (is_numeric($value)) {
            return $exists(' AND f.num ' . self::sqlOp($opr) . ' ?', [$key, (float) $value]);
        }
        return $exists(' AND f.value_key ' . self::sqlOp($opr) . ' ?', [$key, title_key($value)]);
    }

    private static function text(string $value, bool $phrase): array
    {
        $fts = Search::ftsQuery($phrase ? '"' . $value . '"' : $value);
        if ($fts === null) {
            throw new HttpError(400, 'bad_query', "\"$value\" has nothing to search for.");
        }
        return ['n.id IN (SELECT node_id FROM search WHERE search MATCH ?)', [$fts]];
    }

    /** [sql, params] for sort:key or sort:-key. */
    private static function sortTerm(string $value): array
    {
        $dir = str_starts_with($value, '-') ? 'DESC' : 'ASC';
        $key = strtolower(ltrim($value, '-'));
        if (in_array($key, ['created', 'updated'], true)) {
            return ["n.$key $dir", []];
        }
        if ($key === 'title' || $key === 'text') {
            return ["COALESCE(n.title_key, n.text) COLLATE NOCASE $dir", []];
        }
        $num = '(SELECT MIN(num) FROM fields WHERE node_id = n.id AND +key = ?)';
        $txt = '(SELECT MIN(value_key) FROM fields WHERE node_id = n.id AND +key = ?)';
        return ["$txt IS NULL, $num $dir, $txt $dir", [$key, $key, $key]];
    }

    private static function sqlOp(string $opr): string
    {
        return ['>' => '>', '>=' => '>=', '<' => '<', '<=' => '<='][$opr];
    }

    private static function onlyColon(string $key, string $opr): void
    {
        if ($opr !== ':') {
            throw new HttpError(400, 'bad_query', "$key only takes $key:value.");
        }
    }

    /** id => [key => [values]] for the given nodes. */
    private static function fieldsOf(PDO $pdo, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $st = $pdo->prepare('SELECT node_id, key, value FROM fields WHERE node_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY rowid');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[$row['node_id']][$row['key']][] = $row['value'];
        }
        return $out;
    }
}
