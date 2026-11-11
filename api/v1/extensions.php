<?php
/**
 * GET /api/v1/extensions.php
 * The extensions listed in ACTIVE_EXTENSIONS, with the icon from each one's config.json.
 */

require_once __DIR__ . '/../bootstrap.php';

$extensions = [];
foreach (defined('ACTIVE_EXTENSIONS') && is_array(ACTIVE_EXTENSIONS) ? ACTIVE_EXTENSIONS : [] as $name) {
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        continue;
    }
    $configPath = __DIR__ . '/../../extensions/' . $name . '/config.json';
    $config = is_file($configPath) ? json_decode((string)file_get_contents($configPath), true) : null;
    if (is_array($config) && isset($config['featherIcon'])) {
        $extensions[] = ['name' => $name, 'featherIcon' => $config['featherIcon']];
    }
}
\App\ApiResponse::success(['extensions' => $extensions]);
