<?php
/**
 * Router for PHP's built-in web server:
 *
 *     php -S localhost:8000 router.php
 *
 * The built-in server ignores .htaccess files, so without this it would hand
 * out the database and other private files to anyone who can reach it. This
 * script refuses those paths and lets everything else through.
 */

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = '/' . ltrim(str_replace('\\', '/', $path), '/');

$private = preg_match('#^/(db|tests|docs|\.git|\.github|\.cursor)(/|$)#i', $path)   // data, tests, repository internals
    || preg_match('#(^|/)\.[^/]#', $path)                                            // dot files
    || preg_match('#^/(config(\.local)?(\.example)?\.php|router\.php|composer\.(json|lock)|README\.md|LICENSE)$#i', $path)
    || preg_match('#^/uploads/.*\.(php\d?|phtml|phar)$#i', $path)                    // never run anything from uploads
    || preg_match('#^/api/(?!v1/)[^/]+\.php$#i', $path)                              // library code, not endpoints
    || preg_match('#^/assets/template/.*\.php$#i', $path);

if ($private) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Not found\n";
    return true;
}
return false; // serve the file or run the script as usual
