<?php
// Import (a tool, not part of the core; see decision 12): reads a folder of Markdown (a notd export, a Logseq graph, or plain
// notes) and creates its pages through the operations pipeline, so imported
// notes are indexed and searchable like any other.
//
// What it understands: title:: and other key:: value lines (as {key:: value}
// fields), bullets (- * +, indented by tabs or spaces), id:: and collapsed::,
// heading::, fenced code, Logseq journals and namespaces (A___B.md, or the
// legacy A.B.md), Logseq task words and priorities ([#A]), SCHEDULED/DEADLINE,
// logbooks, #+BEGIN_QUOTE and friends, journal links in the graph's own title
// format ([[Nov 13th, 2023]]), the favourites in logseq/config.edn, and links
// to files in the folder (stored and relinked).
//
// Importing the same folder again adds only what is new: a block keeps its
// Logseq id, or gets one derived from where it is and what it says, and a block
// whose id is already in the notebook is left alone (with any edits made since).

declare(strict_types=1);

const IMPORT_TASKS = ['LATER' => 'TODO', 'NOW' => 'DOING', 'WAIT' => 'WAITING', 'CANCELED' => 'CANCELLED'];
const IMPORT_DROP = ['id', 'collapsed', 'created-at', 'updated-at', 'title', 'heading', 'filters', 'public', 'background-color',
    'exclude-from-graph-view'];
const IMPORT_MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
const IMPORT_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

