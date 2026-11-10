<?php
// api/db_helpers.php
// Helper functions for creating notes and pages with property indexing

require_once __DIR__ . '/NoteOps.php';

if (!function_exists('_create_note_and_index_properties')) {
    /**
     * Appends a note at the end of a page (or of a parent's children) and
     * indexes its properties. Returns the new note id. Caller owns the transaction.
     */
    function _create_note_and_index_properties(PDO $pdo, int $page_id, string $content, ?string $parent_id = null): string {
        $ops = new \App\NoteOps($pdo);
        $createOps = $ops->appendCreateOps($page_id, [['content' => $content]], $parent_id);
        $ops->apply($createOps);
        return $createOps[0]['id'];
    }
}

if (!function_exists('_create_page_and_index_properties')) {
    function _create_page_and_index_properties(PDO $pdo, string $name, ?string $content = null) {
        $stmt_create_page = $pdo->prepare("INSERT INTO Pages (name, content) VALUES (?, ?)");
        $stmt_create_page->execute([$name, $content]);
        $pageId = $pdo->lastInsertId();
        if (!$pageId) throw new Exception("Failed to create page record for '$name'.");
        if ($content && !empty(trim($content))) {
            // Only process properties if PatternProcessor is available
            if (class_exists('App\PatternProcessor')) {
                $patternProcessor = new \App\PatternProcessor($pdo);
                $processedData = $patternProcessor->processContent($content, 'page', $pageId, ['pdo' => $pdo]);
                $parsedProperties = $processedData['properties'];
                if (!empty($parsedProperties)) {
                    $patternProcessor->saveProperties($parsedProperties, 'page', $pageId);
                }
            }
        }
        return $pageId;
    }
} 