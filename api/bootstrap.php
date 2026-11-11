<?php
/**
 * The one include every endpoint and script needs.
 *
 * Loads the configuration, opens the door to the database
 * (get_db_connection()), and loads classes in `namespace App` on demand from
 * api/<ClassName>.php.
 */

require_once __DIR__ . '/../config.php';

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $name = substr($class, 4);
    // Classes that share a file with a related class.
    static $shared = [
        'ApiResponse' => 'response_utils',
        'Validator' => 'validator_utils',
        'OpValidationException' => 'NoteOps',
        'PageException' => 'PageStore',
        'AttachmentException' => 'Attachments',
        'TemplateException' => 'Templates',
        'SchemaVersionException' => 'db_connect',
    ];
    $file = __DIR__ . '/' . ($shared[$name] ?? $name) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/response_utils.php';

/**
 * Requests that change data must come from notd itself (or from a script).
 *
 * notd has no login, so without this any web page open in the same browser
 * could quietly send it requests. Browsers label where a request comes from
 * (Sec-Fetch-Site, Origin); a request another site initiated is refused.
 * Scripts and command-line tools send neither header and are let through.
 */
function notd_reject_cross_site_writes(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS' || PHP_SAPI === 'cli') {
        return;
    }
    if (defined('ALLOW_CROSS_SITE_REQUESTS') && ALLOW_CROSS_SITE_REQUESTS) {
        return;
    }
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($site !== null) {
        // Sent by current browsers on https and localhost.
        $foreign = $site !== 'same-origin' && $site !== 'none';
    } elseif ($origin !== null) {
        // Plain http on a LAN address: compare the Origin with the host that was asked for.
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $hosts = array_filter([$_SERVER['HTTP_HOST'] ?? '', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '']);
        $foreign = true;
        foreach ($hosts as $host) {
            if ($originHost !== '' && $originHost === strtolower(preg_replace('/:\d+$/', '', trim(explode(',', $host)[0])))) {
                $foreign = false;
            }
        }
    } else {
        $foreign = false; // not a browser
    }
    if ($foreign) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Cross-site requests are not accepted.']);
        exit;
    }
}
notd_reject_cross_site_writes();

/** The decoded JSON body of the request ([] when there is none). */
function notd_json_input(): array
{
    static $input = null;
    if ($input === null) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
    }
    return $input;
}

/**
 * The request method. PUT and DELETE may be sent as POST with
 * {"_method": "PUT"|"DELETE"} in the JSON body or as a form field, because
 * some embedded web servers (phpdesktop) only pass GET and POST.
 */
function notd_request_method(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'POST') {
        $override = $_POST['_method'] ?? (notd_json_input()['_method'] ?? null);
        if (is_string($override) && in_array(strtoupper($override), ['PUT', 'DELETE'], true)) {
            return strtoupper($override);
        }
    }
    return $method;
}

/** A boolean query parameter: 1/true/yes/on are true. */
function notd_query_flag(string $name, bool $default = false): bool
{
    if (!isset($_GET[$name])) {
        return $default;
    }
    $value = filter_var($_GET[$name], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $value ?? $default;
}
