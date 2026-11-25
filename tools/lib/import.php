<?php
// Import (a tool, not part of the core; see decision 12): reads a folder of Markdown (a notd export, a Logseq graph, or plain
// notes) and creates its pages through the operations pipeline, so imported
// notes are indexed and searchable like any other.
//
// What it understands: title:: and other key:: value lines (as {key:: value}
// fields), bullets (- * +, indented by tabs or spaces), id:: and collapsed::,
// fenced code, Logseq journals (2026_11_25.md) and namespaces (A___B.md),
// Logseq task words, SCHEDULED/DEADLINE, logbooks, journal links like
// [[Nov 13th, 2023]], and links to files in the folder (stored and relinked).

declare(strict_types=1);

const IMPORT_TASKS = ['LATER' => 'TODO', 'NOW' => 'DOING', 'WAIT' => 'WAITING', 'CANCELED' => 'CANCELLED'];
const IMPORT_DROP = ['id', 'collapsed', 'created-at', 'updated-at', 'title', 'heading'];

final class Import
{
    /** Imports every .md file under $dir; returns ['pages' => n, 'blocks' => n, 'files' => n, 'warnings' => [...]]. */
    public static function folder(PDO $pdo, string $dir): array
    {
        $dir = rtrim($dir, '/');
        $stats = ['pages' => 0, 'blocks' => 0, 'files' => 0, 'warnings' => []];
        $pages = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if (preg_match('#(^|/)(logseq|\.git|node_modules|files|assets|whiteboards|draws)/#', $rel)) {
                continue;
            }
            if (!str_ends_with($rel, '.md')) {
                if (preg_match('/\.(org)$/', $rel)) {
                    $stats['warnings'][] = "$rel: only Markdown is imported";
                }
                continue;
            }
            $pages[] = self::parse((string) file_get_contents($f->getPathname()), $rel);
        }
        usort($pages, fn($a, $b) => strcmp($a['file'], $b['file']));
        $renames = [];
        foreach ($pages as &$p) {
            $clean = str_replace(['[', ']'], ['(', ')'], $p['title']);
            if ($clean !== $p['title']) {
                $renames['[[' . $p['title'] . ']]'] = '[[' . $clean . ']]';
                $stats['warnings'][] = "{$p['file']}: renamed to \"$clean\" (titles cannot hold square brackets)";
                $p['title'] = $clean;
            }
        }
        unset($p);
        $seen = [];
        foreach ($pages as $p) {
            $fix = function (string $text) use ($renames, $dir, $p, $pdo, &$stats): string {
                $text = strtr($text, $renames);
                $text = preg_replace_callback('/\[\[([A-Z][a-z]{2}) (\d{1,2})(?:st|nd|rd|th), (\d{4})\]\]/', function ($m) {
                    $t = strtotime("{$m[1]} {$m[2]} {$m[3]}");
                    return $t === false ? $m[0] : '[[' . date('Y-m-d', $t) . ']]';
                }, $text) ?? $text;
                return preg_replace_callback('/(!?\[[^\]]*\])\((?!https?:|file:|#)([^)\s]+)\)(\{[^}\n]*\})?/', function ($m) use ($dir, $p, $pdo, &$stats) {
                    $path = realpath($dir . '/' . dirname($p['file']) . '/' . rawurldecode($m[2]));
                    if ($path === false || !str_starts_with($path, realpath($dir) . '/') || !is_file($path)) {
                        return $m[0];
                    }
                    try {
                        $stats['files']++;
                        return $m[1] . '(file:' . Files::store($pdo, $path, basename($path))['id'] . ')';
                    } catch (HttpError $e) {
                        $stats['warnings'][] = "{$p['file']}: " . basename($path) . ': ' . $e->getMessage();
                        return $m[0];
                    }
                }, $text) ?? $text;
            };
            $existing = Nodes::byTitle($pdo, $p['title']);
            $pageId = $existing['id'] ?? new_id();
            $text = $fix($p['text']);
            $ops = $existing === null
                ? [['op' => 'create', 'kind' => 'page', 'id' => $pageId, 'title' => $p['title'], 'text' => $text]]
                : ($text === '' ? [] : [['op' => 'edit', 'id' => $pageId, 'text' => trim($existing['text'] . "\n" . $text)]]);
            $add = function (array $blocks, string $parent) use (&$add, &$ops, &$seen, $fix, $pdo, $p, &$stats): void {
                foreach ($blocks as $b) {
                    $id = $b['id'];
                    if ($id === null || isset($seen[$id]) || Nodes::get($pdo, $id) !== null) {
                        if ($id !== null) {
                            $stats['warnings'][] = "{$p['file']}: id $id is used twice; the second block gets a new id";
                        }
                        $id = new_id();
                    }
                    $seen[$id] = true;
                    $ops[] = ['op' => 'create', 'id' => $id, 'parent' => $parent, 'text' => $fix($b['text']), 'collapsed' => $b['collapsed']];
                    $add($b['children'], $id);
                }
            };
            $add($p['blocks'], $pageId);
            foreach (array_chunk($ops, OPS_MAX) as $chunk) {
                Ops::apply($pdo, $chunk);
            }
            $stats['pages']++;
            $stats['blocks'] += count($ops) - ($existing === null ? 1 : 0);
        }
        return $stats;
    }

    /** One file: ['file', 'title', 'text', 'blocks' => [['id', 'text', 'collapsed', 'children']]]. */
    public static function parse(string $md, string $file): array
    {
        $md = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $md) ?? $md);
        $name = basename($file, '.md');
        $title = preg_match('/^(\d{4})_(\d{2})_(\d{2})$/', $name, $d) ? "$d[1]-$d[2]-$d[3]" : str_replace('___', '/', rawurldecode($name));
        $root = ['children' => []];
        $stack = [[-1, &$root]];
        $head = [];
        $cur = null;
        $fence = false;
        foreach (explode("\n", $md) as $line) {
            $expanded = str_replace("\t", '  ', $line);
            $indent = strlen($expanded) - strlen(ltrim($expanded, ' '));
            if (!$fence && preg_match('/^ *[-*+](?: (.*))?$/', $expanded, $m)) {
                while ($stack[count($stack) - 1][0] >= $indent) {
                    array_pop($stack);
                }
                $parent = &$stack[count($stack) - 1][1];
                $parent['children'][] = ['id' => null, 'lines' => [$m[1] ?? ''], 'collapsed' => false, 'children' => [], 'indent' => $indent];
                $cur = &$parent['children'][count($parent['children']) - 1];
                $stack[] = [$indent, &$cur];
                unset($parent);
                continue;
            }
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fence = !$fence;
            }
            if ($cur === null) {
                $head[] = $line;
            } else {
                $cur['lines'][] = substr($expanded, min($indent, $cur['indent'] + 2));
            }
        }
        unset($cur);
        $pageText = [];
        foreach ($head as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):: ?(.*)$/', $line, $m)) {
                if (strtolower($m[1]) === 'title') {
                    $title = trim($m[2]);
                } elseif (!in_array(strtolower($m[1]), IMPORT_DROP, true)) {
                    $pageText[] = '{' . $m[1] . ':: ' . trim($m[2]) . '}';
                }
            } else {
                $pageText[] = preg_replace('/^\\\\(\\\\*- )/', '$1', $line);
            }
        }
        $blocks = array_map([self::class, 'block'], $root['children']);
        if ($blocks && $blocks[0]['propsOnly'] && !$blocks[0]['children']) {
            $pageText[] = array_shift($blocks)['text'];     // Logseq keeps page properties in a first block
        }
        return ['file' => $file, 'title' => trim($title), 'text' => trim(implode("\n", $pageText)), 'blocks' => $blocks];
    }

    private static function block(array $b): array
    {
        $lines = [];
        $props = 0;
        $logbook = false;
        $first = array_shift($b['lines']);
        $first = preg_replace_callback('/^(LATER|NOW|WAIT|CANCELED) /', fn($m) => IMPORT_TASKS[$m[1]] . ' ', $first) ?? $first;
        foreach ([$first, ...$b['lines']] as $i => $line) {
            if (preg_match('/^:LOGBOOK:\s*$/', $line)) {
                $logbook = true;
            } elseif ($logbook) {
                $logbook = !preg_match('/^:END:\s*$/', $line);
            } elseif (preg_match('/^(SCHEDULED|DEADLINE): <(\d{4}-\d{2}-\d{2})[^>]*>\s*$/', $line, $m)) {
                $lines[] = '{' . strtolower($m[1]) . ':: ' . $m[2] . '}';
            } elseif (preg_match('/^([A-Za-z0-9_-]+):: ?(.*)$/', $line, $m)) {
                $key = strtolower($m[1]);
                $props++;
                if ($key === 'id' && is_id(strtolower(trim($m[2])))) {
                    $b['id'] = strtolower(trim($m[2]));
                } elseif ($key === 'collapsed') {
                    $b['collapsed'] = trim($m[2]) === 'true';
                } elseif ($key === 'heading' && ctype_digit(trim($m[2])) && $lines && !str_starts_with($lines[0], '#')) {
                    $lines[0] = str_repeat('#', min(6, (int) $m[2])) . ' ' . $lines[0];
                } elseif (!in_array($key, IMPORT_DROP, true)) {
                    $lines[] = '{' . $m[1] . ':: ' . trim($m[2]) . '}';
                }
            } else {
                $lines[] = $line;
            }
        }
        $text = rtrim(implode("\n", $lines));
        return ['id' => $b['id'], 'text' => $text, 'collapsed' => $b['collapsed'], 'children' => array_map([self::class, 'block'], $b['children']),
            'propsOnly' => $props > 0 && $props === count($b['lines']) + 1];
    }
}
