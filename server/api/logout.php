<?php
// POST /api/v1/logout: removes the login cookie.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['POST' => function (Request $r): array {
    Auth::logout();
    return ['authenticated' => false];
}];
