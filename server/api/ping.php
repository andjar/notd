<?php
// GET /api/v1/ping: is the server up, which protocol does it speak, and may this client in?

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return [
    'GET' => fn(Request $r): array => ['name' => 'notd', 'version' => NOTD_VERSION, 'protocol' => NOTD_PROTOCOL] + Auth::status(),
];
