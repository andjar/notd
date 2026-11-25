<?php
// GET /api/v1/file?id=...: the content of a file.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['GET' => fn(Request $r): FileResponse => Files::response(Db::get(), $r->string('id'))];
