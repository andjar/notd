<?php
// Operations: the one way notes change. A batch is applied in one transaction;
// if any operation fails, nothing in the batch happens. Each operation may
// carry an opId; an opId seen before is skipped, so a client can safely send
// a batch again after a lost response.

declare(strict_types=1);

require_once __DIR__ . '/nodes.php';
require_once __DIR__ . '/sortkeys.php';

const OPS_MAX = 1000;

final class Ops
{
    /** Called after each change with ($pdo, $op, $nodeId); the indexer hooks in here. */
    public static array $listeners = [];

    /**
     * Applies a batch and returns ['results' => [...], 'seq' => last change number].
     * Errors are HttpErrors whose message names the failing operation.
     */
    public static function apply(PDO $pdo, array $ops): array
    {
        if (!array_is_list($ops) || count($ops) > OPS_MAX) {
            throw new HttpError(400, 'bad_request', '"ops" must be a list of at most ' . OPS_MAX . ' operations.');
        }
        return Db::write($pdo, function (PDO $pdo) use ($ops): array {
            $results = [];
            foreach ($ops as $i => $op) {
                try {
                    $results[] = self::applyOne($pdo, $op);
                } catch (HttpError $e) {
                    throw new HttpError($e->status, $e->codeName, "Operation $i: " . $e->getMessage());
                }
            }
            return ['results' => $results, 'seq' => self::lastSeq($pdo)];
        });
    }

    public static function lastSeq(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COALESCE(MAX(seq), 0) FROM changes')->fetchColumn();
    }

    private static function applyOne(PDO $pdo, mixed $op): array
    {
        if (!is_array($op) || !is_string($op['op'] ?? null)) {
            throw new HttpError(400, 'bad_request', 'an operation must be an object with "op".');
        }
        $opId = $op['opId'] ?? null;
        if ($opId !== null) {
            if (!is_string($opId) || $opId === '' || strlen($opId) > 100) {
                throw new HttpError(400, 'bad_request', '"opId" must be a string of at most 100 characters.');
            }
            $seen = $pdo->prepare('SELECT 1 FROM applied_ops WHERE op_id = ?');
            $seen->execute([$opId]);
            if ($seen->fetchColumn()) {
                return ['opId' => $opId, 'status' => 'duplicate'];
            }
        }
        $id = match ($op['op']) {
            'create' => self::create($pdo, $op),
            'edit' => self::edit($pdo, $op),
            'move' => self::move($pdo, $op),
            'delete' => self::delete($pdo, $op),
            'collapse' => self::collapse($pdo, $op),
            default => throw new HttpError(400, 'bad_op', "unknown operation \"{$op['op']}\"."),
        };
        if ($opId !== null) {
            $pdo->prepare('INSERT INTO applied_ops (op_id, at) VALUES (?, ?)')->execute([$opId, now_ms()]);
        }
        return ['opId' => $opId, 'status' => 'applied', 'id' => $id];
    }

    private static function create(PDO $pdo, array $op): string
    {
        $kind = self::str($op, 'kind', 'block');
        $id = isset($op['id']) ? self::str($op, 'id') : null;
        if ($kind === 'page' || $kind === 'doc') {
            $node = Nodes::createPage($pdo, self::str($op, 'title'), $id, $kind);
            if (isset($op['text'])) {
                self::setText($pdo, $node['id'], self::str($op, 'text'));
            }
        } elseif ($kind === 'block') {
            $parent = self::existing($pdo, self::str($op, 'parent'));
            $sort = self::sortKey($pdo, $op, $parent['id'], null);
            $node = Nodes::createBlock($pdo, $parent['id'], self::str($op, 'text', ''), $sort, $id);
        } else {
            throw new HttpError(400, 'bad_request', "nodes of kind \"$kind\" cannot be created with an operation.");
        }
        if (!empty($op['collapsed'])) {
            $pdo->prepare('UPDATE nodes SET collapsed = 1 WHERE id = ?')->execute([$node['id']]);
        }
        self::changed($pdo, 'create', $node['id']);
        return $node['id'];
    }

    private static function edit(PDO $pdo, array $op): string
    {
        $node = self::existing($pdo, self::str($op, 'id'));
        if (!array_key_exists('text', $op) && !array_key_exists('title', $op)) {
            throw new HttpError(400, 'bad_request', 'an edit needs "text" or "title".');
        }
        if (array_key_exists('title', $op)) {
            if (!isset($node['title'])) {
                throw new HttpError(400, 'bad_request', 'only pages have a title.');
            }
            $title = clean_title(self::str($op, 'title'));
            $other = Nodes::byTitle($pdo, $title);
            if ($other !== null && $other['id'] !== $node['id']) {
                throw new HttpError(409, 'title_taken', "A page titled \"{$other['title']}\" already exists.");
            }
            $pdo->prepare('UPDATE nodes SET title = ?, title_key = ? WHERE id = ?')->execute([$title, title_key($title), $node['id']]);
        }
        if (array_key_exists('text', $op)) {
            self::setText($pdo, $node['id'], self::str($op, 'text'));
        }
        self::changed($pdo, 'edit', $node['id']);
        return $node['id'];
    }

