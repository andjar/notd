<?php
// POST /api/v1/ops: apply a batch of operations atomically.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return [
    'POST' => function (Request $r): array {
        $body = $r->body();
        if (!isset($body['ops'])) {
            throw new HttpError(400, 'bad_request', 'The body needs "ops", a list of operations.');
        }
        return Ops::apply(Db::get(), $body['ops']);
    },
];
