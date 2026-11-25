<?php
// Loads the core and wires its parts together. Endpoints, tools and tests
// require this one file.

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nodes.php';
require_once __DIR__ . '/sortkeys.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/indexer.php';

Ops::$listeners['index'] = [Indexer::class, 'onChange'];
