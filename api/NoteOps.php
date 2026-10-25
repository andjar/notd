<?php
/**
 * The single write path for notes.
 *
 * Clients send *intent* operations (create / move / edit / collapse / delete)
 * in the order the user performed them. They are applied in that order inside
 * one transaction (the caller owns it). Every operation carries an `op_id`;
 * an op_id that was already applied is skipped, so retrying a request after a
 * lost response is harmless.
 *
 * The server owns validation: parents must exist on the same page and moves
 * may not create cycles. Positions are fractional sort keys. The client sends
 * the key it computed optimistically; the server accepts it when it still
 * falls strictly between the intended neighbours and otherwise computes a new
 * one from `after` (the id of the previous sibling, or null for "first").
 *
 * Operations whose target has disappeared (e.g. deleted in another tab) are
 * skipped with a warning rather than failing the batch, so unrelated edits in
 * the same batch are never lost. Malformed operations throw
 * OpValidationException, which the endpoint turns into HTTP 400.
 */

namespace App;

use PDO;

require_once __DIR__ . '/Ordering.php';
require_once __DIR__ . '/PatternProcessor.php';
require_once __DIR__ . '/DataManager.php';

class OpValidationException extends \InvalidArgumentException {}

class NoteOps
{
    public const TYPES = ['create', 'move', 'edit', 'collapse', 'delete'];

    private PDO $pdo;
    private DataManager $dm;
    /** @var array<string,bool> */
    private array $touched = [];
    /** @var string[] */
    private array $deleted = [];
    /** @var array<int,array> */
    private array $warnings = [];

    public function __construct(PDO $pdo, ?DataManager $dm = null)
    {
        $this->pdo = $pdo;
        $this->dm = $dm ?? new DataManager($pdo);
    }

    /**
     * Applies ops in order. The caller must wrap this in a transaction.
     *
     * @return array{results: array, notes: array, deleted: string[], warnings: array}
     */
    public function apply(array $ops): array
    {
        $this->touched = [];
        $this->deleted = [];
        $this->warnings = [];
        $results = [];

        foreach (array_values($ops) as $i => $op) {
            $this->validateShape($op, $i);
            $opId = $op['op_id'];

            if ($this->alreadyApplied($opId)) {
                if (isset($op['id']) && $op['type'] !== 'delete') {
                    $this->touched[$op['id']] = true;
                }
                $results[] = ['op_id' => $opId, 'status' => 'duplicate'];
                continue;
            }

            $status = $this->{'apply' . ucfirst($op['type'])}($op, $i);
            $this->markApplied($opId);
            $results[] = ['op_id' => $opId, 'status' => $status];
        }

        $deleted = array_flip($this->deleted);
        $ids = array_values(array_filter(array_keys($this->touched), fn($id) => !isset($deleted[(string)$id])));
        return [
            'results' => $results,
            'notes' => $this->dm->getNotesByIds(array_map('strval', $ids)),
            'deleted' => array_values(array_unique($this->deleted)),
            'warnings' => $this->warnings,
        ];
    }

    // ---------------------------------------------------------------- ops

