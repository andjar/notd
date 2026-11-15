<?php
/**
 * Imports a Logseq graph in its file-based Markdown format (the folder with
 * journals/, pages/, assets/ and logseq/config.edn) into notd.
 *
 *     $import = new LogseqImport($pdo, ['mode' => 'merge']);
 *     $report = $import->run('/home/me/logseq-graph');
 *
 * What is carried over, and how:
 *
 *   journals/2023_11_14.md       the journal page 2023-11-14 (file name format from config.edn)
 *   pages/A___B.md, title::      the page "A/B"; a title:: property wins over the file name
 *   page properties              page content {key::value}; title:: is dropped
 *   key:: value (block)          {key::value} at the end of the note's first line
 *   id:: <uuid>                  the note's id, so ((uuid)) and {{embed ((uuid))}} become !{{uuid}}
 *   collapsed:: true             the note is collapsed
 *   heading:: 2                  "## " in front of the text
 *   LATER/NOW/WAIT/CANCELED ...  TODO/DOING/WAITING/CANCELLED
 *   [#A]                         {priority::A}
 *   SCHEDULED/DEADLINE: <...>    {scheduled::2023-11-14 09:00} / {deadline::...}
 *   :LOGBOOK: ... :END:          dropped
 *   #tag, #[[a tag]]             [[tag]], [[a tag]] (Logseq tags are page links)
 *   [[Nov 14th, 2023]]           [[2023-11-14]] (journal title format from config.edn)
 *   [[alias]]                    [[Page]] when the alias has no page of its own
 *   {{embed [[Page]]}}           [[Page]]
 *   #+BEGIN_QUOTE / _SRC / ...   Markdown quote / fenced code
 *   ![x](../assets/a.png)        the file is copied to uploads/, attached to the note,
 *   [x](../assets/a.pdf)         and the link points at the copy
 *   [[draws/x.excalidraw]]       attached the same way
 *   :favorites in config.edn     {favorite::true} on those pages
 *
 * Code (fenced blocks and `spans`) is never rewritten.
 *
 * Reliability:
 *   - Everything runs in one transaction with a savepoint per page: a page that
 *     fails is rolled back alone and reported; nothing is half-written.
 *     Files copied for a failed page (or a failed run) are removed again.
 *   - Re-running is safe. Notes get stable ids (the Logseq id:: when there is
 *     one, otherwise one derived from the file, the parent and the text), so
 *     in the default "merge" mode notes that are already there are skipped and
 *     only new ones are added. "replace" mode makes each imported page an
 *     exact copy of its file.
 *   - Identical files are stored once, also across runs.
 *   - dry_run parses and writes everything inside a transaction that is then
 *     rolled back, without copying any file, so it reports exactly what a
 *     real run would do.
 */

namespace App;

use PDO;

/** Thrown inside the transaction to roll a dry run back. */
class LogseqDryRun extends \RuntimeException {}

class LogseqImport
{
    /** Logseq task markers and the notd state each becomes. */
    private const MARKERS = [
        'TODO' => 'TODO', 'LATER' => 'TODO',
        'DOING' => 'DOING', 'NOW' => 'DOING', 'IN-PROGRESS' => 'DOING',
        'DONE' => 'DONE',
        'WAITING' => 'WAITING', 'WAIT' => 'WAITING',
        'CANCELED' => 'CANCELLED', 'CANCELLED' => 'CANCELLED',
    ];

    /** Block properties Logseq uses for itself; handled here, not copied as properties. */
    private const BLOCK_SYSTEM_PROPERTIES = ['id', 'collapsed', 'heading', 'created-at', 'updated-at'];

    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private PDO $pdo;
    private array $options;

    private string $root = '';
    private array $config = [];
    /** @var array{0:string,1:array}|null compiled journal title format */
    private ?array $titleFormat = null;
    /** @var array<int, array{0:string,1:array}> compiled journal file name formats */
    private array $fileFormats = [];

    /** @var array<string,string> lower-case page name => page name, for every imported page */
    private array $pageNames = [];
    /** @var array<string,string> lower-case original name => sanitized name, for names notd cannot store */
    private array $renamed = [];
    /** @var array<string,string> lower-case alias => page name */
    private array $aliases = [];
    /** @var array<string,bool> block ids defined by the graph */
    private array $blockIds = [];
    /** @var array<string,bool> lower-case names of favorite pages */
    private array $favorites = [];

    /** Files copied in this run: absolute path => true. Removed again if the run fails. */
    private array $copied = [];
    /** sha1 of a source file => stored relative path, for files stored in this run */
    private array $storedByHash = [];
    /** Attachment files of replaced notes; deleted once the run has committed. */
    private array $obsoleteFiles = [];

    private array $report = [];
    /** @var callable|null */
    private $log;

    /**
     * @param array $options
     *   mode            'merge' (default) adds what is not there yet; 'replace' replaces the notes of each imported page
     *   dry_run         bool: report what would happen, change nothing
     *   strict          bool: stop and roll everything back at the first page that fails
     *   tags            'link' (default) turns #tag into [[tag]]; 'keep' leaves it as written
     *   resolve_aliases bool (default true): [[alias]] becomes [[Page]] when the alias has no page of its own
     *   max_file_size   bytes; larger attachments are skipped (default Attachments::MAX_FILE_SIZE, 0 = no limit)
     *   filename_format 'triple-lowbar' or 'legacy'; default: from config.edn
     *   webhooks        bool: send the webhooks the import triggers (default false)
     *   log             callable(string $level, string $message): progress and warnings
     */
    public function __construct(PDO $pdo, array $options = [])
    {
        $this->pdo = $pdo;
        $this->options = $options + [
            'mode' => 'merge',
            'dry_run' => false,
            'strict' => false,
            'tags' => 'link',
            'resolve_aliases' => true,
            'max_file_size' => Attachments::MAX_FILE_SIZE,
            'filename_format' => null,
            'webhooks' => false,
            'log' => null,
        ];
        if (!in_array($this->options['mode'], ['merge', 'replace'], true)) {
            throw new \InvalidArgumentException("mode must be 'merge' or 'replace'");
        }
        $this->log = $this->options['log'];
    }

    // ================================================================ run

    /**
     * Imports the graph in $dir.
     *
     * @return array report: counts, warnings and the pages that failed
     */
    public function run(string $dir): array
    {
        $root = realpath($dir);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException("Not a folder: $dir");
        }
        $this->root = str_replace('\\', '/', $root);
        $this->report = [
            'pages_created' => 0, 'pages_updated' => 0, 'pages_empty' => 0, 'journals' => 0,
            'notes_created' => 0, 'notes_unchanged' => 0,
            'attachments_stored' => 0, 'attachments_reused' => 0,
            'files_skipped' => [], 'warnings' => [], 'failed' => [], 'dry_run' => (bool)$this->options['dry_run'],
        ];

