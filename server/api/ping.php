<?php
// GET /api/v1/ping: is the server up, and which protocol does it speak?

declare(strict_types=1);

return [
    'GET' => fn(Request $r): array => ['name' => 'notd', 'version' => NOTD_VERSION, 'protocol' => NOTD_PROTOCOL],
];