    private function applyCreate(array $op, int $i): string
    {
        $id = $op['id'];
        if ($this->noteExists($id)) {
            $this->warn($i, $op, 'exists', 'A note with this id already exists; create skipped.');
            $this->touched[$id] = true;
            return 'skipped';
        }
        $pageId = $op['page_id'] ?? null;
        if (!is_int($pageId) && !(is_string($pageId) && ctype_digit($pageId))) {
            throw new OpValidationException("op $i: create requires an integer page_id");
        }
        $pageId = (int)$pageId;
        if (!$this->pageExists($pageId)) {
            $this->warn($i, $op, 'page_missing', 'Page no longer exists; create skipped.');
            return 'skipped';
        }

        $parentId = $op['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = $this->getNoteRow($parentId);
            if (!$parent || (int)$parent['page_id'] !== $pageId) {
                $this->warn($i, $op, 'parent_missing', 'Parent not found on this page; note created at the top level instead.');
                $parentId = null;
            }
        }

        $content = (string)($op['content'] ?? '');
        $key = $this->resolveKey($pageId, $parentId, $op['after'] ?? null, $op['sort_key'] ?? null, $id, $i, $op);
        $stmt = $this->pdo->prepare(
            "INSERT INTO Notes (id, page_id, parent_note_id, sort_key, content, collapsed) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $pageId, $parentId, $key, $content, !empty($op['collapsed']) ? 1 : 0]);

        if (trim($content) !== '') {
            $this->indexProperties($id, $content);
        }
        $this->touchPage($pageId);
        $this->touched[$id] = true;
        return 'applied';
    }

    private function applyMove(array $op, int $i): string
    {
        $id = $op['id'];
        $note = $this->getNoteRow($id);
        if (!$note) {
            $this->warn($i, $op, 'missing', 'Note no longer exists; move skipped.');
            return 'skipped';
        }
        $pageId = (int)$note['page_id'];
        $parentId = $op['parent_id'] ?? null;

        if ($parentId !== null) {
            if ($parentId === $id) {
                $this->warn($i, $op, 'cycle', 'A note cannot be its own parent; move skipped.');
                $this->touched[$id] = true;
                return 'skipped';
            }
            $parent = $this->getNoteRow($parentId);
            if (!$parent || (int)$parent['page_id'] !== $pageId) {
                $this->warn($i, $op, 'parent_missing', 'Target parent not found on this page; move skipped.');
                $this->touched[$id] = true;
                return 'skipped';
            }
            if ($this->isAncestor($id, $parentId)) {
                $this->warn($i, $op, 'cycle', 'Cannot move a note under its own descendant; move skipped.');
                $this->touched[$id] = true;
                return 'skipped';
            }
        }

        $key = $this->resolveKey($pageId, $parentId, $op['after'] ?? null, $op['sort_key'] ?? null, $id, $i, $op);
        $stmt = $this->pdo->prepare(
            "UPDATE Notes SET parent_note_id = ?, sort_key = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute([$parentId, $key, $id]);
        $this->touchPage($pageId);
        $this->touched[$id] = true;
        return 'applied';
    }

    private function applyEdit(array $op, int $i): string
    {
        $id = $op['id'];
        if (!array_key_exists('content', $op) || !is_string($op['content'])) {
            throw new OpValidationException("op $i: edit requires string content");
        }
        $note = $this->getNoteRow($id);
        if (!$note) {
            $this->warn($i, $op, 'missing', 'Note no longer exists; edit skipped.');
            return 'skipped';
        }
        $stmt = $this->pdo->prepare(
            "UPDATE Notes SET content = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute([$op['content'], $id]);
        $this->indexProperties($id, $op['content']);
        $this->touchPage((int)$note['page_id']);
        $this->touched[$id] = true;
        return 'applied';
    }

    private function applyCollapse(array $op, int $i): string
    {
        $id = $op['id'];
        if (!$this->noteExists($id)) {
            $this->warn($i, $op, 'missing', 'Note no longer exists; collapse skipped.');
            return 'skipped';
        }
        $stmt = $this->pdo->prepare("UPDATE Notes SET collapsed = ?, version = version + 1 WHERE id = ?");
        $stmt->execute([!empty($op['collapsed']) ? 1 : 0, $id]);
        $this->touched[$id] = true;
        return 'applied';
    }

    private function applyDelete(array $op, int $i): string
    {
        $id = $op['id'];
        $note = $this->getNoteRow($id);
        if (!$note) {
            $this->deleted[] = $id; // already gone: report it so the client drops it too
            return 'skipped';
        }
        $stmt = $this->pdo->prepare(
            "WITH RECURSIVE sub(id) AS (SELECT ? UNION ALL SELECT n.id FROM Notes n JOIN sub ON n.parent_note_id = sub.id)
             SELECT id FROM sub"
        );
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $gone) {
            $this->deleted[] = (string)$gone;
            unset($this->touched[$gone]);
        }
        // Children cascade via the foreign key.
        $this->pdo->prepare("DELETE FROM Notes WHERE id = ?")->execute([$id]);
        $this->touchPage((int)$note['page_id']);
        return 'applied';
    }

    // ------------------------------------------------------------ helpers

    private function validateShape($op, int $i): void
    {
        if (!is_array($op)) {
            throw new OpValidationException("op $i: must be an object");
        }
        if (!isset($op['op_id']) || !is_string($op['op_id']) || $op['op_id'] === '' || strlen($op['op_id']) > 64) {
            throw new OpValidationException("op $i: op_id must be a non-empty string (max 64 chars)");
        }
        if (!isset($op['type']) || !in_array($op['type'], self::TYPES, true)) {
            throw new OpValidationException("op $i: type must be one of " . implode(', ', self::TYPES));
        }
        if (!isset($op['id']) || !Ordering::isValidId($op['id'])) {
            throw new OpValidationException("op $i: id must match [A-Za-z0-9_-]{1,64}");
        }
        foreach (['parent_id', 'after'] as $f) {
            if (isset($op[$f]) && !Ordering::isValidId($op[$f])) {
                throw new OpValidationException("op $i: $f must be null or a valid id");
            }
        }
        if (isset($op['sort_key']) && !is_string($op['sort_key'])) {
            throw new OpValidationException("op $i: sort_key must be a string");
        }
    }

    /**
     * Picks the sort key for `$selfId` under ($pageId, $parentId), right after
     * sibling `$afterId` (null = first).
     */
    private function resolveKey(int $pageId, ?string $parentId, ?string $afterId, ?string $hint, string $selfId, int $i, array $op): string
    {
        $siblings = $this->siblings($pageId, $parentId, $selfId);
        $idx = -1; // position of the previous sibling; -1 = insert first
        if ($afterId !== null) {
            $idx = null;
            foreach ($siblings as $k => $s) {
                if ($s['id'] === $afterId) {
                    $idx = $k;
                    break;
                }
            }
            if ($idx === null) {
                $this->warn($i, $op, 'after_missing', 'Previous sibling not found; placed at the end.');
                $idx = count($siblings) - 1;
            }
        }
        $prev = $idx >= 0 ? $siblings[$idx] : null;
        $next = $siblings[$idx + 1] ?? null;
        $lo = $prev['sort_key'] ?? null;
        $hi = $next['sort_key'] ?? null;

        if ($hint !== null && Ordering::isValidKey($hint)
            && ($lo === null || strcmp($lo, $hint) < 0)
            && ($hi === null || strcmp($hint, $hi) < 0)) {
            return $hint;
        }
        if ($lo === null || $hi === null || strcmp($lo, $hi) < 0) {
            return Ordering::keyBetween($lo, $hi);
        }

        // Neighbours share a key (possible after concurrent edits from two
        // tabs): spread the whole sibling group out again, then insert.
        $keys = Ordering::keysBetween(null, null, count($siblings));
        $upd = $this->pdo->prepare("UPDATE Notes SET sort_key = ?, version = version + 1 WHERE id = ?");
        foreach ($siblings as $k => $s) {
            $upd->execute([$keys[$k], $s['id']]);
            $siblings[$k]['sort_key'] = $keys[$k];
            $this->touched[$s['id']] = true;
        }
        $lo = $prev ? $keys[$idx] : null;
        $hi = $next ? $keys[$idx + 1] : null;
        return Ordering::keyBetween($lo, $hi);
    }

    private function siblings(int $pageId, ?string $parentId, string $excludeId): array
    {
        if ($parentId === null) {
            $stmt = $this->pdo->prepare(
                "SELECT id, sort_key FROM Notes WHERE page_id = ? AND parent_note_id IS NULL AND id != ? ORDER BY sort_key, id"
            );
            $stmt->execute([$pageId, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id, sort_key FROM Notes WHERE page_id = ? AND parent_note_id = ? AND id != ? ORDER BY sort_key, id"
            );
            $stmt->execute([$pageId, $parentId, $excludeId]);
        }
        return array_map(fn($r) => ['id' => (string)$r['id'], 'sort_key' => $r['sort_key']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** True when $candidateDescendant is $noteId itself or lies anywhere in $noteId's subtree. */
    private function isAncestor(string $noteId, string $candidateDescendant): bool
    {
        $stmt = $this->pdo->prepare(
            "WITH RECURSIVE anc(id, depth) AS (
                SELECT ?, 0
                UNION ALL
                SELECT n.parent_note_id, anc.depth + 1 FROM Notes n JOIN anc ON n.id = anc.id
                WHERE n.parent_note_id IS NOT NULL AND anc.depth < 10000
             )
             SELECT 1 FROM anc WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$candidateDescendant, $noteId]);
        return (bool)$stmt->fetchColumn();
    }

    private function getNoteRow(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, page_id, parent_note_id FROM Notes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function noteExists(string $id): bool
    {
        return $this->getNoteRow($id) !== null;
    }

    private function pageExists(int $pageId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM Pages WHERE id = ?");
        $stmt->execute([$pageId]);
        return (bool)$stmt->fetchColumn();
    }

    private function touchPage(int $pageId): void
    {
        $this->pdo->prepare("UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$pageId]);
    }

    private function alreadyApplied(string $opId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM AppliedOps WHERE op_id = ?");
        $stmt->execute([$opId]);
        return (bool)$stmt->fetchColumn();
    }

    private function markApplied(string $opId): void
    {
        $this->pdo->prepare("INSERT INTO AppliedOps (op_id) VALUES (?)")->execute([$opId]);
    }

    private function warn(int $i, array $op, string $code, string $message): void
    {
        $this->warnings[] = ['index' => $i, 'op_id' => $op['op_id'], 'id' => $op['id'] ?? null, 'code' => $code, 'message' => $message];
    }

    /**
     * Re-derives the note's property index from its content (content is the
     * source of truth). Replaceable properties that no longer appear in the
     * content are removed; append-only (log) properties are kept.
     */
    public function indexProperties(string $noteId, string $content): void
    {
        $enc = $this->pdo->prepare("SELECT 1 FROM Properties WHERE note_id = ? AND name = 'encrypted' AND value = 'true' LIMIT 1");
        $enc->execute([$noteId]);
        if ($enc->fetch()) {
            return;
        }

        $appendWeights = [];
        if (defined('PROPERTY_WEIGHTS')) {
            foreach (PROPERTY_WEIGHTS as $weight => $cfg) {
                if (($cfg['update_behavior'] ?? 'replace') === 'append') {
                    $appendWeights[] = (int)$weight;
                }
            }
        }
        if ($appendWeights) {
            $in = implode(',', $appendWeights);
            $this->pdo->prepare("DELETE FROM Properties WHERE note_id = ? AND weight NOT IN ($in)")->execute([$noteId]);
        } else {
            $this->pdo->prepare("DELETE FROM Properties WHERE note_id = ?")->execute([$noteId]);
        }

        $processor = new PatternProcessor($this->pdo);
        $processed = $processor->processContent($content, 'note', $noteId, ['pdo' => $this->pdo]);
        $props = $processed['properties'] ?? [];
        if (!empty($props)) {
            $processor->saveProperties($props, 'note', $noteId);
        }

        $internal = false;
        foreach ($props as $p) {
            if (strtolower($p['name'] ?? '') === 'internal' && strtolower((string)($p['value'] ?? '')) === 'true') {
                $internal = true;
                break;
            }
        }
        $this->pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?")->execute([$internal ? 1 : 0, $noteId]);
    }

    /**
     * Server-side helper for imports (welcome notes, append API): builds create
     * ops that append `$contents` at the end of a page's top level.
     */
    public function appendCreateOps(int $pageId, array $items, ?string $parentId = null): array
    {
        $siblings = $this->siblings($pageId, $parentId, '');
        $last = $siblings ? end($siblings) : null;
        $lastKey = $last['sort_key'] ?? null;
        $lastId = $last['id'] ?? null;
        $ops = [];
        foreach ($items as $item) {
            $id = Ordering::newId();
            $key = Ordering::keyBetween($lastKey, null);
            $ops[] = [
                'op_id' => 'srv-' . Ordering::newId(),
                'type' => 'create',
                'id' => $id,
                'page_id' => $pageId,
                'parent_id' => $parentId,
                'after' => $lastId,
                'sort_key' => $key,
                'content' => (string)($item['content'] ?? ''),
                'collapsed' => !empty($item['collapsed']),
            ];
            $lastKey = $key;
            $lastId = $id;
        }
        return $ops;
    }
}