        $this->readConfig();
        $files = $this->discover();
        if (!$files) {
            throw new \InvalidArgumentException("No Markdown pages found in $dir (expected journals/ and pages/ folders).");
        }
        $docs = [];
        foreach ($files as $file) {
            try {
                $docs[] = $this->parseFile($file);
            } catch (\Throwable $e) {
                $this->report['failed'][] = ['file' => $this->rel($file), 'error' => $e->getMessage()];
                $this->say('error', $this->rel($file) . ': ' . $e->getMessage());
            }
        }
        $docs = $this->index($docs);
        $this->say('info', count($docs) . ' pages to import from ' . $this->root);

        $snapshot = $this->report;
        try {
            notd_write($this->pdo, function () use ($docs, $snapshot) {
                // notd_write retries when the database is busy: start each attempt clean.
                $this->removeCopiedFiles();
                $this->storedByHash = [];
                $this->obsoleteFiles = [];
                $this->report = $snapshot;

                $n = 0;
                foreach ($docs as $doc) {
                    $n++;
                    $this->importDocSafely($doc);
                    if ($n % 100 === 0) {
                        $this->say('info', "$n/" . count($docs) . ' pages');
                    }
                }
                if ($this->options['dry_run']) {
                    throw new LogseqDryRun();
                }
            });
        } catch (LogseqDryRun $e) {
            // rolled back on purpose
        } catch (\Throwable $e) {
            $this->removeCopiedFiles();
            throw $e;
        } finally {
            if (!$this->options['webhooks'] || $this->options['dry_run']) {
                PropertyTriggerService::discardPendingWebhooks();
            }
        }

