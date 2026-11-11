<?php
/**
 * Pages as Markdown outlines, the format Logseq and most outliners read:
 *
 *     {favorite::true}            <- page content (properties), if any
 *
 *     - a note
 *       second line of the same note
 *     	- a child note              <- one tab per level
 *
 * export and import are inverses: importing an exported page gives the same
 * outline. Import also accepts space-indented lists and Logseq's
 * "key:: value" property lines.
 */

namespace App;

use PDO;

class Markdown
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** The page as a Markdown outline. */
    public function exportPage(int $pageId): string
    {
        $stmt = $this->pdo->prepare('SELECT content FROM Pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $pageContent = trim((string)$stmt->fetchColumn());

        $stmt = $this->pdo->prepare('SELECT id, parent_note_id, content FROM Notes WHERE page_id = ? AND active = 1 ORDER BY sort_key, id');
        $stmt->execute([$pageId]);
        $children = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
            $children[(string)$note['parent_note_id']][] = $note;
        }

        $out = $pageContent === '' ? '' : $pageContent . "\n\n";
        $stack = [];
        foreach (array_reverse($children[''] ?? []) as $note) {
            $stack[] = [$note, 0];
        }
        while ($stack) {
            [$note, $depth] = array_pop($stack);
            $indent = str_repeat("\t", $depth);
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$note['content']));
            $out .= $indent . '- ' . array_shift($lines) . "\n";
            foreach ($lines as $line) {
                $out .= ($line === '' ? '' : $indent . '  ' . $line) . "\n";
            }
            foreach (array_reverse($children[(string)$note['id']] ?? []) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
        return $out;
    }

    /**
     * Parses a Markdown outline.
     *
     * @return array{content: string, notes: array} page content and a tree for NoteOps::appendTree()
     */
    public static function parse(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $pageLines = [];
        $roots = [];
        /** @var array<int, array{indent: int, node: array}> $stack path from a root to the current note */
        $stack = [];
        $current = null;      // reference to the note that continuation lines belong to
        $currentIndent = 0;   // width of that note's bullet indentation

        $width = static function (string $ws): int {
            return strlen(str_replace("\t", '    ', $ws)); // a tab counts as four columns
        };

        foreach ($lines as $line) {
            if (preg_match('/^([ \t]*)[-*+] (.*)$/', $line, $m) || preg_match('/^([ \t]*)[-*+]$/', $line, $m)) {
                $indent = $width($m[1]);
                $node = ['content' => $m[2] ?? '', 'children' => []];
                while ($stack && $stack[count($stack) - 1]['indent'] >= $indent) {
                    array_pop($stack);
                }
                if ($stack) {
                    $parent = &$stack[count($stack) - 1]['node'];
                    $parent['children'][] = $node;
                    $ref = &$parent['children'][count($parent['children']) - 1];
                    unset($parent);
                } else {
                    $roots[] = $node;
                    $ref = &$roots[count($roots) - 1];
                }
                $stack[] = ['indent' => $indent, 'node' => &$ref];
                $current = &$ref;
                $currentIndent = $indent;
                unset($ref);
                continue;
            }
            if ($current === null) {
                $pageLines[] = $line; // before the first bullet: page content
                continue;
            }
            // A continuation line of the current note: drop the indentation that aligns it under the bullet text.
            $text = $line;
            if (preg_match('/^([ \t]*)(.*)$/', $line, $m)) {
                $strip = $currentIndent + 2;
                $ws = str_replace("\t", '    ', $m[1]);
                $text = substr($ws, min(strlen($ws), $strip)) . $m[2];
            }
            // Logseq writes block properties as "key:: value" lines.
            if (preg_match('/^([A-Za-z0-9_.-]+):: (.+)$/', $text, $m)) {
                if (!in_array($m[1], ['id', 'collapsed'], true)) {
                    $current['content'] .= ' {' . $m[1] . '::' . trim($m[2]) . '}';
                }
                continue;
            }
            $current['content'] .= "\n" . $text;
        }
        unset($current);

        // Page-level "key:: value" lines (Logseq page properties) become notd properties.
        $content = [];
        foreach ($pageLines as $line) {
            $content[] = preg_match('/^([A-Za-z0-9_.-]+):: (.+)$/', $line, $m) ? '{' . $m[1] . '::' . trim($m[2]) . '}' : $line;
        }

        $clean = static function (array $nodes) use (&$clean): array {
            $out = [];
            foreach ($nodes as $n) {
                $n['content'] = rtrim($n['content']);
                $n['children'] = $clean($n['children']);
                $out[] = $n;
            }
            return $out;
        };
        return ['content' => trim(implode("\n", $content)), 'notes' => $clean($roots)];
    }

    /**
     * Adds the notes of a Markdown outline to a page, creating the page if
     * needed. Existing notes are kept; page content is set only if the page has none.
     *
     * @return array{page_id: int, notes: int}
     */
    public function importPage(string $pageName, string $markdown): array
    {
        $parsed = self::parse($markdown);
        return notd_write($this->pdo, function (PDO $pdo) use ($pageName, $parsed) {
            $pages = new PageStore($pdo);
            $pageId = $pages->ensure($pageName);
            $page = $pages->findById($pageId);
            if ($parsed['content'] !== '' && trim((string)$page['content']) === '') {
                $pages->update($pageId, ['content' => $parsed['content']]);
            }
            $created = $parsed['notes'] ? (new NoteOps($pdo))->appendTree($pageId, $parsed['notes']) : [];
            return ['page_id' => $pageId, 'notes' => count($created)];
        });
    }

    /** File name for a page, the way Logseq names files: "/" becomes "___". */
    public static function fileName(string $pageName): string
    {
        $name = str_replace('/', '___', $pageName);
        $name = preg_replace('/[<>:"\\\\|?*\x00-\x1f]/', '_', $name);
        if (PageStore::isJournalName($pageName)) {
            $name = str_replace('-', '_', $name);
        }
        return $name . '.md';
    }

    /** The page name a file name stands for (the inverse of fileName()). */
    public static function pageName(string $fileName): string
    {
        $name = preg_replace('/\.(md|markdown|txt)$/i', '', basename($fileName));
        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})$/', $name, $m)) {
            return "$m[1]-$m[2]-$m[3]";
        }
        return str_replace('___', '/', rawurldecode($name));
    }
}
