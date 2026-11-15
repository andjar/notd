<?php
/**
 * The property index.
 *
 * Note and page content is the source of truth. This class derives the rows of
 * the Properties table from it:
 *
 *   {key::value}            a property; its weight is the number of colons
 *   {tag::a} {tags::a, b}   one tag property per value, each also a links_to_page
 *   {alias::A, B}           one alias property per value (page aliases)
 *   [[Page Name]]           links_to_page
 *   TODO text (line start)  status (a log property) and, for DONE, done_at
 *   !{{note-id}}, ((uuid))  references_block
 *   SQL{...}                sql_query
 *   http(s)://...           external_url
 *
 * reindex() compares what the content says with what is stored and writes only
 * the difference, so the common edit (text changes, properties do not) touches
 * no row and fires no webhook.
 */

namespace App;

use PDO;

class PropertyIndex
{
    /** Properties whose value is a comma-separated list, indexed one row per item. */
    public const LIST_PROPERTIES = ['tag', 'tags', 'alias'];

    /** A note id in UUID form, as in an inline reference ((id)). */
    public const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** "a, [[b c]], #d" => ['a', 'b c', 'd'] */
    public static function splitList(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if (preg_match('/^#?\[\[(.*)\]\]$/s', $item, $m)) {
                $item = trim($m[1]);
            }
            $item = trim(ltrim($item, '#'));
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    private PDO $pdo;
    private PropertyTriggerService $triggers;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->triggers = new PropertyTriggerService($pdo);
    }

    /**
     * Extracts properties from content. Pure: no database access.
     *
     * @return array<int, array{name: string, value: string, weight: int}>
     */
    public static function parse(?string $content): array
    {
        if ($content === null || $content === '' || self::isCiphertext($content)) {
            return [];
        }
        $special = defined('SPECIAL_STATE_WEIGHTS') ? SPECIAL_STATE_WEIGHTS : [];
        $props = [];

        $states = (defined('TASK_STATES') && TASK_STATES) ? TASK_STATES : ['TODO', 'DONE'];
        $statePattern = '/^(' . implode('|', array_map('preg_quote', $states)) . ')\s+.*$/m';
        if (preg_match_all($statePattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $props[] = ['name' => 'status', 'value' => $m[1], 'weight' => (int)($special['TASK'] ?? 4)];
                if ($m[1] === 'DONE') {
                    $props[] = ['name' => 'done_at', 'value' => gmdate('Y-m-d H:i:s'), 'weight' => (int)($special['DONE_AT'] ?? 3)];
                }
            }
        }

        $links = [];
        if (preg_match_all('/\{([a-zA-Z0-9_\.-]+)(:{2,})([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = trim($m[1]);
                $weight = strlen($m[2]);
                $lower = strtolower($name);
                if (in_array($lower, self::LIST_PROPERTIES, true)) {
                    // Tags and aliases: one row per value, so each can be found on its own.
                    foreach (self::splitList($m[3]) as $value) {
                        $props[] = ['name' => $name, 'value' => $value, 'weight' => $weight];
                        if ($lower !== 'alias') {
                            $links[] = $value; // a tag is a link to the page of that name
                        }
                    }
                    continue;
                }
                $props[] = ['name' => $name, 'value' => trim($m[3]), 'weight' => $weight];
            }
        }

        if (preg_match_all('/\[\[([^\]]+)\]\]/', $content, $matches)) {
            $links = array_merge(array_map('trim', $matches[1]), $links);
        }
        $seenLinks = [];
        foreach (array_filter($links, 'strlen') as $page) {
            if (isset($seenLinks[mb_strtolower($page)])) {
                continue;
            }
            $seenLinks[mb_strtolower($page)] = true;
            $props[] = ['name' => 'links_to_page', 'value' => $page, 'weight' => (int)($special['LINK'] ?? 3)];
        }

        if (preg_match_all('/(https?:\/\/[^\s<>"]+|www\.[^\s<>"]+)/', $content, $matches)) {
            foreach ($matches[0] as $url) {
                $url = strpos($url, 'www.') === 0 ? 'https://' . $url : $url;
                $props[] = ['name' => 'external_url', 'value' => $url, 'weight' => (int)($special['URL'] ?? 3)];
            }
        }

        $refs = [];
        if (preg_match_all('/!\{\{([^}]+)\}\}/', $content, $matches)) {
            $refs = array_filter(array_map('trim', $matches[1]), 'strlen');
        }
        if (preg_match_all('/\(\((' . self::UUID . ')\)\)/i', $content, $matches)) {
            $refs = array_merge($refs, array_map('strtolower', $matches[1]));
        }
        foreach (array_values(array_unique($refs)) as $ref) {
            $props[] = ['name' => 'references_block', 'value' => $ref, 'weight' => (int)($special['TRANSCLUSION'] ?? 3)];
        }

        if (preg_match_all('/SQL\{([^}]+)\}/', $content, $matches)) {
            foreach (array_filter(array_map('trim', $matches[1]), 'strlen') as $sql) {
                $props[] = ['name' => 'sql_query', 'value' => $sql, 'weight' => (int)($special['SQL'] ?? 3)];
            }
        }

        return $props;
    }

