<?php
// Reading: pages with their blocks, nodes with their descendants, backlinks,
// page names and journal days.

declare(strict_types=1);

final class Read
{
    /** 'YYYY-MM-DD' titles that are real dates are journal pages. */
    public static function isJournalTitle(string $title): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $title, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * A page and all its blocks (flat, parents before children, siblings in order), found by
     * title, alias or id. A journal page that does not exist yet is created.
     */
    public static function page(PDO $pdo, ?string $title, ?string $id = null): array
    {
        $via = null;
        if ($id !== null) {
            $page = Nodes::get($pdo, $id);
        } else {
            $page = Nodes::byTitle($pdo, (string) $title);
            if ($page === null && ($page = self::byAlias($pdo, (string) $title)) !== null) {
                $via = 'alias';
            }
            if ($page === null && self::isJournalTitle(trim((string) $title))) {
                $newId = new_id();
                Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $newId, 'title' => trim((string) $title)]]);
                $page = Nodes::get($pdo, $newId);
            }
        }
        if ($page === null || $page['kind'] === 'block') {
            throw new HttpError(404, 'not_found', 'No such page: ' . ($id ?? $title));
        }
        return ['page' => $page, 'via' => $via, 'blocks' => self::descendants($pdo, $page['id'])];
    }

    /** A node and its descendants (flat, in outline order). */
    public static function node(PDO $pdo, string $id): array
    {
        $node = Nodes::get($pdo, $id);
        if ($node === null) {
            throw new HttpError(404, 'not_found', "No node $id.");
        }
        return ['node' => $node, 'descendants' => self::descendants($pdo, $id)];
    }

    /** All nodes under $id in outline order: depth first, siblings by sort key. */
    public static function descendants(PDO $pdo, string $id): array
    {
        $st = $pdo->prepare('WITH RECURSIVE down(id, path) AS (
              SELECT id, sort_key || char(1) || id FROM nodes WHERE parent_id = ?
              UNION ALL
              SELECT n.id, down.path || char(2) || n.sort_key || char(1) || n.id FROM nodes n JOIN down ON n.parent_id = down.id)
            SELECT n.* FROM down CROSS JOIN nodes n ON n.id = down.id ORDER BY down.path');
        $st->execute([$id]);
        return array_map([Nodes::class, 'shape'], $st->fetchAll());
    }

    /**
     * Blocks that point at a page (links, tags, embeds, also through its aliases) or at a
     * block (references, embeds), grouped by the page they are on. A page's own blocks are left out.
     */
    public static function backlinks(PDO $pdo, string $id): array
    {
        $node = Nodes::get($pdo, $id);
        if ($node === null) {
            throw new HttpError(404, 'not_found', "No node $id.");
        }
        if ($node['kind'] === 'block') {
            $targets = [$id];
            $kinds = ['ref', 'embed'];
        } else {
            $targets = [title_key($node['title'] ?? '')];
            $st = $pdo->prepare("SELECT value FROM fields WHERE node_id = ? AND key = 'alias'");
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $alias) {
                $targets[] = title_key($alias);
            }
            $kinds = ['link', 'tag', 'embed'];
        }
        $t = implode(',', array_fill(0, count($targets), '?'));
        $k = implode(',', array_fill(0, count($kinds), '?'));
        $st = $pdo->prepare("SELECT DISTINCT n.*, p.title AS page_title FROM links l JOIN nodes n ON n.id = l.from_id
            JOIN nodes p ON p.id = n.page_id WHERE l.target IN ($t) AND l.kind IN ($k) AND n.page_id <> ?
            ORDER BY p.title_key, n.page_id, n.created, n.id");
        $st->execute([...$targets, ...$kinds, $node['page']]);
        $groups = [];
        foreach ($st->fetchAll() as $row) {
            $groups[$row['page_id']] ??= ['page' => ['id' => $row['page_id'], 'title' => $row['page_title']], 'blocks' => []];
            $groups[$row['page_id']]['blocks'][] = Nodes::shape($row);
        }
        return array_values($groups);
    }

    /** Every page title with its aliases, for link suggestions. */
    public static function pages(PDO $pdo): array
    {
        $aliases = [];
        foreach ($pdo->query("SELECT f.node_id, f.value FROM fields f JOIN nodes n ON n.id = f.node_id
            WHERE f.key = 'alias' AND n.kind <> 'block'") as $row) {
            $aliases[$row['node_id']][] = $row['value'];
        }
        $out = [];
        foreach ($pdo->query("SELECT id, kind, title, updated FROM nodes WHERE kind IN ('page', 'doc') ORDER BY title_key") as $row) {
            $out[] = ['id' => $row['id'], 'kind' => $row['kind'], 'title' => $row['title'], 'updated' => (int) $row['updated'],
                'aliases' => $aliases[$row['id']] ?? []];
        }
        return $out;
    }

    /** Days of a month ('YYYY-MM') whose journal page has some text. */
    public static function journalDays(PDO $pdo, string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new HttpError(400, 'bad_request', '"month" must look like 2026-11.');
        }
        return self::days($pdo, 'p.title_key LIKE ?', [$month . '-__'], 'ASC', 31);
    }

    /** The latest days before a date ('YYYY-MM-DD') whose journal page has some text, newest first. */
    public static function journalDaysBefore(PDO $pdo, string $before, int $limit): array
    {
        if (!self::isJournalTitle($before)) {
            throw new HttpError(400, 'bad_request', '"before" must be a date like 2026-11-25.');
        }
        return self::days($pdo, "p.title_key < ? AND p.title_key GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'", [$before], 'DESC', max(1, min($limit, 100)));
    }

    private static function days(PDO $pdo, string $where, array $params, string $dir, int $limit): array
    {
        $st = $pdo->prepare("SELECT p.title FROM nodes p WHERE p.kind = 'page' AND $where
            AND EXISTS (SELECT 1 FROM nodes b WHERE b.page_id = p.id AND b.kind = 'block' AND trim(b.text) <> '')
            ORDER BY p.title_key $dir LIMIT $limit");
        $st->execute($params);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), [self::class, 'isJournalTitle']));
    }

    private static function byAlias(PDO $pdo, string $title): ?array
    {
        $key = title_key($title);
        foreach ($pdo->query("SELECT f.node_id, f.value FROM fields f JOIN nodes n ON n.id = f.node_id
            WHERE f.key = 'alias' AND n.kind <> 'block'") as $row) {
            if (title_key($row['value']) === $key) {
                return Nodes::get($pdo, $row['node_id']);
            }
        }
        return null;
    }
}
