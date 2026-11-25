<?php
// GET /api/v1/plugins: every plugin found, whether it is on, and what the browser loads.
// POST /api/v1/plugins {"name": "...", "enabled": false}: switches a plugin on or off.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return [
    'GET' => fn(Request $r): array => ['safe' => Plugins::safe(), 'api' => PLUGIN_API, 'plugins' => Plugins::listing(Db::get())],
    'POST' => function (Request $r): array {
        $b = $r->body();
        if (!is_string($b['name'] ?? null) || !is_bool($b['enabled'] ?? null)) {
            throw new HttpError(400, 'bad_request', 'Send {"name": "...", "enabled": true or false}.');
        }
        Plugins::setEnabled(Db::get(), $b['name'], $b['enabled']);
        return ['plugins' => Plugins::listing(Db::get())];
    },
];
