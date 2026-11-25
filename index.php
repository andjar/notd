<?php
// The one entry point. Under PHP's built-in server it is also the router:
//   php -S 127.0.0.1:8080 index.php
// Only files under client/ and plugins/ are served as they are; everything
// else goes through the server, so the database and server code are never
// handed out as files.

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if (preg_match('#^/(client|plugins)/[^\0]*$#', $path) && !str_contains($path, '..')
        && !str_ends_with($path, '.php') && is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/server/http.php';

Http::handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
