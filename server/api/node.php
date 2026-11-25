<?php
// GET /api/v1/node?id=...: a node and everything under it.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): array => Read::node(Db::get(), $r->string('id'))];
