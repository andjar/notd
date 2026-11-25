<?php
// POST /api/v1/login {"password": "..."}: sets the login cookie.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return [
    'POST' => function (Request $r): array {
        $password = $r->body()['password'] ?? null;
        if (!is_string($password)) {
            throw new HttpError(400, 'bad_request', 'Send {"password": "..."}.');
        }
        Auth::login(Db::get(), $password);
        return ['authenticated' => true];
    },
];
