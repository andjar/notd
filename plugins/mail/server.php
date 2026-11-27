<?php
// The mail plugin, server side: POST /api/v1/mail.add with an .eml file (multipart,
// field "file", and optionally "page") adds it as a note; see lib/mail.php.

declare(strict_types=1);

require_once __DIR__ . '/lib/mail.php';

return function (PluginApi $notd): void {
    $notd->endpoint('add', [
        'POST' => function (Request $r): array {
            $f = $r->files['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null)) {
                throw new HttpError(400, 'bad_request', 'Send the message as multipart form data in the field "file".');
            }
            $page = $r->form['page'] ?? null;
            return Mail::add(Db::get(), (string) file_get_contents($f['tmp_name']), is_string($page) ? $page : null);
        },
    ]);
};
