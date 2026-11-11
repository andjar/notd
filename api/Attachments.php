<?php
/**
 * File attachments of notes: files live under UPLOADS_DIR/<year>/<month>/,
 * the Attachments table records which note each belongs to.
 */

namespace App;

use PDO;

/** An upload or request that cannot be carried out; the code is the HTTP status. */
class AttachmentException extends \RuntimeException {}

class Attachments
{
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** Accepted file extensions and the type recorded for each. Nothing else is stored. */
    public const TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
        'json' => 'application/json', 'excalidraw' => 'application/json',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'zip' => 'application/zip',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * The URL a browser can load an attachment from. Files in the default
     * uploads/ folder are served by the web server itself; if UPLOADS_DIR
     * points elsewhere they are sent through attachments.php?file=.
     */
    public static function url(string $path): string
    {
        static $base = null, $direct = null;
        if ($base === null) {
            // /sub/api/v1/attachments.php -> /sub/ ; /sub/page.php -> /sub/
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
            $pos = strpos($script, '/api/v1/');
            if ($pos === false) {
                $pos = strpos($script, '/extensions/');
            }
            $base = $pos === false ? rtrim(dirname($script), '/') . '/' : substr($script, 0, $pos + 1);
            $direct = realpath(UPLOADS_DIR) === realpath(__DIR__ . '/../uploads');
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        return $direct ? $base . 'uploads/' . $encoded : $base . 'api/v1/attachments.php?file=' . rawurlencode($path);
    }

    private static function shape(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['size'] = (int)$row['size'];
        $row['url'] = self::url($row['path']);
        return $row;
    }

    /** @return array attachments of a note, newest first */
    public function forNote(string $noteId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, note_id, name, path, type, size, created_at FROM Attachments WHERE note_id = ? AND active = 1 ORDER BY id DESC');
        $stmt->execute([$noteId]);
        return array_map([self::class, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * All attachments, filtered, sorted and paged.
     *
     * @param array $q sort_by, sort_order, page, per_page, filter_by_name, filter_by_type
     * @return array{data: array, pagination: array}
     */
    public function all(array $q): array
    {
        $sortBy = in_array($q['sort_by'] ?? '', ['id', 'name', 'path', 'type', 'size', 'created_at'], true) ? $q['sort_by'] : 'created_at';
        $sortOrder = strtolower((string)($q['sort_order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(100, (int)($q['per_page'] ?? 10)));

        $where = ['active = 1'];
        $params = [];
        if (!empty($q['filter_by_name'])) {
            $where[] = "name LIKE ? ESCAPE '\\'";
            $params[] = '%' . PageStore::likeEscape((string)$q['filter_by_name']) . '%';
        }
        if (!empty($q['filter_by_type'])) {
            $where[] = 'type = ?';
            $params[] = (string)$q['filter_by_type'];
        }
        $where = implode(' AND ', $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM Attachments WHERE $where");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT id, note_id, name, path, type, size, created_at FROM Attachments WHERE $where
             ORDER BY $sortBy $sortOrder, id $sortOrder LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return [
            'data' => array_map([self::class, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination' => [
                'total_items' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Stores one uploaded file (an entry of $_FILES) for a note.
     *
     * @throws AttachmentException with a message fit for the user
     */
    public function store(string $noteId, array $file): array
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        ];
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($error) || $error !== UPLOAD_ERR_OK) {
            throw new AttachmentException($errors[is_array($error) ? UPLOAD_ERR_NO_FILE : $error] ?? 'The upload failed on the server.', 400);
        }
        if (($file['size'] ?? 0) > self::MAX_FILE_SIZE) {
            throw new AttachmentException('Files can be at most ' . (self::MAX_FILE_SIZE / 1024 / 1024) . ' MB.', 413);
        }

        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? 'file')));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            throw new AttachmentException(
                'This file type is not accepted. Allowed: ' . implode(', ', array_keys(self::TYPES)) . '.', 415
            );
        }
        // The content must agree with the extension for images and PDFs.
        $type = self::TYPES[$extension];
        if (class_exists('finfo') && (strpos($type, 'image/') === 0 || $type === 'application/pdf')) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if ($detected && $detected !== $type) {
                throw new AttachmentException("The file does not look like a .$extension file.", 415);
            }
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM Notes WHERE id = ?');
        $exists->execute([$noteId]);
        if (!$exists->fetchColumn()) {
            throw new AttachmentException('Note not found.', 404);
        }

        $folder = gmdate('Y') . '/' . gmdate('m');
        $dir = rtrim(UPLOADS_DIR, '/\\') . '/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AttachmentException('The uploads folder cannot be created. Check its permissions.', 500);
        }
        $stem = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($name, PATHINFO_FILENAME)), '_');
        $stored = bin2hex(random_bytes(6)) . '_' . substr($stem === '' ? 'file' : $stem, 0, 80) . '.' . $extension;
        $target = $dir . '/' . $stored;
        if (!@move_uploaded_file($file['tmp_name'], $target) && !@rename($file['tmp_name'], $target)) {
            throw new AttachmentException('The file could not be saved. Check the permissions of the uploads folder.', 500);
        }

        try {
            $id = notd_write($this->pdo, function (PDO $pdo) use ($noteId, $name, $folder, $stored, $type, $file) {
                $pdo->prepare('INSERT INTO Attachments (note_id, name, path, type, size) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$noteId, $name, $folder . '/' . $stored, $type, (int)$file['size']]);
                return (int)$pdo->lastInsertId();
            });
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        return self::shape([
            'id' => $id, 'note_id' => $noteId, 'name' => $name, 'path' => $folder . '/' . $stored,
            'type' => $type, 'size' => (int)$file['size'], 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT path FROM Attachments WHERE id = ?');
        $stmt->execute([$id]);
        $path = $stmt->fetchColumn();
        if ($path === false) {
            throw new AttachmentException('Attachment not found', 404);
        }
        $this->pdo->prepare('DELETE FROM Attachments WHERE id = ?')->execute([$id]);
        $file = self::resolve((string)$path);
        if ($file !== null) {
            @unlink($file);
        }
    }

    /** The absolute file for a stored path, or null if it is missing or outside UPLOADS_DIR. */
    public static function resolve(string $path): ?string
    {
        $root = realpath(UPLOADS_DIR);
        $file = $root === false ? false : realpath($root . '/' . $path);
        if ($file === false || strncmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 || !is_file($file)) {
            return null;
        }
        return $file;
    }

    /** Sends a stored file to the browser (used when UPLOADS_DIR is not web-accessible). */
    public function send(string $path): void
    {
        $stmt = $this->pdo->prepare('SELECT name, type FROM Attachments WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $file = $row ? self::resolve($path) : null;
        if ($file === null) {
            throw new AttachmentException('File not found', 404);
        }
        header('Content-Type: ' . $row['type']);
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=31536000, immutable');
        $inline = strpos($row['type'], 'image/') === 0 || $row['type'] === 'application/pdf';
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($row['name'], '"\\') . '"');
        readfile($file);
    }
}