    /** Notes of encrypted pages are stored as SJCL ciphertext (a JSON object). */
    public static function isCiphertext(string $content): bool
    {
        return strncmp($content, '{"iv":', 6) === 0 && strpos($content, '"ct":') !== false;
    }

    /**
     * Brings the stored properties of a note or page in line with its content.
     * The caller owns the transaction.
     *
     * @param string     $entityType 'note' or 'page'
     * @param string|int $entityId
     * @return array the parsed properties
     */
    public function reindex(string $entityType, $entityId, ?string $content): array
    {
        $column = $entityType === 'note' ? 'note_id' : 'page_id';
        if ($content !== null && self::isCiphertext($content)) {
            // Nothing can be derived from ciphertext; drop what was indexed before encryption.
            $this->pdo->prepare("DELETE FROM Properties WHERE $column = ?")->execute([$entityId]);
            return [];
        }
        $parsed = self::parse($content);

        $stmt = $this->pdo->prepare("SELECT id, name, value, weight FROM Properties WHERE $column = ? ORDER BY id");
        $stmt->execute([$entityId]);
        $stored = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $logWeights = [];
        foreach (defined('PROPERTY_WEIGHTS') ? PROPERTY_WEIGHTS : [] as $weight => $cfg) {
            if (($cfg['update_behavior'] ?? 'replace') === 'append') {
                $logWeights[] = (int)$weight;
            }
        }
        $isLog = static fn(int $weight): bool => in_array($weight, $logWeights, true);
        $notLog = $logWeights ? ' AND weight NOT IN (' . implode(',', $logWeights) . ')' : '';

        // Group by name, keeping the order of appearance.
        $wanted = ['replace' => [], 'append' => []];
        foreach ($parsed as $p) {
            $wanted[$isLog((int)$p['weight']) ? 'append' : 'replace'][$p['name']][] = [(string)$p['value'], (int)$p['weight']];
        }
        $have = ['replace' => [], 'append' => []];
        foreach ($stored as $row) {
            $have[$isLog((int)$row['weight']) ? 'append' : 'replace'][$row['name']][] = [(string)$row['value'], (int)$row['weight']];
        }

        // A task keeps the time it was first completed, however often the note is edited afterwards.
        if (isset($wanted['replace']['done_at'], $have['replace']['done_at'])) {
            $wanted['replace']['done_at'] = [$have['replace']['done_at'][0]];
        }

        $insert = $this->pdo->prepare("INSERT INTO Properties ($column, name, value, weight) VALUES (?, ?, ?, ?)");
        $changed = [];

        // Replaceable properties mirror the content exactly.
        foreach ($have['replace'] as $name => $values) {
            if (($wanted['replace'][$name] ?? null) !== $values) {
                $this->pdo->prepare("DELETE FROM Properties WHERE $column = ? AND name = ?$notLog")->execute([$entityId, $name]);
                unset($have['replace'][$name]);
            }
        }
        foreach ($wanted['replace'] as $name => $values) {
            if (isset($have['replace'][$name])) {
                continue; // unchanged
            }
            foreach ($values as [$value, $weight]) {
                $insert->execute([$entityId, $name, $value, $weight]);
            }
            $changed[$name] = $values[0][0];
        }

        // Log properties only grow: new values are appended when they differ
        // from the most recent entries, so re-saving a note adds nothing.
        foreach ($wanted['append'] as $name => $values) {
            $tail = array_slice($have['append'][$name] ?? [], -count($values));
            if ($tail === $values) {
                continue;
            }
            foreach ($values as [$value, $weight]) {
                $insert->execute([$entityId, $name, $value, $weight]);
            }
            $changed[$name] = $values[0][0];
        }

        foreach ($changed as $name => $value) {
            $this->triggers->dispatch($entityType, $entityId, $name, $value);
        }
        return $parsed;
    }
}
