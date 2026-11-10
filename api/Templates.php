<?php
/**
 * Note and page templates: plain text files in assets/template/<type>/.
 *
 * Templates are data. They are read as text and never executed, and names are
 * restricted to letters, digits, space, dash and underscore, so a template can
 * neither run code nor be written outside its folder.
 *
 * Placeholders, filled in when a template is listed:
 *   {{date}} {{time}} {{datetime}} {{year}} {{month}} {{day}} {{weekday}}
 *   {{timestamp}} {{random}} {{uuid}}
 * The date ones accept a PHP date format: {{date:d.m.Y}}.
 */

namespace App;

class TemplateException extends \RuntimeException {}

class Templates
{
    private const DATE_FORMATS = [
        'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s',
        'year' => 'Y', 'month' => 'm', 'day' => 'd', 'weekday' => 'l',
    ];

    private string $dir;

    public function __construct(string $type)
    {
        if ($type !== 'note' && $type !== 'page') {
            throw new TemplateException('Invalid template type', 400);
        }
        $this->dir = __DIR__ . '/../assets/template/' . $type;
    }

    private static function checkName($name): string
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,63}$/', $name)) {
            throw new TemplateException('Template names may contain letters, digits, spaces, dashes and underscores (at most 64 characters).', 400);
        }
        return $name;
    }

    /** The file of a template, or null. `.md` is the format; `.php` files from older versions are read as text. */
    private function path(string $name): ?string
    {
        foreach (['.md', '.php'] as $extension) {
            if (is_file($this->dir . '/' . $name . $extension)) {
                return $this->dir . '/' . $name . $extension;
            }
        }
        return null;
    }

    private static function read(string $path): string
    {
        $text = (string)file_get_contents($path);
        if (substr($path, -4) === '.php') {
            // Templates from older versions start with a PHP comment block; it is not part of the template.
            $text = preg_replace('/^\s*<\?php.*?\?>\s*\n?/s', '', $text, 1);
        }
        return $text;
    }

    /**
     * @param string|null $timezone IANA zone used for date placeholders (default UTC)
     * @return array<int, array{name: string, content: string, raw: string}>
     */
    public function all(?string $timezone = null): array
    {
        $names = [];
        foreach (glob($this->dir . '/*.{md,php}', GLOB_BRACE) ?: [] as $file) {
            $names[pathinfo($file, PATHINFO_FILENAME)] = true;
        }
        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $now = self::now($timezone);
        $templates = [];
        foreach (array_keys($names) as $name) {
            $raw = self::read($this->path((string)$name));
            $templates[] = ['name' => (string)$name, 'content' => self::fill($raw, $now), 'raw' => $raw];
        }
        return $templates;
    }

    private static function now(?string $timezone): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable('now', new \DateTimeZone($timezone ?: 'UTC'));
        } catch (\Exception $e) {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    public static function fill(string $content, \DateTimeImmutable $now): string
    {
        return preg_replace_callback(
            '/\{\{(date|datetime|time|year|month|day|weekday|timestamp|random|uuid)(?::([^}]+))?\}\}/',
            static function (array $m) use ($now): string {
                switch ($m[1]) {
                    case 'timestamp': return (string)$now->getTimestamp();
                    case 'random': return (string)random_int(0, 2147483647);
                    case 'uuid': return Ordering::newId();
                    default: return $now->format($m[2] ?? self::DATE_FORMATS[$m[1]]);
                }
            },
            $content
        );
    }

    public function save(string $name, string $content): void
    {
        $name = self::checkName($name);
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true)) {
            throw new TemplateException('The template folder cannot be created.', 500);
        }
        if (@file_put_contents($this->dir . '/' . $name . '.md', $content, LOCK_EX) === false) {
            throw new TemplateException('The template could not be saved. Check the permissions of assets/template.', 500);
        }
        if (is_file($this->dir . '/' . $name . '.php')) {
            @unlink($this->dir . '/' . $name . '.php'); // replaced by the .md file
        }
    }

    public function exists(string $name): bool
    {
        return $this->path(self::checkName($name)) !== null;
    }

    public function delete(string $name): void
    {
        $path = $this->path(self::checkName($name));
        if ($path === null) {
            throw new TemplateException('Template not found', 404);
        }
        if (!@unlink($path)) {
            throw new TemplateException('The template could not be deleted.', 500);
        }
    }
}
