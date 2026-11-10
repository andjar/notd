<?php
/**
 * Read queries. Everything the API returns about notes and pages is shaped
 * here, so each kind of record looks the same in every endpoint.
 *
 * Before PHP 8.1 the SQLite driver returns integers as strings; the cast*Row
 * helpers give JSON the same types on every PHP version.
 */

namespace App;

use PDO;

class DataManager
{
    protected PDO $pdo;

    /** Properties of this weight and above are "internal": hidden unless asked for. */
    private const INTERNAL_WEIGHT = 3;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ------------------------------------------------------------ row shapes

    public static function castNoteRow(array $note): array
    {
        unset($note['rid']);
        foreach (['page_id', 'internal', 'collapsed', 'active', 'version'] as $field) {
            if (isset($note[$field])) {
                $note[$field] = (int)$note[$field];
            }
        }
        return $note;
    }

    public static function castPageRow(array $page): array
    {
        foreach (['id', 'active'] as $field) {
            if (isset($page[$field])) {
                $page[$field] = (int)$page[$field];
            }
        }
        return $page;
    }

    /** Groups property rows by name: name => [{value, internal}, ...] in the order they were written. */
    private static function groupProperties(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['name']][] = [
                'value' => $row['value'],
                'internal' => (int)$row['weight'] >= self::INTERNAL_WEIGHT,
            ];
        }
        return $grouped;
    }

    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    // ------------------------------------------------------------ properties

    public function getPageProperties(int $pageId, bool $includeInternal = false): array
    {
        $sql = 'SELECT name, value, weight FROM Properties WHERE page_id = ? AND active = 1'
            . ($includeInternal ? '' : ' AND weight < ' . self::INTERNAL_WEIGHT) . ' ORDER BY id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$pageId]);
        return self::groupProperties($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getNoteProperties(string $noteId, bool $includeInternal = false): array
    {
        return $this->getPropertiesForNoteIds([$noteId], $includeInternal)[$noteId] ?? [];
    }

    /**
     * Properties of many notes in one query per 500 ids.
     *
     * @return array<string, array> note id => grouped properties (every requested id is present)
     */
    public function getPropertiesForNoteIds(array $noteIds, bool $includeInternal = false): array
    {
        $result = [];
        foreach ($noteIds as $id) {
            $result[(string)$id] = [];
        }
        foreach (array_chunk(array_keys($result), 500) as $chunk) {
            $chunk = array_map('strval', $chunk);
            $sql = 'SELECT note_id, name, value, weight FROM Properties WHERE note_id IN (' . self::placeholders($chunk) . ') AND active = 1'
                . ($includeInternal ? '' : ' AND weight < ' . self::INTERNAL_WEIGHT) . ' ORDER BY id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string)$row['note_id']][$row['name']][] = [
                    'value' => $row['value'],
                    'internal' => (int)$row['weight'] >= self::INTERNAL_WEIGHT,
                ];
            }
        }
        return $result;
    }

    /**
     * Properties inherited from ancestors, nearest ancestor first, each value once.
     *
     * @return array<string, array> note id => name => [{value}, ...] (every requested id is present)
     */
    public function getParentPropertiesForNoteIds(array $noteIds, bool $includeInternal = false): array
    {
        $result = [];
        foreach ($noteIds as $id) {
            $result[(string)$id] = [];
        }
        foreach (array_chunk(array_keys($result), 500) as $chunk) {
            $chunk = array_map('strval', $chunk);
            $sql = 'WITH RECURSIVE anc(note_id, ancestor, depth) AS (
                        SELECT id, parent_note_id, 1 FROM Notes
                         WHERE id IN (' . self::placeholders($chunk) . ') AND parent_note_id IS NOT NULL
                        UNION ALL
                        SELECT anc.note_id, n.parent_note_id, anc.depth + 1
                          FROM anc JOIN Notes n ON n.id = anc.ancestor
                         WHERE n.parent_note_id IS NOT NULL AND anc.depth < 100
                    )
                    SELECT anc.note_id, p.name, p.value
                      FROM anc JOIN Properties p ON p.note_id = anc.ancestor
                     WHERE p.active = 1' . ($includeInternal ? '' : ' AND p.weight < ' . self::INTERNAL_WEIGHT) . '
                     ORDER BY anc.depth, p.id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);
            $seen = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['note_id'] . "\0" . $row['name'] . "\0" . $row['value'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[(string)$row['note_id']][$row['name']][] = ['value' => $row['value']];
                }
            }
        }
        return $result;
    }

    // ----------------------------------------------------------------- notes

    /** Adds `properties` and `has_attachments` to raw note rows. */
    private function decorateNotes(array $notes, bool $includeInternal): array
    {
        if (!$notes) {
            return [];
        }
        $ids = array_map('strval', array_column($notes, 'id'));
        $properties = $this->getPropertiesForNoteIds($ids, $includeInternal);

        $withAttachments = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->pdo->prepare('SELECT DISTINCT note_id FROM Attachments WHERE note_id IN (' . self::placeholders($chunk) . ') AND active = 1');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $withAttachments[(string)$id] = true;
            }
        }

        foreach ($notes as &$note) {
            $note = self::castNoteRow($note);
            $note['properties'] = $properties[(string)$note['id']] ?? [];
            $note['has_attachments'] = isset($withAttachments[(string)$note['id']]);
        }
        unset($note);
        return $notes;
    }

    /** All notes of a page, flat, in outline order within each parent. */
    public function getNotesByPageId(int $pageId, bool $includeInternal = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Notes WHERE page_id = ? AND active = 1 ORDER BY sort_key, id');
        $stmt->execute([$pageId]);
        return $this->decorateNotes($stmt->fetchAll(PDO::FETCH_ASSOC), $includeInternal);
    }

    /** Several notes by id (any page). Missing ids are absent from the result. */
    public function getNotesByIds(array $noteIds, bool $includeInternal = false): array
    {
        $noteIds = array_values(array_unique(array_map('strval', $noteIds)));
        $rows = [];
        foreach (array_chunk($noteIds, 500) as $chunk) {
            $stmt = $this->pdo->prepare('SELECT * FROM Notes WHERE id IN (' . self::placeholders($chunk) . ') AND active = 1');
            $stmt->execute($chunk);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $this->decorateNotes($rows, $includeInternal);
    }

    public function getNoteById(string $noteId, bool $includeInternal = false, bool $includeParentProperties = false): ?array
    {
        $note = $this->getNotesByIds([$noteId], $includeInternal)[0] ?? null;
        if ($note) {
            $note['parent_properties'] = $includeParentProperties
                ? $this->getParentPropertiesForNoteIds([$noteId], $includeInternal)[$noteId]
                : null;
        }
        return $note;
    }

    /**
     * A note with its subtree nested under `children` (at most $maxDepth levels below it).
     */
    public function getNoteWithChildren(string $noteId, bool $includeInternal = false, bool $includeParentProperties = false, int $maxDepth = 10): ?array
    {
        $root = $this->getNoteById($noteId, $includeInternal, $includeParentProperties);
        if (!$root) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'WITH RECURSIVE sub(id, depth) AS (
                 SELECT id, 1 FROM Notes WHERE parent_note_id = ? AND active = 1
                 UNION ALL
                 SELECT n.id, sub.depth + 1 FROM Notes n JOIN sub ON n.parent_note_id = sub.id
                  WHERE n.active = 1 AND sub.depth < ?
             )
             SELECT n.* FROM Notes n JOIN sub ON sub.id = n.id ORDER BY n.sort_key, n.id'
        );
        $stmt->execute([$noteId, $maxDepth]);
        $descendants = $this->decorateNotes($stmt->fetchAll(PDO::FETCH_ASSOC), $includeInternal);
        $parentProps = $includeParentProperties
            ? $this->getParentPropertiesForNoteIds(array_column($descendants, 'id'), $includeInternal)
            : [];

        $byParent = [];
        foreach ($descendants as $note) {
            $note['parent_properties'] = $includeParentProperties ? ($parentProps[(string)$note['id']] ?? []) : null;
            $byParent[(string)$note['parent_note_id']][] = $note;
        }
        $attach = static function (array $note) use (&$attach, $byParent): array {
            $note['children'] = array_map($attach, $byParent[(string)$note['id']] ?? []);
            return $note;
        };
        return $attach($root);
    }

    // ----------------------------------------------------------------- pages

    private function decoratePage(?array $page): ?array
    {
        if (!$page) {
            return null;
        }
        $page = self::castPageRow($page);
        $page['properties'] = $this->getPageProperties($page['id'], true);
        return $page;
    }

    public function getPageById(int $pageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Pages WHERE id = ? AND active = 1');
        $stmt->execute([$pageId]);
        return $this->decoratePage($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function getPageByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Pages WHERE LOWER(name) = LOWER(?) AND active = 1');
        $stmt->execute([$name]);
        return $this->decoratePage($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    /** SQL condition that is true for journal pages (pages named like a date). */
    private const IS_JOURNAL = "name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'";

    /**
     * Pages, most recently changed first.
     *
     * @return array{data: array, pagination: array}
     */
    public function getPages(int $page = 1, int $perPage = 20, array $options = []): array
    {
        $where = 'active = 1' . (!empty($options['exclude_journal']) ? ' AND NOT ' . self::IS_JOURNAL : '');
        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM Pages WHERE $where")->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT id, name, content, alias, updated_at FROM Pages WHERE $where ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => array_map([self::class, 'castPageRow'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination' => [
                'total_items' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => (int)ceil($total / max(1, $perPage)),
            ],
        ];
    }

    /** Pages that carry a {date::YYYY-MM-DD} property with this value. */
    public function getPagesByDate(string $date): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT p.id, p.name, p.content, p.alias, p.updated_at
               FROM Pages p JOIN Properties prop ON p.id = prop.page_id
              WHERE prop.name = 'date' AND prop.value = ? AND p.active = 1 AND prop.active = 1
              ORDER BY p.name"
        );
        $stmt->execute([$date]);
        return array_map([self::class, 'castPageRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Pages directly below $namespace: "A/B" for "A", but not "A/B/C". */
    public function getChildPages(string $namespace): array
    {
        $prefix = rtrim($namespace, '/') . '/';
        $stmt = $this->pdo->prepare(
            "SELECT id, name, updated_at FROM Pages
              WHERE LOWER(name) LIKE LOWER(?) ESCAPE '\\'
                AND SUBSTR(name, ?) NOT LIKE '%/%'
                AND active = 1
              ORDER BY name"
        );
        $stmt->execute([PageStore::likeEscape($prefix) . '%', mb_strlen($prefix) + 1]);
        return array_map([self::class, 'castPageRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getRecentPages(int $limit = 7): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, updated_at FROM Pages WHERE active = 1 ORDER BY updated_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'castPageRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Pages marked {favorite::true}. */
    public function getFavoritePages(?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT P.id, P.name, P.updated_at FROM Pages P JOIN Properties Prop ON P.id = Prop.page_id
              WHERE Prop.name = 'favorite' AND LOWER(Prop.value) = 'true' AND P.active = 1
              ORDER BY P.name COLLATE NOCASE LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $limit ?? -1, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'castPageRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countFavoritePages(): int
    {
        return (int)$this->pdo->query(
            "SELECT COUNT(DISTINCT page_id) FROM Properties WHERE name = 'favorite' AND LOWER(value) = 'true' AND page_id IS NOT NULL"
        )->fetchColumn();
    }

    /**
     * Notes that link to a page with [[Name]] (page names are case-insensitive).
     *
     * @return array<int, array{note_id: string, content: string, page_id: int, page_name: string}>
     */
    public function getBacklinks(string $pageName, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT N.id AS note_id, N.content, N.page_id, P.name AS page_name
               FROM Notes N JOIN Pages P ON N.page_id = P.id
              WHERE N.id IN (SELECT note_id FROM Properties WHERE name = 'links_to_page' AND value = ? COLLATE NOCASE)
              ORDER BY N.updated_at DESC, N.id LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $pageName);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['page_id'] = (int)$row['page_id'];
        }
        return $rows;
    }

    public function countBacklinks(string $pageName): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT note_id) FROM Properties WHERE name = 'links_to_page' AND value = ? COLLATE NOCASE");
        $stmt->execute([$pageName]);
        return (int)$stmt->fetchColumn();
    }
}
