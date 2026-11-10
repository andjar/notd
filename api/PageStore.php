<?php
/**
 * The single write path for pages: create, rename, update, delete.
 *
 * Page names are unique without regard to case. A page named like a date
 * (YYYY-MM-DD) is a journal page and gets {type::journal} in its content, so
 * that fact lives in the content like every other property.
 *
 * Every method runs inside notd_write(), so it is atomic on its own and joins
 * the caller's transaction when there is one.
 */

namespace App;

use PDO;

/** A request that cannot be carried out; the code is the HTTP status to answer with. */
class PageException extends \RuntimeException {}

class PageStore
{
    public const MAX_NAME_LENGTH = 255;

    private PDO $pdo;
    private PropertyIndex $index;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->index = new PropertyIndex($pdo);
    }

    public static function isJournalName(string $name): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $name);
    }

    /** Trims a name and rejects what cannot be a page name. */
    public static function normalizeName($name): string
    {
        if (!is_string($name)) {
            throw new PageException('Page name is required.', 400);
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $name = trim($name, '/');
        if ($name === '') {
            throw new PageException('Page name is required.', 400);
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new PageException('Page name is too long.', 400);
        }
        if (preg_match('/[\x00-\x1f\[\]]/', $name)) {
            throw new PageException('Page names cannot contain [ or ] or control characters.', 400);
        }
        return $name;
    }

    /** @return array|null the raw Pages row */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Pages WHERE LOWER(name) = LOWER(?) AND active = 1');
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM Pages WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Creates a page. @return int its id */
    public function create($name, ?string $content = null): int
    {
        $name = self::normalizeName($name);
        if ($this->findByName($name)) {
            throw new PageException('A page with this name already exists.', 409);
        }
        if (self::isJournalName($name) && strpos((string)$content, '{type::journal}') === false) {
            $content = trim('{type::journal} ' . (string)$content);
        }
        return $this->transaction(function () use ($name, $content) {
            $this->pdo->prepare('INSERT INTO Pages (name, content) VALUES (?, ?)')->execute([$name, $content]);
            $id = (int)$this->pdo->lastInsertId();
            $this->index->reindex('page', $id, $content);
            return $id;
        });
    }

    /** Returns the id of the page with this name, creating the page if needed. */
    public function ensure($name): int
    {
        $name = self::normalizeName($name);
        $page = $this->findByName($name);
        if ($page) {
            return (int)$page['id'];
        }
        try {
            return $this->create($name);
        } catch (\PDOException $e) {
            // Two requests created the same page at once; the other one won.
            $page = $this->findByName($name);
            if ($page) {
                return (int)$page['id'];
            }
            throw $e;
        }
    }

    /**
     * Changes a page's content and/or name. Renaming also rewrites every
     * [[link]] to the page, renames the pages below it in the namespace
     * (Old/Child becomes New/Child) and rewrites links to those.
     *
     * @return array{renamed: array<string,string>, notes_updated: int} old name => new name
     */
    public function update(int $id, array $changes): array
    {
        $page = $this->findById($id);
        if (!$page) {
            throw new PageException('Page not found.', 404);
        }
        $result = ['renamed' => [], 'notes_updated' => 0];

        return $this->transaction(function () use ($id, $page, $changes, $result) {
            if (array_key_exists('content', $changes)) {
                $content = $changes['content'] === null ? null : (string)$changes['content'];
                $this->pdo->prepare('UPDATE Pages SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$content, $id]);
                $this->index->reindex('page', $id, $content);
            }
            if (array_key_exists('name', $changes)) {
                $newName = self::normalizeName($changes['name']);
                if ($newName !== $page['name']) {
                    $result = $this->rename($page, $newName);
                }
            }
            return $result;
        });
    }

    private function rename(array $page, string $newName): array
    {
        $oldName = $page['name'];
        $other = $this->findByName($newName);
        if ($other && (int)$other['id'] !== (int)$page['id']) {
            throw new PageException('A page with this name already exists.', 409);
        }

        // The page itself and everything below it in the namespace.
        $renames = [$oldName => $newName];
        $stmt = $this->pdo->prepare("SELECT id, name FROM Pages WHERE LOWER(name) LIKE LOWER(?) ESCAPE '\\'");
        $stmt->execute([self::likeEscape($oldName) . '/%']);
        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($children as $child) {
            $childNew = $newName . substr($child['name'], strlen($oldName));
            $clash = $this->findByName($childNew);
            if ($clash && (int)$clash['id'] !== (int)$child['id']) {
                throw new PageException("Cannot rename: a page named \"$childNew\" already exists.", 409);
            }
            $renames[$child['name']] = $childNew;
        }

        $setName = $this->pdo->prepare('UPDATE Pages SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        // Two steps, so that swapping case or moving into one's own former name never trips the unique index.
        $setName->execute(["\x01renaming-" . $page['id'], $page['id']]);
        foreach ($children as $child) {
            $setName->execute([$renames[$child['name']], $child['id']]);
        }
        $setName->execute([$newName, $page['id']]);

        // Rewrite [[links]] in every note and page that points at a renamed page.
        $lower = [];
        foreach ($renames as $from => $to) {
            $lower[mb_strtolower($from)] = $to;
        }
        $rewrite = static function (string $content) use ($lower): string {
            return preg_replace_callback('/\[\[([^\]]+)\]\]/', static function ($m) use ($lower) {
                $target = $lower[mb_strtolower(trim($m[1]))] ?? null;
                return $target === null ? $m[0] : '[[' . $target . ']]';
            }, $content);
        };

        $notesUpdated = 0;
        $find = $this->pdo->prepare(
            "SELECT DISTINCT N.id, N.content FROM Properties P JOIN Notes N ON N.id = P.note_id
             WHERE P.name = 'links_to_page' AND LOWER(P.value) = LOWER(?)"
        );
        $setNote = $this->pdo->prepare('UPDATE Notes SET content = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $seen = [];
        foreach (array_keys($renames) as $from) {
            $find->execute([$from]);
            foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $note) {
                if (isset($seen[$note['id']])) {
                    continue;
                }
                $seen[$note['id']] = true;
                $updated = $rewrite((string)$note['content']);
                if ($updated !== $note['content']) {
                    $setNote->execute([$updated, $note['id']]);
                    $this->index->reindex('note', $note['id'], $updated);
                    $notesUpdated++;
                }
            }
        }
        $findPages = $this->pdo->prepare(
            "SELECT DISTINCT Pg.id, Pg.content FROM Properties P JOIN Pages Pg ON Pg.id = P.page_id
             WHERE P.name = 'links_to_page' AND LOWER(P.value) = LOWER(?)"
        );
        $setPage = $this->pdo->prepare('UPDATE Pages SET content = ? WHERE id = ?');
        foreach (array_keys($renames) as $from) {
            $findPages->execute([$from]);
            foreach ($findPages->fetchAll(PDO::FETCH_ASSOC) as $linking) {
                $updated = $rewrite((string)$linking['content']);
                if ($updated !== $linking['content']) {
                    $setPage->execute([$updated, $linking['id']]);
                    $this->index->reindex('page', (int)$linking['id'], $updated);
                }
            }
        }

        return ['renamed' => $renames, 'notes_updated' => $notesUpdated];
    }

    /** Deletes a page with its notes. Attachment files of those notes are removed from disk. */
    public function delete(int $id): void
    {
        if (!$this->findById($id)) {
            throw new PageException('Page not found.', 404);
        }
        $files = $this->transaction(function () use ($id) {
            $stmt = $this->pdo->prepare('SELECT A.id, A.path FROM Attachments A JOIN Notes N ON N.id = A.note_id WHERE N.page_id = ?');
            $stmt->execute([$id]);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $drop = $this->pdo->prepare('DELETE FROM Attachments WHERE id = ?');
            foreach ($attachments as $a) {
                $drop->execute([$a['id']]);
            }
            $this->pdo->prepare('DELETE FROM Pages WHERE id = ?')->execute([$id]);
            return array_column($attachments, 'path');
        });
        foreach ($files as $path) {
            $full = rtrim(UPLOADS_DIR, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            if (strpos($path, '..') === false && is_file($full)) {
                @unlink($full);
            }
        }
    }

    public static function likeEscape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    private function transaction(callable $fn)
    {
        return notd_write($this->pdo, $fn);
    }
}
