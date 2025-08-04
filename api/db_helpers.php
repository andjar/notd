<?php
// api/db_helpers.php
// Helper functions for creating notes and pages with property indexing

require_once __DIR__ . '/uuid_utils.php';

if (!function_exists('_create_note_and_index_properties')) {
    function _create_note_and_index_properties(PDO $pdo, string $page_id, string $content, int $order_index) {
        $noteId = \App\UuidUtils::generateUuidV7();
        $stmt_insert_note = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
        $stmt_insert_note->execute([$noteId, $page_id, $content, $order_index]);
        
        if (!empty(trim($content))) {
            // Only process properties if PatternProcessor is available
            if (class_exists('App\PatternProcessor')) {
                $patternProcessor = new \App\PatternProcessor($pdo);
                $processedData = $patternProcessor->processContent($content, 'note', $noteId, ['pdo' => $pdo]);
                $parsedProperties = $processedData['properties'];
                if (!empty($parsedProperties)) {
                    $patternProcessor->saveProperties($parsedProperties, 'note', $noteId);
                }
            }
        }
    }
}

if (!function_exists('_create_page_and_index_properties')) {
    function _create_page_and_index_properties(PDO $pdo, string $name, ?string $content = null) {
        $pageId = \App\UuidUtils::generateUuidV7();
        $stmt_create_page = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
        $stmt_create_page->execute([$pageId, $name, $content]);
        
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