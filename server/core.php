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
require_once __DIR__ . '/read.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/feed.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/plugins.php';

Ops::$listeners['index'] = [Indexer::class, 'onChange'];
Ops::$listeners['search'] = [Search::class, 'onChange'];
