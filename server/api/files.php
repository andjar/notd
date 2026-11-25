<?php
// POST /api/v1/files (multipart, field "file"): store a file; returns its node.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return [
    'POST' => function (Request $r): array {
        $f = $r->files['file'] ?? null;
        if (!is_array($f) || !is_string($f['tmp_name'] ?? null)) {
            throw new HttpError(400, 'bad_request', 'Send the file as multipart form data in the field "file".');
        }
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $big = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new HttpError($big ? 413 : 400, $big ? 'too_large' : 'bad_request', 'The upload failed (PHP error ' . $f['error'] . ').');
        }
        return Files::store(Db::get(), $f['tmp_name'], (string) ($f['name'] ?? ''));
    },
];
