<?php
// The node store: creating and reading pages and blocks. Every write here is
// meant to run inside Db::write(); the operations endpoint is its only caller
// over HTTP.

declare(strict_types=1);

const TITLE_MAX = 255;

/** The key titles are compared by: Unicode NFC, case-folded, spaces trimmed and collapsed. */
function title_key(string $title): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $t = Normalizer::normalize($t, Normalizer::FORM_C) ?: $t;
    return mb_convert_case($t, MB_CASE_FOLD_SIMPLE, 'UTF-8');
}

/** A title as stored: NFC, spaces trimmed and collapsed. Throws when it cannot be a title. */
function clean_title(string $title): string
{
    if (!mb_check_encoding($title, 'UTF-8')) {
        throw new HttpError(400, 'bad_title', 'A title must be valid UTF-8.');
    }
    $t = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    $t = Normalizer::normalize($t, Normalizer::FORM_C) ?: $t;
    if ($t === '') {
        throw new HttpError(400, 'bad_title', 'A title cannot be empty.');
    }
    if (mb_strlen($t) > TITLE_MAX) {
        throw new HttpError(400, 'bad_title', 'A title can be at most ' . TITLE_MAX . ' characters.');
    }
    if (preg_match('/[\x00-\x1F\x7F\[\]]/u', $t)) {
        throw new HttpError(400, 'bad_title', 'A title cannot contain control characters or square brackets.');
    }
    return $t;
}

/** A UUIDv7: 48 bits of milliseconds, then random bits, so ids sort by creation time. */
function new_id(): string
{
    $ms = now_ms();
    $bytes = pack('J', $ms);                      // 8 bytes, big endian
    $b = substr($bytes, 2, 6) . random_bytes(10);
    $b[6] = chr((ord($b[6]) & 0x0F) | 0x70);      // version 7
    $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);      // variant 10
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function is_id(mixed $id): bool
{
    return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
}

final class Nodes
{
    public static function createPage(PDO $pdo, string $title, ?string $id = null, string $kind = 'page'): array
    {
        $title = clean_title($title);
        $key = title_key($title);
        $existing = self::byTitle($pdo, $title);
        if ($existing !== null) {
            throw new HttpError(409, 'title_taken', "A page titled \"{$existing['title']}\" already exists.");
        }
        $id = self::checkNewId($pdo, $id);
        $now = now_ms();
        $pdo->prepare('INSERT INTO nodes (id, kind, parent_id, page_id, title, title_key, created, updated) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)')
            ->execute([$id, $kind, $id, $title, $key, $now, $now]);
        return self::get($pdo, $id);
    }

    public static function createBlock(PDO $pdo, string $parentId, string $text, string $sortKey, ?string $id = null): array
    {
        $parent = self::get($pdo, $parentId);
        if ($parent === null) {
            throw new HttpError(404, 'not_found', "No node $parentId to add a block to.");
        }
        if ($parent['kind'] !== 'block' && $parent['kind'] !== 'page') {
            throw new HttpError(400, 'bad_parent', "A {$parent['kind']} cannot have blocks.");
        }
        $id = self::checkNewId($pdo, $id);
        $now = now_ms();
        $pdo->prepare('INSERT INTO nodes (id, kind, parent_id, page_id, sort_key, text, created, updated) VALUES (?, \'block\', ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $parentId, $parent['page'], $sortKey, self::checkText($text), $now, $now]);
        return self::get($pdo, $id);
    }

    public static function get(PDO $pdo, string $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM nodes WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false ? null : self::shape($row);
    }

    public static function byTitle(PDO $pdo, string $title): ?array
    {
        $st = $pdo->prepare('SELECT * FROM nodes WHERE title_key = ?');
        $st->execute([title_key($title)]);
        $row = $st->fetch();
        return $row === false ? null : self::shape($row);
    }

    /** Direct children in order. */
    public static function children(PDO $pdo, string $parentId): array
    {
        $st = $pdo->prepare('SELECT * FROM nodes WHERE parent_id = ? ORDER BY sort_key, id');
        $st->execute([$parentId]);
        return array_map([self::class, 'shape'], $st->fetchAll());
    }

    /** A node as the protocol shows it: integers as integers, booleans as booleans. */
    public static function shape(array $row): array
    {
        $node = [
            'id' => $row['id'],
            'kind' => $row['kind'],
            'parent' => $row['parent_id'],
            'page' => $row['page_id'],
            'sort' => $row['sort_key'],
            'text' => $row['text'],
            'collapsed' => (bool) $row['collapsed'],
            'clock' => $row['clock'],
            'created' => (int) $row['created'],
            'updated' => (int) $row['updated'],
        ];
        if ($row['title'] !== null) {
            $node['title'] = $row['title'];
        }
        return $node;
    }

    public static function checkText(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new HttpError(400, 'bad_text', 'Text must be valid UTF-8.');
        }
        return $text;
    }

    private static function checkNewId(PDO $pdo, ?string $id): string
    {
        if ($id === null) {
            return new_id();
        }
        if (!is_id($id)) {
            throw new HttpError(400, 'bad_id', "\"$id\" is not a lower-case UUID.");
        }
        if (self::get($pdo, $id) !== null) {
            throw new HttpError(409, 'id_taken', "A node with id $id already exists.");
        }
        return $id;
    }
}