        if (!$this->options['dry_run']) {
            foreach (array_keys($this->obsoleteFiles) as $path) {
                $file = Attachments::resolve($path);
                if ($file !== null) {
                    @unlink($file);
                }
            }
        }
        $this->copied = [];
        return $this->report;
    }

    private function importDocSafely(array $doc): void
    {
        $this->pdo->exec('SAVEPOINT logseq_page');
        $copiedBefore = $this->copied;
        $hashesBefore = $this->storedByHash;
        $obsoleteBefore = $this->obsoleteFiles;
        $reportBefore = $this->report;
        try {
            $this->importDoc($doc);
            $this->pdo->exec('RELEASE SAVEPOINT logseq_page');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT logseq_page');
            $this->pdo->exec('RELEASE SAVEPOINT logseq_page');
            foreach (array_keys(array_diff_key($this->copied, $copiedBefore)) as $file) {
                @unlink($file);
            }
            $this->copied = $copiedBefore;
            $this->storedByHash = $hashesBefore;
            $this->obsoleteFiles = $obsoleteBefore;
            $warnings = $this->report['warnings'];
            $this->report = $reportBefore;
            $this->report['warnings'] = $warnings;
            $this->report['failed'][] = ['file' => $doc['rel'], 'page' => $doc['name'], 'error' => $e->getMessage()];
            $this->say('error', "{$doc['rel']}: not imported: " . $e->getMessage());
            if ($this->options['strict']) {
                throw $e;
            }
        }
    }

    // ============================================================ discovery

    private function readConfig(): void
    {
        $this->config = [
            'title_format' => 'MMM do, yyyy',
            'file_format' => 'yyyy_MM_dd',
            'filename_format' => null,
            'favorites' => [],
        ];
        $file = $this->root . '/logseq/config.edn';
        if (is_file($file)) {
            $edn = (string)file_get_contents($file);
            // Drop ;; comments (outside strings, which is good enough for config.edn).
            $edn = preg_replace('/^((?:[^";]|"(?:[^"\\\\]|\\\\.)*")*);.*$/m', '$1', $edn);
            if (preg_match('/:journal\/page-title-format\s+"([^"]+)"/', $edn, $m)) {
                $this->config['title_format'] = $m[1];
            }
            if (preg_match('/:journal\/file-name-format\s+"([^"]+)"/', $edn, $m)) {
                $this->config['file_format'] = $m[1];
            }
            if (preg_match('/:file\/name-format\s+:([a-z-]+)/', $edn, $m)) {
                $this->config['filename_format'] = $m[1];
            }
            if (preg_match('/:favorites\s+\[([^\]]*)\]/', $edn, $m) && preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $fav)) {
                $this->config['favorites'] = array_map('stripcslashes', $fav[1]);
            }
            $this->config['filename_format'] = $this->config['filename_format'] ?? 'legacy';
        }
        // A folder without config.edn is not a Logseq graph proper: do not guess at legacy dot names.
        $format = $this->options['filename_format'] ?? $this->config['filename_format'] ?? 'triple-lowbar';
        if (!in_array($format, ['triple-lowbar', 'legacy'], true)) {
            $this->warn(null, "Unknown :file/name-format \"$format\"; reading file names as triple-lowbar.");
            $format = 'triple-lowbar';
        }
        $this->config['filename_format'] = $format;

        $this->titleFormat = self::compileDateFormat($this->config['title_format']);
        $this->fileFormats = [];
        foreach (array_unique([$this->config['file_format'], 'yyyy_MM_dd', 'yyyy-MM-dd', 'yyyyMMdd']) as $f) {
            $this->fileFormats[] = self::compileDateFormat($f);
        }
        foreach ($this->config['favorites'] as $fav) {
            $this->favorites[mb_strtolower(trim($fav))] = true;
        }
    }

    /** @return string[] Markdown files to import, journals first */
    private function discover(): array
    {
        $dirs = [];
        foreach (['journals', 'pages'] as $d) {
            if (is_dir("$this->root/$d")) {
                $dirs[] = "$this->root/$d";
            }
        }
        $graph = (bool)$dirs;
        if (!$graph) {
            $dirs = [$this->root]; // a plain folder of .md files
        }
        $files = [];
        foreach ($dirs as $dir) {
            $found = [];
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    function (\SplFileInfo $f) use ($graph) {
                        $name = $f->getFilename();
                        if ($name[0] === '.') {
                            return false;
                        }
                        if ($f->isDir()) {
                            return $graph || !in_array($name, ['logseq', 'assets', 'draws', 'whiteboards', 'node_modules'], true);
                        }
                        return true;
                    }
                )
            );
            foreach ($it as $f) {
                $path = str_replace('\\', '/', $f->getPathname());
                if (preg_match('/\.(md|markdown)$/i', $path)) {
                    $found[] = $path;
                } elseif (preg_match('/\.org$/i', $path)) {
                    $this->report['files_skipped'][] = $this->rel($path);
                    $this->warn($this->rel($path), 'Org-mode file skipped (only Markdown is imported).');
                }
            }
            sort($found, SORT_STRING);
            $files = array_merge($files, $found);
        }
        return $files;
    }

    // ============================================================== parsing

    /**
     * Parses one file into
     *   ['file', 'rel', 'name', 'journal' => bool, 'props' => [[key, value]...], 'blocks' => tree]
     * Each block: ['text' => string, 'props' => [[key, value]...], 'id' => ?string,
     *   'collapsed' => bool, 'heading' => ?int, 'created' => ?int, 'updated' => ?int, 'line' => int, 'children' => [...]]
     */
    public function parseFile(string $file): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('cannot be read');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            $this->warn($this->rel($file), 'not UTF-8; read as Windows-1252');
        }
        $parsed = self::parseOutline($raw);
        $rel = $this->rel($file);

        $props = $parsed['props'];
        $title = null;
        foreach ($props as $i => [$k, $v]) {
            if (strtolower($k) === 'title' && trim($v) !== '') {
                $title = trim($v);
                unset($props[$i]);
            }
        }
        $props = array_values($props);

        $stem = preg_replace('/\.(md|markdown)$/i', '', basename($file));
        $inJournals = strpos($rel, 'journals/') === 0;
        $journal = null;
        if ($title !== null) {
            // title:: wins; a title that is a date (in either form) makes a journal page.
            $journal = $this->journalDate($title);
            $name = $journal ?? $title;
        } else {
            if (strpos($rel, 'pages/') !== 0) {
                foreach ($this->fileFormats as $fmt) {
                    if (($journal = self::matchDate($fmt, $stem)) !== null) {
                        break;
                    }
                }
                if ($journal === null && $inJournals) {
                    $this->warn($rel, "file name does not match the journal format \"{$this->config['file_format']}\"; imported as a page");
                }
            }
            $name = $journal ?? $this->pageNameFromFile($stem);
        }

        return [
            'file' => $file,
            'rel' => $rel,
            'name' => $name,
            'journal' => $journal !== null,
            'props' => $props,
            'blocks' => $parsed['blocks'],
            'warnings' => $parsed['warnings'],
        ];
    }

    /** The page name a file name stands for, per :file/name-format. */
    private function pageNameFromFile(string $stem): string
    {
        if ($this->config['filename_format'] === 'legacy') {
            // Legacy names: "." separated namespaces and %-escapes.
            $name = rawurldecode($stem);
            if (strpos($stem, '.') !== false && strpos($stem, '%2F') === false) {
                $name = str_replace('.', '/', $name);
            }
            return $name;
        }
        return rawurldecode(str_replace('___', '/', $stem));
    }

    /**
     * Parses Logseq Markdown into page properties and a block tree. Pure.
     *
     * @return array{props: array, blocks: array, warnings: array}
     */
    public static function parseOutline(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));
        $warnings = [];

        // YAML front matter (---\nkey: value\n---), which some tools write.
        $front = [];
        if (isset($lines[0]) && rtrim($lines[0]) === '---') {
            for ($i = 1; $i < count($lines) && $i < 200; $i++) {
                if (rtrim($lines[$i]) === '---') {
                    foreach (array_slice($lines, 1, $i - 1) as $l) {
                        if (preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/', $l, $m) && trim($m[2]) !== '') {
                            $front[] = [$m[1], trim(trim($m[2]), '"\'')];
                        }
                    }
                    $lines = array_slice($lines, $i + 1);
                    break;
                }
            }
        }

        // Logseq writes "- " bullets. Plain Markdown files may use * or + instead.
        $dashes = false;
        foreach ($lines as $l) {
            if (preg_match('/^[ \t]*-( |$)/', $l)) {
                $dashes = true;
                break;
            }
        }
        $bulletRe = $dashes ? '/^([ \t]*)-(?: (.*)|)$/' : '/^([ \t]*)[-*+](?: (.*)|)$/';

        $width = static function (string $ws): int {
            return strlen(str_replace("\t", '    ', $ws));
        };

        $pre = [];
        $roots = [];
        $stack = []; // [indent, &node]
        $current = null;
        $currentIndent = 0;
        $currentWs = '';
        $fence = null; // the fence string while inside a fenced code block

        foreach ($lines as $n => $line) {
            // A heading at the start of a line, without a bullet, starts a new
            // top-level block in Logseq (hand-edited files have these).
            if ($fence === null && $current !== null && preg_match('/^#{1,6} /', $line)) {
                $line = '- ' . $line;
            }
            if ($fence === null && preg_match($bulletRe, $line, $m)) {
                $indent = $width($m[1]);
                $node = ['lines' => [$m[2] ?? ''], 'line' => $n + 1, 'children' => []];
                while ($stack && $stack[count($stack) - 1][0] >= $indent) {
                    array_pop($stack);
                }
                if ($stack) {
                    $parent = &$stack[count($stack) - 1][1];
                    $parent['children'][] = $node;
                    $ref = &$parent['children'][count($parent['children']) - 1];
                    unset($parent);
                } else {
                    $roots[] = $node;
                    $ref = &$roots[count($roots) - 1];
                }
                $stack[] = [$indent, &$ref];
                $current = &$ref;
                unset($ref);
                $currentIndent = $indent;
                $currentWs = $m[1];
                $fence = self::fenceToggle($m[2] ?? '', null);
                continue;
            }
            if ($current === null) {
                $pre[] = $line;
                continue;
            }
            // Continuation line: drop the indentation that aligns it under the
            // bullet text (the bullet's own indentation, then a tab or two spaces).
            $text = $line;
            if ($currentWs !== '' && strncmp($text, $currentWs, strlen($currentWs)) === 0) {
                $text = substr($text, strlen($currentWs));
            } else {
                preg_match('/^([ \t]*)(.*)$/s', $text, $m);
                $ws = str_replace("\t", '    ', $m[1]);
                $text = substr($ws, min(strlen($ws), $currentIndent)) . $m[2];
            }
            $text = preg_replace('/^(\t| {1,2})/', '', $text);
            $current['lines'][] = $text;
            $fence = self::fenceToggle($text, $fence);
        }
        unset($current);
        if ($fence !== null) {
            $warnings[] = 'a code block is not closed';
        }

        // Lines before the first bullet: page properties, and possibly text.
        $props = $front;
        $leading = [];
        foreach ($pre as $l) {
            if (empty($leading) && preg_match('/^([^\s:][^\s]*?):: ?(.*)$/', $l, $m)) {
                if (trim($m[2]) !== '') {
                    $props[] = [$m[1], trim($m[2])];
                }
            } else {
                $leading[] = $l;
            }
        }
        $blocks = array_map([self::class, 'finishBlock'], $roots);

        // The first block holding nothing but properties is the page's properties (Logseq's "pre-block").
        if (!$props && $blocks && $blocks[0]['text'] === '' && $blocks[0]['props'] && !$blocks[0]['children']
            && $blocks[0]['id'] === null && empty($blocks[0]['planning'])) {
            $props = $blocks[0]['props'];
            array_shift($blocks);
        }
        $leadingText = trim(implode("\n", $leading));
        if ($leadingText !== '') {
            array_unshift($blocks, self::finishBlock(['lines' => explode("\n", $leadingText), 'line' => 1, 'children' => []]));
        }
        return ['props' => $props, 'blocks' => $blocks, 'warnings' => $warnings];
    }

    /** Opens or closes a fenced code block. @return string|null the open fence after this line */
    private static function fenceToggle(string $line, ?string $open): ?string
    {
        $t = ltrim($line);
        if ($open === null) {
            return preg_match('/^(`{3,}|~{3,})/', $t, $m) ? $m[1] : null;
        }
        return preg_match('/^' . preg_quote($open[0], '/') . '{' . strlen($open) . ',}\s*$/', $t) ? null : $open;
    }

    /** Splits a raw block into text, properties, planning and drawers. */
    private static function finishBlock(array $node): array
    {
        $lines = $node['lines'];
        $props = [];
        $planning = [];
        $id = null;
        $collapsed = false;
        $heading = null;
        $created = $updated = null;

        // The leading region: properties, SCHEDULED/DEADLINE and :DRAWER: ... :END:,
        // directly after the first line (or as the first line).
        $start = preg_match('/^([^\s:][^\s]*?):: ?(.*)$/', $lines[0]) ? 0 : 1;
        $i = $start;
        $count = count($lines);
        while ($i < $count) {
            $l = trim($lines[$i]);
            if (preg_match('/^([^\s:`][^\s]*?):: ?(.*)$/', $l, $m)) {
                $key = $m[1];
                $value = trim($m[2]);
                $lk = strtolower($key);
                if ($lk === 'id') {
                    $id = $value;
                } elseif ($lk === 'collapsed') {
                    $collapsed = strtolower($value) === 'true';
                } elseif ($lk === 'heading') {
                    $heading = ctype_digit($value) ? max(1, min(6, (int)$value)) : (strtolower($value) === 'true' ? 2 : null);
                } elseif ($lk === 'created-at' && ctype_digit($value)) {
                    $created = (int)$value;
                } elseif ($lk === 'updated-at' && ctype_digit($value)) {
                    $updated = (int)$value;
                } elseif ($value !== '') {
                    $props[] = [$key, $value];
                }
                $i++;
                continue;
            }
            if (preg_match_all('/\b(SCHEDULED|DEADLINE):\s*<(\d{4}-\d{2}-\d{2})(?:\s+[A-Za-z]{2,3})?(?:\s+(\d{1,2}:\d{2}))?[^>]*>/', $l, $mm, PREG_SET_ORDER)
                && trim(preg_replace('/\b(SCHEDULED|DEADLINE):\s*<[^>]*>/', '', $l)) === '') {
                foreach ($mm as $p) {
                    $planning[] = [strtolower($p[1]), $p[2] . (empty($p[3]) ? '' : ' ' . $p[3])];
                }
                $i++;
                continue;
            }
            if (preg_match('/^:([A-Za-z_-]+):$/', trim($l)) && strtoupper(trim($l)) !== ':END:') {
                $j = $i + 1;
                while ($j < $count && strtoupper(trim($lines[$j])) !== ':END:') {
                    $j++;
                }
                if ($j < $count) {
                    $i = $j + 1;
                    continue;
                }
            }
            break;
        }
        $body = $start === 0 ? array_slice($lines, $i) : array_merge([$lines[0]], array_slice($lines, $i));
        while ($body && trim(end($body)) === '') {
            array_pop($body);
        }
        $text = implode("\n", array_map('rtrim', $body));

        return [
            'text' => $text,
            'props' => $props,
            'planning' => $planning,
            'id' => $id,
            'collapsed' => $collapsed,
            'heading' => $heading,
            'created' => $created,
            'updated' => $updated,
            'line' => $node['line'],
            'children' => array_map([self::class, 'finishBlock'], $node['children']),
        ];
    }

    // =============================================================== dates

    /**
     * Compiles a date-fns style format ("MMM do, yyyy", "yyyy_MM_dd", "EEEE, dd.MM.yyyy")
     * into a regex and the meaning of each group.
     *
     * @return array{0: string, 1: array<int,string>}
     */
    public static function compileDateFormat(string $format): array
    {
        $tokens = [
            'yyyy' => ['(\d{4})', 'y'], 'yy' => ['(\d{2})', 'yy'],
            'MMMM' => ['(' . implode('|', self::MONTHS) . ')', 'Mname'],
            'MMM' => ['(jan|feb|mar|apr|may|jun|jul|aug|sept?|oct|nov|dec)', 'Mshort'],
            'MM' => ['(\d{2})', 'M'], 'M' => ['(\d{1,2})', 'M'],
            'do' => ['(\d{1,2})(?:st|nd|rd|th)', 'd'], 'dd' => ['(\d{2})', 'd'], 'd' => ['(\d{1,2})', 'd'],
            'EEEE' => ['(?:' . implode('|', self::DAYS) . ')', null],
            'EEE' => ['(?:mon|tue|wed|thu|fri|sat|sun)', null], 'EE' => ['(?:mon|tue|wed|thu|fri|sat|sun)', null],
            'E' => ['(?:mon|tue|wed|thu|fri|sat|sun)', null],
        ];
        $re = '';
        $groups = [];
        $len = strlen($format);
        for ($i = 0; $i < $len;) {
            if ($format[$i] === "'") {
                $end = strpos($format, "'", $i + 1);
                $end = $end === false ? $len : $end;
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

    /** The YYYY-MM-DD a string stands for in a compiled format, or null. */
    public static function matchDate(array $compiled, string $text): ?string
    {
        [$re, $groups] = $compiled;
        if (!preg_match($re, trim($text), $m)) {
            return null;
        }
        $y = $mo = $d = null;
        foreach ($groups as $k => $meaning) {
            $v = strtolower($m[$k + 1]);
            if ($meaning === 'y') {
                $y = (int)$v;
            } elseif ($meaning === 'yy') {
                $y = 2000 + (int)$v;
            } elseif ($meaning === 'M') {
                $mo = (int)$v;
            } elseif ($meaning === 'Mname') {
                $mo = array_search($v, self::MONTHS, true) + 1;
            } elseif ($meaning === 'Mshort') {
                $mo = array_search(substr($v, 0, 3), array_map(fn($x) => substr($x, 0, 3), self::MONTHS), true) + 1;
            } elseif ($meaning === 'd') {
                $d = (int)$v;
            }
        }
        if ($y === null || $mo === null || $d === null || !checkdate($mo, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** The journal date a page name stands for ("Nov 14th, 2023" or "2023-11-14"), or null. */
    private function journalDate(string $name): ?string
    {
        if (PageStore::isJournalName($name)) {
            return checkdate((int)substr($name, 5, 2), (int)substr($name, 8, 2), (int)substr($name, 0, 4)) ? $name : null;
        }
        return $this->titleFormat ? self::matchDate($this->titleFormat, $name) : null;
    }

    // ============================================================ indexing

    /**
     * Settles page names (journal dates, names notd cannot store, files that
     * name the same page), aliases, favorites and block ids.
     */
    private function index(array $docs): array
    {
        // Names notd refuses: brackets and control characters, overlong names.
        foreach ($docs as &$doc) {
            foreach ($doc['warnings'] as $w) {
                $this->warn($doc['rel'], $w);
            }
            $clean = $this->sanitizeName($doc['name']);
            if ($clean !== $doc['name']) {
                $this->renamed[mb_strtolower($doc['name'])] = $clean;
                $this->warn($doc['rel'], "page \"{$doc['name']}\" is imported as \"$clean\" (notd page names cannot contain [ or ])");
                $doc['name'] = $clean;
            }
        }
        unset($doc);

        // Several files for one page (names differ only in case, or a title:: clash): merge them.
        $byName = [];
        foreach ($docs as $doc) {
            $key = mb_strtolower($doc['name']);
            if (isset($byName[$key])) {
                $this->warn($doc['rel'], "names the same page as {$byName[$key]['rel']} (\"{$doc['name']}\"); the notes are added to that page");
                $byName[$key]['blocks'] = array_merge($byName[$key]['blocks'], $doc['blocks']);
                $byName[$key]['props'] = array_merge($byName[$key]['props'], $doc['props']);
                continue;
            }
            $byName[$key] = $doc;
        }
        $docs = array_values($byName);
        foreach ($docs as $doc) {
            $this->pageNames[mb_strtolower($doc['name'])] = $doc['name'];
        }

        // Aliases: [[alias]] goes to the page, unless the alias is a page of its own.
        foreach ($docs as $doc) {
            foreach ($doc['props'] as [$k, $v]) {
                if (strtolower($k) !== 'alias') {
                    continue;
                }
                foreach (self::splitList($v) as $alias) {
                    $lk = mb_strtolower($alias);
                    if (!isset($this->pageNames[$lk]) && $lk !== mb_strtolower($doc['name'])) {
                        $this->aliases[$lk] = $doc['name'];
                    }
                }
            }
        }

        // Block ids: Logseq ids are kept; a duplicate id (Logseq allows it after copy and paste) gets a new one.
        foreach ($docs as &$doc) {
            $this->claimIds($doc['blocks'], $doc['rel']);
        }
        unset($doc);
        return $docs;
    }

    private function claimIds(array &$blocks, string $rel): void
    {
        foreach ($blocks as &$b) {
            if ($b['id'] !== null) {
                $id = strtolower($b['id']);
                if (!Ordering::isValidId($id)) {
                    $this->warn("$rel:{$b['line']}", "block id \"{$b['id']}\" cannot be used; the note gets a new id");
                    $b['id'] = null;
                } elseif (isset($this->blockIds[$id])) {
                    $this->warn("$rel:{$b['line']}", "block id $id is used twice in the graph; references go to the first one");
                    $b['id'] = null;
                } else {
                    $b['id'] = $id;
                    $this->blockIds[$id] = true;
                }
            }
            $this->claimIds($b['children'], $rel);
        }
        unset($b);
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strtr($name, ['[' => '(', ']' => ')'])));
        $name = preg_replace('/[\x00-\x1f]/', '', $name);
        $name = trim($name, '/');
        if (mb_strlen($name) > PageStore::MAX_NAME_LENGTH) {
            $name = rtrim(mb_substr($name, 0, PageStore::MAX_NAME_LENGTH));
        }
        return $name === '' ? 'Untitled' : $name;
    }

    /** "a, [[b c]], d" => ['a', 'b c', 'd'] */
    private static function splitList(string $value): array
    {
        $out = [];
        foreach (preg_split('/,(?![^\[]*\]\])/', $value) as $part) {
            $part = trim(preg_replace('/^#?\[\[(.*)\]\]$/', '$1', trim($part)));
            $part = ltrim($part, '#');
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    // ============================================================= writing

    private static function hasContent(array $blocks): bool
    {
        foreach ($blocks as $b) {
            if (trim($b['text']) !== '' || $b['props'] || $b['planning'] || self::hasContent($b['children'])) {
                return true;
            }
        }
        return false;
    }

    private function importDoc(array $doc): void
    {
        if (!$doc['props'] && !self::hasContent($doc['blocks']) && !isset($this->favorites[mb_strtolower($doc['name'])])) {
            $this->report['pages_empty']++;
            return; // an empty journal file, say
        }
        $pages = new PageStore($this->pdo);
        $existing = $pages->findByName($doc['name']);
        $replace = $this->options['mode'] === 'replace';

        if ($existing && $replace) {
            $this->clearPage((int)$existing['id']);
        }
        $pageId = $existing ? (int)$existing['id'] : $pages->create($doc['name']);
        $this->report[$existing ? 'pages_updated' : 'pages_created']++;
        if ($doc['journal']) {
            $this->report['journals']++;
        }

        // Page content: the page properties.
        $content = $this->pageContent($doc);
        if ($content !== '') {
            $page = $pages->findById($pageId);
            $current = trim(str_replace('{type::journal}', '', (string)$page['content']));
            if ($replace || $current === '') {
                if ($doc['journal'] && strpos($content, '{type::journal}') === false) {
                    $content = '{type::journal}' . "\n" . $content;
                }
                $pages->update($pageId, ['content' => $content]);
            } elseif ($current !== trim(str_replace('{type::journal}', '', $content))) {
                $this->warn($doc['rel'], 'the page already has properties in notd; they are kept (use --replace to overwrite)');
            }
        }

        // Which of the note ids are already in the database, and where.
        $ids = [];
        $this->collectIds($doc['blocks'], $doc['rel'], null, $ids);
        $known = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $stmt = $this->pdo->prepare('SELECT id, page_id FROM Notes WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $known[(string)$row['id']] = (int)$row['page_id'];
            }
        }

        $ops = [];
        $pending = ['attachments' => [], 'times' => []];
        $this->buildOps($doc, $pageId, $doc['blocks'], null, $known, $ops, $pending);

        if ($ops) {
            $result = (new NoteOps($this->pdo))->apply($ops);
            foreach ($result['results'] as $r) {
                $this->report[$r['status'] === 'applied' ? 'notes_created' : 'notes_unchanged']++;
            }
            foreach ($result['warnings'] as $w) {
                $this->warn($doc['rel'], $w['message']);
            }
        }

        $insert = $this->pdo->prepare('INSERT INTO Attachments (note_id, name, path, type, size) VALUES (?, ?, ?, ?, ?)');
        foreach ($pending['attachments'] as $a) {
            $insert->execute([$a['note_id'], $a['name'], $a['path'], $a['type'], $a['size']]);
        }
        $times = $this->pdo->prepare('UPDATE Notes SET created_at = COALESCE(?, created_at), updated_at = COALESCE(?, updated_at) WHERE id = ?');
        foreach ($pending['times'] as [$id, $created, $updated]) {
            $times->execute([$created, $updated, $id]);
        }
    }

    /** Removes the notes of a page (replace mode). Their files are deleted after the run commits. */
    private function clearPage(int $pageId): void
    {
        $stmt = $this->pdo->prepare('SELECT A.id, A.path FROM Attachments A JOIN Notes N ON N.id = A.note_id WHERE N.page_id = ?');
        $stmt->execute([$pageId]);
        $drop = $this->pdo->prepare('DELETE FROM Attachments WHERE id = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $drop->execute([$a['id']]);
            $this->obsoleteFiles[(string)$a['path']] = true;
        }
        $this->pdo->prepare('DELETE FROM Notes WHERE page_id = ?')->execute([$pageId]);
    }

    private function pageContent(array $doc): string
    {
        $parts = [];
        $seen = [];
        foreach ($doc['props'] as [$k, $v]) {
            $key = self::propertyKey($k);
            if ($key === null || isset($seen[strtolower($key)])) {
                continue;
            }
            $seen[strtolower($key)] = true;
            $value = $this->convertInline($v, $doc['rel'], null, false);
            $parts[] = self::property($key, $value);
        }
        if (isset($this->favorites[mb_strtolower($doc['name'])]) && !isset($seen['favorite'])) {
            $parts[] = '{favorite::true}';
        }
        return implode("\n", $parts);
    }

    /** Gives every block its note id (stable across runs) and returns them in $ids. */
    private function collectIds(array &$blocks, string $rel, ?string $parentId, array &$ids): void
    {
        $occurrences = [];
        foreach ($blocks as &$b) {
            if ($b['id'] === null) {
                $k = $b['text'];
                $occurrences[$k] = ($occurrences[$k] ?? 0) + 1;
                $b['id'] = self::stableId($rel . "\0" . ($parentId ?? '') . "\0" . $b['text'] . "\0" . $occurrences[$k]);
                $b['generated_id'] = true;
            }
            $ids[] = $b['id'];
            $this->collectIds($b['children'], $rel, $b['id'], $ids);
        }
        unset($b);
    }

    /** A UUID-shaped id derived from $seed (version 8, "custom"). */
    private static function stableId(string $seed): string
    {
        $h = sha1($seed);
        $h[12] = '8';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private function buildOps(array $doc, int $pageId, array $blocks, ?string $parentId, array &$known, array &$ops, array &$pending): void
    {
        if (!$blocks) {
            return;
        }
        // New notes go after the last note already under this parent.
        if ($parentId === null) {
            $stmt = $this->pdo->prepare('SELECT id, sort_key FROM Notes WHERE page_id = ? AND parent_note_id IS NULL ORDER BY sort_key DESC, id DESC LIMIT 1');
            $stmt->execute([$pageId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT id, sort_key FROM Notes WHERE parent_note_id = ? ORDER BY sort_key DESC, id DESC LIMIT 1');
            $stmt->execute([$parentId]);
        }
        $last = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $lastId = $last['id'] ?? null;
        $lastKey = $last['sort_key'] ?? null;

        foreach ($blocks as $b) {
            if (trim($b['text']) === '' && !$b['children'] && !$b['props'] && !$b['planning']) {
                continue; // Logseq's empty placeholder blocks
            }
            $id = $b['id'];
            $where = "{$doc['rel']}:{$b['line']}";
            if (isset($known[$id])) {
                if ($known[$id] === $pageId) {
                    // Already imported: keep it, and look at its children.
                    $this->report['notes_unchanged']++;
                    $this->buildOps($doc, $pageId, $b['children'], $id, $known, $ops, $pending);
                    continue;
                }
                $new = Ordering::newId();
                if (empty($b['generated_id'])) {
                    $this->warn($where, "a note with id $id exists on another page; this one gets the id $new");
                }
                $id = $new;
            }
            $known[$id] = $pageId;
            $key = Ordering::keyBetween($lastKey, null);
            $ops[] = [
                'op_id' => 'logseq-' . Ordering::newId(),
                'type' => 'create',
                'id' => $id,
                'page_id' => $pageId,
                'parent_id' => $parentId,
                'after' => $lastId,
                'sort_key' => $key,
                'content' => $this->convertBlock($b, $where, $id, $pending),
                'collapsed' => $b['collapsed'] && $b['children'],
            ];
            if ($b['created'] || $b['updated']) {
                $pending['times'][] = [
                    $id,
                    $b['created'] ? gmdate('Y-m-d H:i:s', intdiv($b['created'], 1000)) : null,
                    $b['updated'] ? gmdate('Y-m-d H:i:s', intdiv($b['updated'], 1000)) : null,
                ];
            }
            $lastId = $id;
            $lastKey = $key;
            $this->buildOps($doc, $pageId, $b['children'], $id, $known, $ops, $pending);
        }
    }

    // ========================================================== conversion

    /** The notd text of a block. */
    private function convertBlock(array $b, string $where, string $noteId, array &$pending): string
    {
        $text = self::convertOrgBlocks($b['text']);
        [$first, $rest] = array_pad(explode("\n", $text, 2), 2, '');
        $extra = [];

        // Task marker (optionally after a heading) and priority.
        if (preg_match('/^(#{1,6} )?(' . implode('|', array_map('preg_quote', array_keys(self::MARKERS))) . ')(?= |$)(.*)$/', $first, $m)) {
            $first = $m[1] . $this->taskState(self::MARKERS[$m[2]]) . $m[3];
        }
        if (preg_match('/\s*\[#([A-Ca-c])\]/', $first, $m)) {
            $first = rtrim(preg_replace('/\s*\[#[A-Ca-c]\]/', '', $first, 1));
            $extra[] = ['priority', strtoupper($m[1])];
        }
        if ($b['heading'] && !preg_match('/^#{1,6} /', $first)) {
            $first = str_repeat('#', $b['heading']) . ' ' . $first;
        }

        $text = $first . ($rest !== '' ? "\n" . $rest : '');
        $text = $this->convertInline($text, $where, ['note' => $noteId, 'pending' => &$pending], true);

        $props = [];
        foreach (array_merge($extra, $b['planning'], $b['props']) as [$k, $v]) {
            if (in_array(strtolower($k), self::BLOCK_SYSTEM_PROPERTIES, true)) {
                continue;
            }
            $key = self::propertyKey($k);
            if ($key === null) {
                $this->warn($where, "property \"$k\" has no usable name; kept as text");
                $text .= "\n$k: $v";
                continue;
            }
            $props[] = self::property($key, $this->convertInline($v, $where, null, false));
        }
        if ($props) {
            $lines = explode("\n", $text);
            $lines[0] = ltrim($lines[0] . ' ' . implode(' ', $props));
            $text = implode("\n", $lines);
        }
        return $text;
    }

    /** A task state notd knows (TASK_STATES may be configured without some of them). */
    private function taskState(string $state): string
    {
        $states = defined('TASK_STATES') ? TASK_STATES : ['TODO', 'DONE'];
        if (in_array($state, $states, true)) {
            return $state;
        }
        return in_array($state, ['DONE', 'CANCELLED'], true) ? 'DONE' : 'TODO';
    }

    /** A property key notd accepts, or null. */
    private static function propertyKey(string $key): ?string
    {
        $key = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($key));
        $key = trim($key, '-');
        return $key === '' ? null : $key;
    }

    /** {key::value}; a value cannot hold "}" or a line break. */
    private static function property(string $key, string $value): string
    {
        $value = trim(str_replace(["\n", '}'], [' ', ')'], $value));
        return '{' . $key . '::' . ($value === '' ? ' ' : $value) . '}';
    }

    /** #+BEGIN_QUOTE ... #+END_QUOTE and friends as Markdown. */
    private static function convertOrgBlocks(string $text): string
    {
        if (stripos($text, '#+BEGIN_') === false) {
            return $text;
        }
        return preg_replace_callback('/^#\+BEGIN_([A-Z]+)([^\n]*)\n(.*?)\n?#\+END_\1[ \t]*$/ims', static function ($m) {
            $kind = strtoupper($m[1]);
            $body = $m[3];
            if ($kind === 'SRC' || $kind === 'EXAMPLE' || $kind === 'EXPORT') {
                return '```' . trim($m[2]) . "\n" . $body . "\n```";
            }
            $label = in_array($kind, ['NOTE', 'TIP', 'IMPORTANT', 'CAUTION', 'WARNING', 'PINNED'], true)
                ? '**' . ucfirst(strtolower($kind)) . ':** ' : '';
            $lines = explode("\n", $label . $body);
            return implode("\n", array_map(static fn($l) => '> ' . $l, $lines));
        }, $text);
    }

    /**
     * Rewrites links, tags, block references and asset links outside code.
     *
     * @param array|null $assets ['note' => id, 'pending' => &array] to store asset files; null to leave asset links alone
     */
    private function convertInline(string $text, string $where, ?array $assets, bool $tags): string
    {
        $out = '';
        foreach (self::splitCode($text) as [$isCode, $segment]) {
            $out .= $isCode ? $segment : $this->convertSegment($segment, $where, $assets, $tags);
        }
        return $out;
    }

    /** Splits text into [isCode, text] pieces: fenced blocks and `code spans` are code. */
    private static function splitCode(string $text): array
    {
        $pieces = [];
        $re = '/(?:^|(?<=\n))(`{3,}|~{3,})[^\n]*\n.*?(?:\n\1[`~]*[ \t]*(?=\n|$)|$)|(`+)(?!`).+?(?<!`)\2(?!`)/s';
        $last = 0;
        if (preg_match_all($re, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$match, $offset]) {
                if ($offset > $last) {
                    $pieces[] = [false, substr($text, $last, $offset - $last)];
                }
                $pieces[] = [true, $match];
                $last = $offset + strlen($match);
            }
        }
        if ($last < strlen($text)) {
            $pieces[] = [false, substr($text, $last)];
        }
        return $pieces;
    }

    private function convertSegment(string $s, string $where, ?array $assets, bool $tags): string
    {
        // {{embed ((uuid))}} and ((uuid)): notd embeds notes with !{{id}}.
        $s = preg_replace_callback('/\{\{embed\s+\(\(([0-9a-fA-F-]{36})\)\)\s*\}\}|\(\(([0-9a-fA-F-]{36})\)\)/', function ($m) use ($where) {
            $id = strtolower($m[1] !== '' ? $m[1] : $m[2]);
            if ($this->noteIdKnown($id)) {
                return '!{{' . $id . '}}';
            }
            $this->warn($where, "reference to block $id, which is not in the graph; kept as text");
            return $m[0];
        }, $s);
        // {{embed [[Page]]}}: notd has no page embeds; a link is the closest.
        $s = preg_replace('/\{\{embed\s+(\[\[.+?\]\])\s*\}\}/', '$1', $s);


        // Markdown links and images to local files.
        if ($assets !== null) {
            $s = preg_replace_callback('/(!?)\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*(<[^>]+>|[^)]+?)\s*(?:"[^"]*")?\s*\)(\{:[^}]*\})?/', function ($m) use ($where, $assets) {
                $target = trim($m[3], '<> ');
                $plain = $m[1] . '[' . $m[2] . '](' . $m[3] . ')'; // without Logseq's {:height 120} size
                if (preg_match('#^[a-z][a-z0-9+.-]+:#i', $target) && stripos($target, 'file:') !== 0) {
                    return $plain; // a web link
                }
                if ($target === '' || $target[0] === '#') {
                    return $plain;
                }
                $url = $this->storeAsset($target, $where, $assets);
                return $url === null ? $plain : $m[1] . '[' . $m[2] . '](' . $url . ')';
            }, $s);
        }

        // [[links]], with nested brackets allowed as Logseq does (after the Markdown links,
        // so the links made for Excalidraw drawings are not looked at twice).
        $s = $this->rewriteLinks($s, $where, $assets);

        // #tags
        $s = $this->rewriteTags($s, $tags);
        return $s;
    }

    /** #tag and #[[a tag]] become [[tag]] and [[a tag]]: in Logseq a tag is a page link. */
    private function rewriteTags(string $s, bool $tags): string
    {
        if ($tags && $this->options['tags'] === 'link') {
            $s = preg_replace_callback('/(^|(?<=\s))#(?!\[\[)([^\s#,!?;:"\'()\[\]{}<>`]*[^\s#,.!?;:"\'()\[\]{}<>`])/u', function ($m) {
                if (preg_match('/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$|^\d+$/', $m[2]) && !isset($this->pageNames[mb_strtolower($m[2])])) {
                    return $m[0]; // #fff, #12: colours and numbers rather than tags
                }
                return $m[1] . '[[' . $this->linkTarget($m[2]) . ']]';
            }, $s);
            $s = preg_replace('/(^|(?<=\s))#(\[\[)/', '$2', $s);
        }

        return $s;
    }

    /** [[Target]] rewriting: journal titles, aliases, renamed pages, Excalidraw drawings. */
    private function rewriteLinks(string $s, string $where, ?array $assets): string
    {
        $out = '';
        $len = strlen($s);
        $i = 0;
        while (($start = strpos($s, '[[', $i)) !== false) {
            // Find the matching ]], counting nested [[ ]] and [ ].
            $depth = 0;
            $j = $start + 2;
            $end = null;
            while ($j < $len) {
                if (substr($s, $j, 2) === ']]' && $depth === 0) {
                    $end = $j;
                    break;
                }
                if ($s[$j] === '[') {
                    $depth++;
                } elseif ($s[$j] === ']' && $depth > 0) {
                    $depth--;
                } elseif ($s[$j] === "\n") {
                    break;
                }
                $j++;
            }
            if ($end === null) {
                $out .= substr($s, $i, $start + 2 - $i);
                $i = $start + 2;
                continue;
            }
            $inner = substr($s, $start + 2, $end - $start - 2);
            $out .= substr($s, $i, $start - $i);
            if ($assets !== null && preg_match('/\.excalidraw$/i', $inner)) {
                $url = $this->storeAsset($inner, $where, $assets, true);
                $out .= $url === null ? "[[$inner]]" : '[' . basename($inner) . '](' . $url . ')';
            } else {
                $out .= '[[' . $this->linkTarget($inner) . ']]';
            }
            $i = $end + 2;
        }
        return $out . substr($s, $i);
    }

    /** The notd page a Logseq link target means. */
    private function linkTarget(string $name): string
    {
        $name = trim($name);
        $date = $this->journalDate($name);
        if ($date !== null) {
            return $date;
        }
        $lk = mb_strtolower($name);
        if (isset($this->renamed[$lk])) {
            return $this->renamed[$lk];
        }
        if ($this->options['resolve_aliases'] && isset($this->aliases[$lk])) {
            return $this->aliases[$lk];
        }
        if (strpos($name, '[') !== false || strpos($name, ']') !== false) {
            return $this->sanitizeName($name);
        }
        return $name;
    }

    private function noteIdKnown(string $id): bool
    {
        if (isset($this->blockIds[$id])) {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM Notes WHERE id = ?');
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    // ============================================================ attachments

    /**
     * Copies a file the graph links to into uploads/ and attaches it to the
     * note (once per file: identical files are stored only once).
     *
     * @return string|null the URL to link to, or null to leave the link as it is
     */
    private function storeAsset(string $target, string $where, array $assets, bool $fromRoot = false): ?string
    {
        $source = $this->findAsset($target, $where, $fromRoot);
        if ($source === null) {
            if (preg_match('/\.[A-Za-z0-9]{1,10}$/', $target)) {
                $this->warn($where, "linked file not found: $target");
            }
            return null;
        }
        $name = basename($source);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(Attachments::TYPES[$extension])) {
            $this->warn($where, "$name: .$extension files are not accepted as attachments; link kept as it was");
            $this->report['files_skipped'][] = $this->rel($source);
            return null;
        }
        $size = (int)filesize($source);
        $max = (int)$this->options['max_file_size'];
        if ($max > 0 && $size > $max) {
            $this->warn($where, sprintf('%s is %.1f MB, larger than the limit of %.1f MB; link kept as it was', $name, $size / 1048576, $max / 1048576));
            $this->report['files_skipped'][] = $this->rel($source);
            return null;
        }
        $type = Attachments::TYPES[$extension];
        if (class_exists('finfo') && (strpos($type, 'image/') === 0 || $type === 'application/pdf')) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
            if ($detected && $detected !== $type) {
                if (strpos($detected, 'image/') === 0 && in_array($detected, Attachments::TYPES, true) && strpos($type, 'image/') === 0) {
                    $type = $detected; // a .png that is really a JPEG: fine, record what it is
                } else {
                    $this->warn($where, "$name does not look like a .$extension file ($detected); link kept as it was");
                    $this->report['files_skipped'][] = $this->rel($source);
                    return null;
                }
            }
        }

        $hash = sha1_file($source);
        if (isset($this->storedByHash[$hash])) {
            $this->report['attachments_reused']++;
            return $this->assetUrl($this->storedByHash[$hash]);
        }
        // Stored by an earlier run?
        $stmt = $this->pdo->prepare('SELECT path FROM Attachments WHERE name = ? AND size = ? AND active = 1');
        $stmt->execute([$name, $size]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $file = Attachments::resolve((string)$path);
            if ($file !== null && sha1_file($file) === $hash) {
                $this->storedByHash[$hash] = (string)$path;
                $this->report['attachments_reused']++;
                return $this->assetUrl((string)$path);
            }
        }

        $folder = gmdate('Y') . '/' . gmdate('m');
        $stem = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($name, PATHINFO_FILENAME)), '_');
        $stored = bin2hex(random_bytes(6)) . '_' . substr($stem === '' ? 'file' : $stem, 0, 80) . '.' . $extension;
        $path = $folder . '/' . $stored;
        if (!$this->options['dry_run']) {
            $dir = rtrim(UPLOADS_DIR, '/\\') . '/' . $folder;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create the uploads folder $dir");
            }
            if (!@copy($source, "$dir/$stored")) {
                throw new \RuntimeException("cannot copy $name to $dir");
            }
            $this->copied["$dir/$stored"] = true;
        }
        $this->storedByHash[$hash] = $path;
        $assets['pending']['attachments'][] = ['note_id' => $assets['note'], 'name' => $name, 'path' => $path, 'type' => $type, 'size' => $size];
        $this->report['attachments_stored']++;
        return $this->assetUrl($path);
    }

    /** The file a link in a page points to: relative to the page's folder, the graph, or in assets/. */
    private function findAsset(string $target, string $where, bool $fromRoot): ?string
    {
        $target = preg_replace('#^file://#i', '', $target);
        $target = str_replace('\\', '/', rawurldecode($target));
        $file = preg_replace('/:\d+$/', '', $where);
        $pageDir = dirname($this->root . '/' . $file);
        $candidates = [];
        if ($target !== '' && ($target[0] === '/' || preg_match('#^[A-Za-z]:/#', $target))) {
            $candidates[] = $target;
            $candidates[] = $this->root . $target; // "/assets/x.png" means the graph's assets
        } else {
            if (!$fromRoot) {
                $candidates[] = "$pageDir/$target";
            }
            $candidates[] = "$this->root/$target";
        }
        $candidates[] = "$this->root/assets/" . basename($target);
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return realpath($c) ?: $c;
            }
        }
        return null;
    }

    /** A URL for a stored file, relative to notd's folder, as Attachments::url() resolves it. */
    private function assetUrl(string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        $direct = realpath(UPLOADS_DIR) === realpath(__DIR__ . '/../uploads');
        return $direct ? 'uploads/' . $encoded : 'api/v1/attachments.php?file=' . rawurlencode($path);
    }

    private function removeCopiedFiles(): void
    {
        foreach (array_keys($this->copied) as $file) {
            @unlink($file);
        }
        $this->copied = [];
    }

    // ============================================================= helpers

    private function rel(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        return strpos($file, $this->root . '/') === 0 ? substr($file, strlen($this->root) + 1) : $file;
    }

    private function warn(?string $where, string $message): void
    {
        $line = ($where === null ? '' : "$where: ") . $message;
        $this->report['warnings'][] = $line;
        $this->say('warning', $line);
    }

    private function say(string $level, string $message): void
    {
        if ($this->log) {
            ($this->log)($level, $message);
        }
    }
}