final class Import
{
    /** Imports every .md file under $dir; returns ['pages' => n, 'blocks' => n, 'files' => n, 'warnings' => [...]]. */
    public static function folder(PDO $pdo, string $dir): array
    {
        $dir = rtrim($dir, '/');
        $cfg = self::config($dir);
        $stats = ['pages' => 0, 'blocks' => 0, 'files' => 0, 'warnings' => []];
        $pages = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if (preg_match('#(^|/)(logseq|\.git|node_modules|files|assets|whiteboards|draws)/#', $rel)) {
                continue;
            }
            if (!str_ends_with($rel, '.md')) {
                if (preg_match('/\.(org)$/', $rel)) {
                    $stats['warnings'][] = "$rel: only Markdown is imported";
                }
                continue;
            }
            $pages[] = self::parse((string) file_get_contents($f->getPathname()), $rel, $cfg);
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
            $fix = function (string $text) use ($renames, $dir, $p, $pdo, $cfg, &$stats): string {
                $text = strtr($text, $renames);
                $text = preg_replace_callback('/\[\[([^\[\]\n]+)\]\]/', function ($m) use ($cfg) {
                    $day = self::matchDate($cfg['title'], $m[1]);
                    return $day === null ? $m[0] : "[[$day]]";
                }, $text) ?? $text;
                if (preg_match('/\{\{query\s/i', $text)) {
                    $stats['warnings'][] = "{$p['file']}: a Logseq query is kept as code; write it again as a notd query";
                    $text = preg_replace('/(?<!`)(\{\{query\s[^\n]*?\}\})(?!`)/i', '`$1`', $text) ?? $text;
                }
                $text = preg_replace_callback('/\[\[(draws\/[^\]\n]+\.excalidraw)\]\]/', function ($m) use ($dir, $pdo, $p, &$stats) {
                    return self::store($pdo, $dir, $m[1], '[{basename}](file:{id})', $p['file'], $stats) ?? $m[0];
                }, $text) ?? $text;
                return preg_replace_callback('/(!?\[[^\]]*\])\((?!https?:|file:|#)([^)\s]+)\)(\{[^}\n]*\})?/', function ($m) use ($dir, $p, $pdo, &$stats) {
                    return self::store($pdo, $dir, dirname($p['file']) . '/' . rawurldecode($m[2]), $m[1] . '(file:{id})', $p['file'], $stats) ?? $m[0];
                }, $text) ?? $text;
            };
            try {
                $stats['blocks'] += self::page($pdo, $p, $fix, $seen, $stats);
                $stats['pages']++;
            } catch (HttpError $e) {
                $stats['warnings'][] = "{$p['file']}: not imported: {$e->getMessage()}";
            }
        }
        self::favourites($pdo, $cfg['favorites']);
        return $stats;
    }

    /** One page's operations: creates the page or adds to it; returns how many blocks were created. */
    private static function page(PDO $pdo, array $p, callable $fix, array &$seen, array &$stats): int
    {
        $existing = Nodes::byTitle($pdo, $p['title']);
        $pageId = $existing['id'] ?? new_id();
        $text = $fix($p['text']);
        if ($existing === null) {
            $ops = [['op' => 'create', 'kind' => 'page', 'id' => $pageId, 'title' => $p['title'], 'text' => $text]];
        } else {
            $have = explode("\n", $existing['text']);       // a second import adds no line twice
            $new = array_filter(explode("\n", $text), fn($l) => trim($l) !== '' && !in_array($l, $have, true));
            $ops = $new ? [['op' => 'edit', 'id' => $pageId, 'text' => trim($existing['text'] . "\n" . implode("\n", $new))]] : [];
        }
        $created = 0;
        $add = function (array $blocks, string $parent) use (&$add, &$ops, &$seen, &$created, $fix, $pdo, $p, &$stats): void {
            $count = [];
            foreach ($blocks as $b) {
                $n = $count[$b['text']] = ($count[$b['text']] ?? 0) + 1;     // identical siblings stay apart
                $id = $b['id'];
                if ($id === null || isset($seen[$id])) {
                    if ($id !== null) {
                        $stats['warnings'][] = "{$p['file']}: id $id is used twice; the second block gets a new id";
                    }
                    $id = self::stableId("{$p['file']}\0$parent\0{$b['text']}\0$n");
                }
                $seen[$id] = true;
                if (Nodes::get($pdo, $id) === null) {         // not imported before
                    $ops[] = ['op' => 'create', 'id' => $id, 'parent' => $parent, 'text' => $fix($b['text']), 'collapsed' => $b['collapsed']];
                    $created++;
                }
                $add($b['children'], $id);
            }
        };
        $add($p['blocks'], $pageId);
        foreach (array_chunk($ops, OPS_MAX) as $chunk) {
            Ops::apply($pdo, $chunk);
        }
        return $created;
    }

    /** Stores a file of the folder ($rel from its top); returns $link with {id} and {basename} filled in, or null when it cannot. */
    private static function store(PDO $pdo, string $dir, string $rel, string $link, string $from, array &$stats): ?string
    {
        $real = realpath("$dir/$rel");
        if ($real === false || !is_file($real) || !str_starts_with($real, realpath($dir) . DIRECTORY_SEPARATOR)) {
            return null;                                    // missing, or outside the folder: the link stays as it was
        }
        try {
            $file = Files::store($pdo, $real, basename($real));
            $stats['files']++;
            return strtr($link, ['{id}' => $file['id'], '{basename}' => str_replace(['[', ']'], '', basename($real))]);
        } catch (HttpError $e) {
            $stats['warnings'][] = "$from: " . basename($real) . ': ' . $e->getMessage();
            return null;
        }
    }

    /** Adds the graph's favourites (from logseq/config.edn) to notd/favourites, once each. */
    private static function favourites(PDO $pdo, array $titles): void
    {
        if ($titles === []) {
            return;
        }
        $page = Nodes::byTitle($pdo, 'notd/favourites');
        $ops = [];
        if ($page === null) {
            $page = ['id' => new_id()];
            $ops[] = ['op' => 'create', 'kind' => 'page', 'id' => $page['id'], 'title' => 'notd/favourites'];
            $have = '';
        } else {
            $have = mb_strtolower(implode("\n", array_column(Read::page($pdo, 'notd/favourites')['blocks'], 'text')));
        }
        foreach ($titles as $t) {
            $real = Nodes::byTitle($pdo, $t)['title'] ?? $t;
            if (!str_contains($have, mb_strtolower("[[$real]]"))) {
                $ops[] = ['op' => 'create', 'parent' => $page['id'], 'text' => "[[$real]]"];
            }
        }
        if (count($ops) > ($page === null ? 1 : 0)) {
            Ops::apply($pdo, $ops);
        }
    }

    /** What logseq/config.edn says about journal titles and files, page file names and favourites (Logseq's defaults without one). */
    public static function config(string $dir): array
    {
        $cfg = ['title' => 'MMM do, yyyy', 'files' => 'yyyy_MM_dd', 'names' => 'triple-lowbar', 'favorites' => []];
        $file = "$dir/logseq/config.edn";
        if (is_file($file)) {
            $edn = preg_replace('/^((?:[^";]|"(?:[^"\\\\]|\\\\.)*")*);.*$/m', '$1', (string) file_get_contents($file)) ?? '';
            $cfg['names'] = 'legacy';                       // a graph that does not say is from before triple-lowbar
            foreach (['title' => 'journal\/page-title-format', 'files' => 'journal\/file-name-format'] as $k => $key) {
                if (preg_match('/:' . $key . '\s+"([^"]+)"/', $edn, $m)) {
                    $cfg[$k] = $m[1];
                }
            }
            if (preg_match('/:file\/name-format\s+:([a-z-]+)/', $edn, $m)) {
                $cfg['names'] = $m[1] === 'legacy' ? 'legacy' : 'triple-lowbar';
            }
            if (preg_match('/:favorites\s+\[([^\]]*)\]/', $edn, $m) && preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $fav)) {
                $cfg['favorites'] = array_map('stripcslashes', $fav[1]);
            }
        }
        $cfg['title'] = self::dateFormat($cfg['title']);
        $cfg['files'] = array_map([self::class, 'dateFormat'], array_unique([$cfg['files'], 'yyyy_MM_dd', 'yyyy-MM-dd', 'yyyyMMdd']));
        return $cfg;
    }

    /** A Logseq (Java-style) date format as a regular expression and what its groups hold. */
    public static function dateFormat(string $format): array
    {
        $tokens = [
            'yyyy' => ['(\d{4})', 'y'], 'yy' => ['(\d{2})', 'yy'],
            'MMMM' => ['(' . implode('|', IMPORT_MONTHS) . ')', 'month'],
            'MMM' => ['(jan|feb|mar|apr|may|jun|jul|aug|sept?|oct|nov|dec)', 'mon'],
            'MM' => ['(\d{2})', 'm'], 'M' => ['(\d{1,2})', 'm'],
            'do' => ['(\d{1,2})(?:st|nd|rd|th)', 'd'], 'dd' => ['(\d{2})', 'd'], 'd' => ['(\d{1,2})', 'd'],
            'EEEE' => ['(?:' . implode('|', IMPORT_DAYS) . ')', null], 'EEE' => ['(?:mon|tue|wed|thu|fri|sat|sun)', null],
            'EE' => ['(?:mon|tue|wed|thu|fri|sat|sun)', null], 'E' => ['(?:mon|tue|wed|thu|fri|sat|sun)', null],
        ];
        $re = '';
        $groups = [];
        for ($i = 0, $len = strlen($format); $i < $len;) {
            if ($format[$i] === "'") {                      // 'quoted' text
                $end = strpos($format, "'", $i + 1) ?: $len;
                $re .= preg_quote(substr($format, $i + 1, $end - $i - 1), '/');
                $i = $end + 1;
                continue;
            }
            foreach ($tokens as $tok => [$pattern, $meaning]) {
                if (substr($format, $i, strlen($tok)) === $tok) {
                    $re .= $pattern;
                    if ($meaning !== null) {
                        $groups[] = $meaning;
                    }
                    $i += strlen($tok);
                    continue 2;
                }
            }
            $re .= preg_quote($format[$i], '/');
            $i++;
        }
        return ['/^' . $re . '$/iu', $groups];
    }

    /** The YYYY-MM-DD that $text is in a date format, or null. */
    public static function matchDate(array $format, string $text): ?string
    {
        [$re, $groups] = $format;
        if (!preg_match($re, trim($text), $m)) {
            return null;
        }
        $y = $mo = $d = null;
        foreach ($groups as $k => $meaning) {
            $v = strtolower($m[$k + 1]);
            match ($meaning) {
                'y' => $y = (int) $v,
                'yy' => $y = 2000 + (int) $v,
                'm' => $mo = (int) $v,
                'month' => $mo = array_search($v, IMPORT_MONTHS, true) + 1,
                'mon' => $mo = array_search(substr($v, 0, 3), array_map(fn($x) => substr($x, 0, 3), IMPORT_MONTHS), true) + 1,
                'd' => $d = (int) $v,
            };
        }
        return $y !== null && $mo !== null && $d !== null && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** An id that is the same every time for the same seed, in the UUID layout (version 8). */
    public static function stableId(string $seed): string
    {
        $h = sha1($seed);
        $h[12] = '8';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /** One file: ['file', 'title', 'text', 'blocks' => [['id', 'text', 'collapsed', 'children']]]. */
    public static function parse(string $md, string $file, ?array $cfg = null): array
    {
        $cfg ??= self::config('/nonexistent');
        $md = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $md) ?? $md);
        $name = basename($file, '.md');
        $title = null;
        if (str_starts_with($file, 'journals/') || preg_match('#/journals/#', $file)) {
            foreach ($cfg['files'] as $f) {
                $title ??= self::matchDate($f, $name);
            }
        }
        $title ??= preg_match('/^(\d{4})_(\d{2})_(\d{2})$/', $name, $d) ? "$d[1]-$d[2]-$d[3]" : self::pageName($name, $cfg['names']);
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
            if (preg_match('/^([A-Za-z0-9_.-]+):: ?(.*)$/', $line, $m)) {
                if (strtolower($m[1]) === 'title') {
                    $title = trim($m[2]);
                } elseif (!self::dropped($m[1])) {
                    $pageText[] = '{' . $m[1] . ':: ' . trim($m[2]) . '}';
                }
            } else {
                $pageText[] = preg_replace('/^\\\\(\\\\*- )/', '$1', $line);
            }
        }
        $blocks = array_map([self::class, 'block'], $root['children']);
        if ($blocks && $blocks[0]['propsOnly'] && !$blocks[0]['children']) {
            $first = array_shift($blocks);                   // Logseq keeps page properties in a first block
            $title = $first['title'] ?? $title;
            $pageText[] = $first['text'];
        }
        return ['file' => $file, 'title' => trim($title), 'text' => trim(implode("\n", $pageText)), 'blocks' => $blocks];
    }

    /** A page title from a Logseq file name: A___B is A/B; in legacy graphs A.B is A/B too, and %2F a slash. */
    private static function pageName(string $stem, string $format): string
    {
        if ($format === 'legacy') {
            $name = rawurldecode($stem);
            return str_contains($stem, '.') && !str_contains($stem, '%2F') ? str_replace('.', '/', $name) : $name;
        }
        return rawurldecode(str_replace('___', '/', $stem));
    }

    private static function dropped(string $key): bool
    {
        $key = strtolower($key);
        return in_array($key, IMPORT_DROP, true) || str_starts_with($key, 'logseq.');
    }

    private static function block(array $b): array
    {
        $lines = [];
        $props = 0;
        $logbook = false;
        $first = array_shift($b['lines']);
        $first = preg_replace_callback('/^(LATER|NOW|WAIT|CANCELED) /', fn($m) => IMPORT_TASKS[$m[1]] . ' ', $first) ?? $first;
        if (preg_match('/(?:^|\s)\[#([ABC])\](?=\s|$)/i', $first, $pm)) {    // [#A] -> {priority:: A}
            $first = trim(preg_replace('/\s*\[#[ABC]\](?=\s|$)/i', '', $first, 1) ?? $first) . ' {priority:: ' . strtoupper($pm[1]) . '}';
        }
        $title = null;
        foreach ([$first, ...$b['lines']] as $line) {
            if (preg_match('/^:LOGBOOK:\s*$/', $line)) {
                $logbook = true;
            } elseif ($logbook) {
                $logbook = !preg_match('/^:END:\s*$/', $line);
            } elseif (preg_match('/^(SCHEDULED|DEADLINE): <(\d{4}-\d{2}-\d{2})[^>]*>\s*$/', $line, $m)) {
                $lines[] = '{' . strtolower($m[1]) . ':: ' . $m[2] . '}';
            } elseif (preg_match('/^([A-Za-z0-9_.-]+):: ?(.*)$/', $line, $m)) {
                $key = strtolower($m[1]);
                $props++;
                if ($key === 'id' && is_id(strtolower(trim($m[2])))) {
                    $b['id'] = strtolower(trim($m[2]));
                } elseif ($key === 'collapsed') {
                    $b['collapsed'] = trim($m[2]) === 'true';
                } elseif ($key === 'title') {
                    $title = trim($m[2]);
                } elseif ($key === 'heading' && ctype_digit(trim($m[2])) && $lines && !str_starts_with($lines[0], '#')) {
                    $lines[0] = str_repeat('#', min(6, (int) $m[2])) . ' ' . $lines[0];
                } elseif (!self::dropped($key)) {
                    $lines[] = '{' . $m[1] . ':: ' . trim($m[2]) . '}';
                }
            } else {
                $lines[] = $line;
            }
        }
        $text = self::orgBlocks(rtrim(implode("\n", $lines)));
        return ['id' => $b['id'], 'text' => $text, 'collapsed' => $b['collapsed'], 'children' => array_map([self::class, 'block'], $b['children']),
            'propsOnly' => $props > 0 && $props === count($b['lines']) + 1, 'title' => $title];
    }

    /** #+BEGIN_QUOTE … #+END_QUOTE and friends as Markdown: quotes, fenced code, and labelled quotes for notes and tips. */
    private static function orgBlocks(string $text): string
    {
        if (stripos($text, '#+BEGIN_') === false) {
            return $text;
        }
        return preg_replace_callback('/^#\+BEGIN_([A-Z]+)([^\n]*)\n(.*?)\n?#\+END_\1[ \t]*$/ims', function ($m) {
            $kind = strtoupper($m[1]);
            if (in_array($kind, ['SRC', 'EXAMPLE', 'EXPORT'], true)) {
                return '```' . trim($m[2]) . "\n" . $m[3] . "\n```";
            }
            $label = in_array($kind, ['NOTE', 'TIP', 'IMPORTANT', 'CAUTION', 'WARNING', 'PINNED'], true) ? '**' . ucfirst(strtolower($kind)) . ':** ' : '';
            return implode("\n", array_map(fn($l) => "> $l", explode("\n", $label . $m[3])));
        }, $text) ?? $text;
    }
}
