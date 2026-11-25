<?php
// The plugin host. A plugin is a folder with a plugin.json:
//
//   { "name": "tasks", "version": "1.0.0", "api": 1, "description": "...",
//     "browser": "main.js", "server": "server.php", "styles": ["tasks.css"] }
//
// Plugins live in plugins/ (and any other folder in plugin_dirs). All found
// plugins are on unless switched off; the list of switched-off ones is kept in
// the notebook. In safe mode (the X-Notd-Safe header, or safe_mode in the
// config) no plugin code runs on the server.

declare(strict_types=1);

const PLUGIN_API = 1;

/** What a plugin's server.php gets to register with. */
final class PluginApi
{
    public function __construct(public readonly string $name)
    {
    }

    /** Fields read from a block's text, indexed like {key::value}: fn(string $text): list of [key, value]. */
    public function indexer(callable $fn): void
    {
        Indexer::$extra[$this->name . ':' . count(Indexer::$extra)] = $fn;
    }

    /** A query filter, as in "task:open": fn(string $operator, string $value): [sql, params] on the nodes table n. */
    public function filter(string $key, callable $fn): void
    {
        Query::$filters[strtolower($key)] = $fn;
    }

    /** Called after every change: fn(PDO $pdo, string $op, string $nodeId). */
    public function onChange(callable $fn): void
    {
        $name = $this->name;
        Ops::$listeners["plugin:$name:" . count(Ops::$listeners)] = $fn;
    }
}

final class Plugins
{
    /** @var array<string, string> name => failure message, for plugins whose server code failed to load */
    public static array $failed = [];
    private static bool $loaded = false;

    /** @return list<string> */
    public static function dirs(): array
    {
        $dirs = Config::get('plugin_dirs', dirname(__DIR__) . '/plugins');
        return array_values(array_filter(array_map('trim', explode(PATH_SEPARATOR, (string) $dirs)), 'is_dir'));
    }

    /** Every plugin found, by name: its manifest plus 'dir' and 'problem' (null when it can be used). */
    public static function all(): array
    {
        $found = [];
        foreach (self::dirs() as $dir) {
            foreach (glob("$dir/*/plugin.json") ?: [] as $file) {
                $folder = basename(dirname($file));
                if (isset($found[$folder])) {
                    continue; // the first folder in plugin_dirs wins
                }
                $m = json_decode((string) file_get_contents($file), true);
                $problem = null;
                if (!is_array($m)) {
                    $m = [];
                    $problem = 'plugin.json is not valid JSON';
                } elseif (($m['name'] ?? null) !== $folder) {
                    $problem = "its name must be its folder name, \"$folder\"";
                } elseif (($m['api'] ?? null) !== PLUGIN_API) {
                    $problem = 'it needs plugin API ' . json_encode($m['api'] ?? null) . '; this notd offers ' . PLUGIN_API;
                }
                $found[$folder] = ['name' => $folder] + $m + ['dir' => dirname($file), 'problem' => $problem];
            }
        }
        ksort($found);
        return $found;
    }

    public static function disabled(PDO $pdo): array
    {
        $v = $pdo->query("SELECT value FROM meta WHERE key = 'plugins_disabled'")->fetchColumn();
        $list = is_string($v) ? json_decode($v, true) : [];
        return is_array($list) ? $list : [];
    }

    public static function setEnabled(PDO $pdo, string $name, bool $enabled): void
    {
        if (!isset(self::all()[$name])) {
            throw new HttpError(404, 'not_found', "No plugin \"$name\".");
        }
        $off = array_values(array_diff(self::disabled($pdo), [$name]));
        if (!$enabled) {
            $off[] = $name;
            sort($off);
        }
        Db::write($pdo, fn(PDO $p) => $p->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('plugins_disabled', ?)")->execute([json_encode($off)]));
    }

    public static function safe(): bool
    {
        return ($_SERVER['HTTP_X_NOTD_SAFE'] ?? '') === '1' || Config::get('safe_mode') === '1';
    }

    /** The list for the browser: what each plugin is, whether it is on, and what to load. */
    public static function listing(PDO $pdo): array
    {
        $off = self::disabled($pdo);
        $out = [];
        foreach (self::all() as $name => $p) {
            $out[] = [
                'name' => $name, 'version' => (string) ($p['version'] ?? ''), 'description' => (string) ($p['description'] ?? ''),
                'enabled' => !in_array($name, $off, true), 'problem' => $p['problem'] ?? self::$failed[$name] ?? null,
                'browser' => isset($p['browser']) ? "plugins/$name/" . $p['browser'] : null,
                'styles' => array_map(fn($s) => "plugins/$name/$s", (array) ($p['styles'] ?? [])),
            ];
        }
        return $out;
    }

    /** Runs the server code of every usable, enabled plugin, once per request. A plugin that throws is left out. */
    public static function load(PDO $pdo): void
    {
        if (self::$loaded || self::safe()) {
            return;
        }
        self::$loaded = true;
        $off = self::disabled($pdo);
        $actionDirs = ['actions' => (string) Config::get('actions_dir', dirname(__DIR__) . '/actions')];
        foreach (self::all() as $name => $p) {
            if ($p['problem'] === null && !in_array($name, $off, true) && is_dir($p['dir'] . '/actions')) {
                $actionDirs[$name] = $p['dir'] . '/actions';
            }
        }
        foreach (self::all() as $name => $p) {
            if ($p['problem'] !== null || in_array($name, $off, true) || !isset($p['server'])) {
                continue;
            }
            try {
                $setup = require $p['dir'] . '/' . $p['server'];
                if (!is_callable($setup)) {
                    throw new RuntimeException('server.php must return a function');
                }
                $setup(new PluginApi($name));
            } catch (Throwable $e) {
                self::$failed[$name] = $e->getMessage();
                error_log("notd: plugin $name: $e");
            }
        }
        Actions::load(array_filter($actionDirs, 'is_dir'));
    }

    /** Serves a file from a plugin's folder (browser code, styles, pictures); never PHP or plugin.json. */
    public static function file(string $name, string $path): FileResponse
    {
        $p = self::all()[$name] ?? null;
        $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $abs = $p ? realpath($p['dir'] . '/' . $path) : false;
        if (!$p || !isset($types[$ext]) || $path === 'plugin.json' || $abs === false || !str_starts_with($abs, realpath($p['dir']) . '/') || !is_file($abs)) {
            throw new HttpError(404, 'not_found', "No such file: plugins/$name/$path");
        }
        return new FileResponse($abs, $types[$ext], basename($abs), md5_file($abs), true);
    }
}
