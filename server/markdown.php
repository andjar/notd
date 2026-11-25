<?php
// Markdown export: one file per page, written so that the same notebook always
// gives the same bytes, and only files whose content changed are touched. This
// is what the git plugin commits, and what import reads back.
//
//   journals/2026-11-25.md     pages/Østfold.md     files/2026/11/<sha256>.png
//
// A page file:
//
//   title:: Østfold
//   {alias:: Ostfold}            <- the page's own text, if any
//
//   - Fredrikstad                <- blocks as a bulleted outline, two spaces per level
//     id:: 0192f6a0-...          <- only for blocks that something refers to
//     collapsed:: true
//     - a second line of a block is indented under its bullet
//
// Links to stored files, ](file:<id>), are written as relative paths to the
// copy under files/, so the export reads well in any Markdown tool.

declare(strict_types=1);

final class Markdown
{
    /** Writes the whole notebook to $dir; returns ['written' => n, 'unchanged' => n, 'deleted' => n]. */
    public static function export(PDO $pdo, string $dir): array
    {
        $stats = ['written' => 0, 'unchanged' => 0, 'deleted' => 0];
        $referenced = array_flip($pdo->query("SELECT DISTINCT target FROM links WHERE kind IN ('ref', 'embed')")->fetchAll(PDO::FETCH_COLUMN));
        $files = [];
        foreach ($pdo->query('SELECT node_id, path FROM files') as $row) {
            $files[$row['node_id']] = $row['path'];
            $stats[self::put("$dir/{$row['path']}", null, Config::dataDir() . '/' . $row['path'])]++;
        }
        $keep = [];
        foreach ($pdo->query("SELECT id FROM nodes WHERE kind IN ('page', 'doc') ORDER BY title_key")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $page = Nodes::get($pdo, $id);
            $blocks = Read::descendants($pdo, $id);
            if ($blocks === [] && trim($page['text']) === '' && Read::isJournalTitle($page['title'])) {
                continue; // a journal day that was opened but never written in
            }
            $rel = self::fileName($page);
            $keep["$dir/$rel"] = true;
            $stats[self::put("$dir/$rel", self::page($page, $blocks, $referenced, $files))]++;
        }
        foreach (['pages', 'journals'] as $sub) {
            foreach (glob("$dir/$sub/*.md") ?: [] as $file) {
                if (!isset($keep[$file]) && unlink($file)) {
                    $stats['deleted']++;
                }
            }
        }
        return $stats;
    }

    /** The Markdown of one page. */
    public static function page(array $page, array $blocks, array $referenced = [], array $files = []): string
    {
        $out = 'title:: ' . $page['title'] . "\n";
        if (trim($page['text']) !== '') {
            $out .= preg_replace('/^(\\\\*)(- )/m', '\\\\$1$2', self::fileLinks(rtrim(self::lf($page['text'])), $files)) . "\n";
        }
        $depth = [$page['id'] => -1];
        $out .= $blocks ? "\n" : '';
        foreach ($blocks as $b) {
            $d = $depth[$b['id']] = $depth[$b['parent']] + 1;
            $pad = str_repeat('  ', $d);
            $lines = explode("\n", self::fileLinks(self::lf($b['text']), $files));
            $out .= $pad . rtrim('- ' . array_shift($lines)) . "\n";
            if (isset($referenced[$b['id']])) {
                $out .= "$pad  id:: {$b['id']}\n";
            }
            if ($b['collapsed']) {
                $out .= "$pad  collapsed:: true\n";
            }
            foreach ($lines as $line) {
                $out .= rtrim($line === '' ? '' : "$pad  $line") . "\n";
            }
        }
        return $out;
    }

    /** journals/<date>.md for journal pages, pages/<title>.md for the rest; names safe on every file system. */
    public static function fileName(array $page): string
    {
        $title = $page['title'];
        if (Read::isJournalTitle($title)) {
            return "journals/$title.md";
        }
        $name = preg_replace_callback('/[\x00-\x1F\x7F\/\\\\:*?"<>|%]|^\.|\.$/u', fn($m) => sprintf('%%%02X', ord($m[0])), $title);
        if (strlen($name) > 200) {
            $name = mb_strcut($name, 0, 180, 'UTF-8') . '~' . substr(hash('sha256', $title), 0, 12);
        }
        return "pages/$name.md";
    }

    private static function fileLinks(string $text, array $files): string
    {
        return preg_replace_callback('/\]\(file:(' . UUID_RE . ')\)/i', fn($m) => isset($files[strtolower($m[1])])
            ? '](../' . $files[strtolower($m[1])] . ')' : $m[0], $text) ?? $text;
    }

    private static function lf(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** Writes $content (or copies $source) to $path unless it already holds exactly that. */
    private static function put(string $path, ?string $content, ?string $source = null): string
    {
        if (is_file($path) && ($source !== null ? filesize($path) === filesize($source) && hash_file('sha256', $path) === hash_file('sha256', $source)
            : file_get_contents($path) === $content)) {
            return 'unchanged';
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create ' . dirname($path));
        }
        $ok = $source !== null ? copy($source, $path) : file_put_contents($path, $content, LOCK_EX) !== false;
        if (!$ok) {
            throw new RuntimeException("Cannot write $path");
        }
        return 'written';
    }
}
