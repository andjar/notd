<?php
// Files: uploads are stored once per identical content, under
// data/files/YYYY/MM/<sha256>.<ext>, each described by a node of kind "file".

declare(strict_types=1);

/** Allowed extensions and the content types they may have (as PHP's finfo sees them). */
const FILE_TYPES = [
    'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'gif' => ['image/gif'],
    'webp' => ['image/webp'], 'avif' => ['image/avif'], 'svg' => ['image/svg+xml', 'text/xml', 'text/plain'],
    'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'md' => ['text/plain', 'text/markdown'],
    'csv' => ['text/csv', 'text/plain', 'application/csv'], 'json' => ['application/json', 'text/plain'], 'excalidraw' => ['application/json', 'text/plain'],
    'mp3' => ['audio/mpeg'], 'm4a' => ['audio/mp4', 'audio/x-m4a'], 'wav' => ['audio/x-wav', 'audio/wav'],
    'ogg' => ['audio/ogg', 'video/ogg'], 'mp4' => ['video/mp4'], 'webm' => ['video/webm'],
    'zip' => ['application/zip'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
];
const FILE_MAX_BYTES = 50 * 1024 * 1024;

final class Files
{
    /**
     * Stores a file and returns its node. An identical file that is already stored returns
     * the existing node, so the same content is never kept twice.
     */
    public static function store(PDO $pdo, string $tmpPath, string $name): array
    {
        $parts = preg_split('#[/\\\\]#', $name) ?: [''];
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) end($parts)) ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !isset(FILE_TYPES[$ext])) {
            throw new HttpError(415, 'bad_type', "Files of type .$ext are not accepted.");
        }
        $size = filesize($tmpPath);
        if ($size === false || $size > FILE_MAX_BYTES) {
            throw new HttpError(413, 'too_large', 'A file can be at most ' . (FILE_MAX_BYTES >> 20) . ' MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';
        $isText = in_array('text/plain', FILE_TYPES[$ext], true) && mb_check_encoding((string) file_get_contents($tmpPath), 'UTF-8');
        if (!in_array($mime, FILE_TYPES[$ext], true) && !$isText) {
            throw new HttpError(415, 'bad_type', "The content ($mime) does not match the name .$ext.");
        }
        $mime = FILE_TYPES[$ext][0];
        $sha = hash_file('sha256', $tmpPath);
        return Db::write($pdo, function (PDO $pdo) use ($tmpPath, $name, $ext, $size, $mime, $sha): array {
            $st = $pdo->prepare('SELECT node_id FROM files WHERE sha256 = ?');
            $st->execute([$sha]);
            if (($existing = $st->fetchColumn()) !== false) {
                return self::node($pdo, $existing);
            }
            $rel = 'files/' . gmdate('Y/m') . "/$sha.$ext";
            $abs = Config::dataDir() . '/' . $rel;
            if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0770, true) && !is_dir(dirname($abs))) {
                throw new RuntimeException('Cannot create ' . dirname($abs));
            }
            if (!is_file($abs) && !copy($tmpPath, $abs)) {
                throw new RuntimeException("Cannot write $abs");
            }
            $id = new_id();
            $now = now_ms();
            $pdo->prepare("INSERT INTO nodes (id, kind, page_id, title, created, updated) VALUES (?, 'file', ?, ?, ?, ?)")
                ->execute([$id, $id, $name, $now, $now]);
            $pdo->prepare('INSERT INTO files (node_id, sha256, size, mime, path) VALUES (?, ?, ?, ?, ?)')->execute([$id, $sha, $size, $mime, $rel]);
            Ops::changed($pdo, 'create', $id);
            return self::node($pdo, $id);
        });
    }

    /** A file node with its file details: sha256, size, mime, url. */
    public static function node(PDO $pdo, string $id): array
    {
        $st = $pdo->prepare('SELECT n.*, f.sha256, f.size, f.mime, f.path FROM nodes n JOIN files f ON f.node_id = n.id WHERE n.id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row === false) {
            throw new HttpError(404, 'not_found', "No file $id.");
        }
        return Nodes::shape($row) + ['sha256' => $row['sha256'], 'size' => (int) $row['size'], 'mime' => $row['mime'],
            'url' => 'api/v1/file?id=' . $row['id'], 'path' => $row['path']];
    }

    public static function response(PDO $pdo, string $id): FileResponse
    {
        $f = self::node($pdo, $id);
        $abs = Config::dataDir() . '/' . $f['path'];
        if (!is_file($abs)) {
            throw new HttpError(404, 'not_found', "The content of file $id is missing; run tools/verify.php.");
        }
        return new FileResponse($abs, $f['mime'], $f['title'], $f['sha256']);
    }

    /**
     * Checks every stored file: missing content, content that does not match its SHA-256, and
     * files in the folder that no node describes. With $clean, the last are deleted.
     */
    public static function verify(PDO $pdo, bool $clean = false): array
    {
        $dir = Config::dataDir();
        $report = ['checked' => 0, 'missing' => [], 'corrupt' => [], 'orphans' => []];
        $known = [];
        foreach ($pdo->query('SELECT node_id, sha256, path FROM files') as $row) {
            $report['checked']++;
            $known[$row['path']] = true;
            $abs = "$dir/{$row['path']}";
            if (!is_file($abs)) {
                $report['missing'][] = $row['path'];
            } elseif (hash_file('sha256', $abs) !== $row['sha256']) {
                $report['corrupt'][] = $row['path'];
            }
        }
        if (is_dir("$dir/files")) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/files", FilesystemIterator::SKIP_DOTS)) as $f) {
                $rel = substr($f->getPathname(), strlen($dir) + 1);
                if (!isset($known[$rel])) {
                    $report['orphans'][] = $rel;
                    if ($clean) {
                        unlink($f->getPathname());
                    }
                }
            }
        }
        return $report;
    }
}