    private static function move(PDO $pdo, array $op): string
    {
        $node = self::existing($pdo, self::str($op, 'id'));
        if ($node['kind'] !== 'block') {
            throw new HttpError(400, 'bad_request', 'only blocks can be moved.');
        }
        $parent = self::existing($pdo, self::str($op, 'parent'));
        if ($parent['kind'] !== 'block' && $parent['kind'] !== 'page') {
            throw new HttpError(400, 'bad_parent', "a {$parent['kind']} cannot have blocks.");
        }
        $st = $pdo->prepare('WITH RECURSIVE up(id, parent_id) AS (SELECT id, parent_id FROM nodes WHERE id = ?
            UNION ALL SELECT n.id, n.parent_id FROM nodes n JOIN up ON n.id = up.parent_id) SELECT 1 FROM up WHERE id = ?');
        $st->execute([$parent['id'], $node['id']]);
        if ($st->fetchColumn()) {
            throw new HttpError(409, 'cycle', 'a block cannot move into itself or its own children.');
        }
        $sort = self::sortKey($pdo, $op, $parent['id'], $node['id']);
        $pdo->prepare('UPDATE nodes SET parent_id = ?, sort_key = ? WHERE id = ?')->execute([$parent['id'], $sort, $node['id']]);
        if ($parent['page'] !== $node['page']) {
            foreach (self::subtree($pdo, $node['id']) as $sub) {
                $pdo->prepare('UPDATE nodes SET page_id = ? WHERE id = ?')->execute([$parent['page'], $sub]);
            }
        }
        self::changed($pdo, 'move', $node['id']);
        return $node['id'];
    }

    private static function delete(PDO $pdo, array $op): string
    {
        $node = self::existing($pdo, self::str($op, 'id'));
        $ids = self::subtree($pdo, $node['id']);
        foreach (array_reverse($ids) as $sub) {
            self::changed($pdo, 'delete', $sub, false);
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM nodes WHERE id IN ($marks)")->execute($ids);
        return $node['id'];
    }

    private static function collapse(PDO $pdo, array $op): string
    {
        $node = self::existing($pdo, self::str($op, 'id'));
        if (!is_bool($op['collapsed'] ?? null)) {
            throw new HttpError(400, 'bad_request', '"collapsed" must be true or false.');
        }
        $pdo->prepare('UPDATE nodes SET collapsed = ? WHERE id = ?')->execute([(int) $op['collapsed'], $node['id']]);
        self::changed($pdo, 'collapse', $node['id']);
        return $node['id'];
    }

    /** The node and all its descendants, parents before children. */
    public static function subtree(PDO $pdo, string $id): array
    {
        $st = $pdo->prepare('WITH RECURSIVE down(id, depth) AS (SELECT id, 0 FROM nodes WHERE id = ?
            UNION ALL SELECT n.id, down.depth + 1 FROM nodes n JOIN down ON n.parent_id = down.id) SELECT id FROM down ORDER BY depth');
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function setText(PDO $pdo, string $id, string $text): void
    {
        $pdo->prepare('UPDATE nodes SET text = ? WHERE id = ?')->execute([Nodes::checkText($text), $id]);
    }

    /** The given sort key, checked; or, without one, a key after the last child. */
    private static function sortKey(PDO $pdo, array $op, string $parentId, ?string $movingId): string
    {
        if (isset($op['sort'])) {
            $sort = self::str($op, 'sort');
            if (!SortKeys::isValid($sort)) {
                throw new HttpError(400, 'bad_sort', "\"$sort\" is not a sort key.");
            }
            return $sort;
        }
        $st = $pdo->prepare('SELECT MAX(sort_key) FROM nodes WHERE parent_id = ? AND id IS NOT ?');
        $st->execute([$parentId, $movingId]);
        $last = $st->fetchColumn();
        return SortKeys::between(is_string($last) && SortKeys::isValid($last) ? $last : null, null);
    }

    /** Stamps the node with the next clock and appends to the change feed. */
    private static function changed(PDO $pdo, string $op, string $id, bool $stamp = true): void
    {
        $clock = Clock::next($pdo);
        $now = now_ms();
        if ($stamp) {
            $pdo->prepare('UPDATE nodes SET clock = ?, updated = ? WHERE id = ?')->execute([$clock, $now, $id]);
        }
        $pdo->prepare('INSERT INTO changes (node_id, op, clock, at) VALUES (?, ?, ?, ?)')->execute([$id, $op, $clock, $now]);
        foreach (self::$listeners as $listener) {
            $listener($pdo, $op, $id);
        }
    }

    private static function existing(PDO $pdo, string $id): array
    {
        $node = Nodes::get($pdo, $id);
        if ($node === null) {
            throw new HttpError(404, 'not_found', "no node $id.");
        }
        return $node;
    }

    private static function str(array $op, string $key, ?string $default = null): string
    {
        if (!array_key_exists($key, $op) || $op[$key] === null) {
            if ($default !== null) {
                return $default;
            }
            throw new HttpError(400, 'bad_request', "\"$key\" is missing.");
        }
        if (!is_string($op[$key])) {
            throw new HttpError(400, 'bad_request', "\"$key\" must be a string.");
        }
        return $op[$key];
    }
}

/**
 * A hybrid logical clock: milliseconds plus a counter, as a string that sorts in
 * time order ("0001732540000000-0000"). It never goes backwards, even if the
 * system clock does, so the order of changes is always clear.
 */
final class Clock
{
    public static function next(PDO $pdo): string
    {
        $last = (string) ($pdo->query("SELECT value FROM meta WHERE key = 'clock'")->fetchColumn() ?: '');
        [$lastMs, $lastCount] = $last === '' ? [0, 0] : [(int) substr($last, 0, 16), hexdec(substr($last, 17))];
        $ms = now_ms();
        [$ms, $count] = $ms > $lastMs ? [$ms, 0] : [$lastMs, $lastCount + 1];
        $clock = sprintf('%016d-%04x', $ms, $count);
        $pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('clock', ?)")->execute([$clock]);
        return $clock;
    }
}
