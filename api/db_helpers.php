<?php
// api/db_helpers.php
// Helper functions for creating notes and pages with property indexing

if (!function_exists('_create_note_and_index_properties')) {
    function _create_note_and_index_properties(PDO $pdo, int $page_id, string $content, int $order_index) {
        require_once __DIR__ . '/pattern_processor.php';
        $stmt_insert_note = $pdo->prepare("INSERT INTO Notes (page_id, content, order_index) VALUES (?, ?, ?)");
        $stmt_insert_note->execute([$page_id, $content, $order_index]);
        $noteId = $pdo->lastInsertId();
        if (!$noteId) throw new Exception("Failed to create note record for welcome note.");
        if (!empty(trim($content))) {
            $patternProcessor = new \App\PatternProcessor($pdo);
            $processedData = $patternProcessor->processContent($content, 'note', $noteId, ['pdo' => $pdo]);
            $parsedProperties = $processedData['properties'];
            if (!empty($parsedProperties)) {
                $patternProcessor->saveProperties($parsedProperties, 'note', $noteId);
            }
        }
    }
}

if (!function_exists('_create_page_and_index_properties')) {
    function _create_page_and_index_properties(PDO $pdo, string $name, ?string $content = null) {
        require_once __DIR__ . '/pattern_processor.php';
        $stmt_create_page = $pdo->prepare("INSERT INTO Pages (name, content) VALUES (?, ?)");
        $stmt_create_page->execute([$name, $content]);
        $pageId = $pdo->lastInsertId();
        if (!$pageId) throw new Exception("Failed to create page record for '$name'.");
        if ($content && !empty(trim($content))) {
            $patternProcessor = new \App\PatternProcessor($pdo);
            $processedData = $patternProcessor->processContent($content, 'page', $pageId, ['pdo' => $pdo]);
            $parsedProperties = $processedData['properties'];
            if (!empty($parsedProperties)) {
                $patternProcessor->saveProperties($parsedProperties, 'page', $pageId);
            }
        }
        return $pageId;
    }
} 