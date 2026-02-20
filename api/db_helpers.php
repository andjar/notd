<?php

// api/db_helpers.php
// Helper functions for creating notes and pages with property indexing

require_once __DIR__ . '/UuidUtils.php';

if (!function_exists('_create_note_and_index_properties')) {
    function _create_note_and_index_properties(PDO $pdo, string $page_id, string $content, int $order_index) {
        $noteId = UuidUtils::generateUuidV7();
        $stmt_insert_note = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
        $stmt_insert_note->execute([$noteId, $page_id, $content, $order_index]);
        
        // Skip property processing during initial database setup to avoid namespace issues
        // Properties will be processed when the application is fully loaded
    }
}

if (!function_exists('_create_page_and_index_properties')) {
    function _create_page_and_index_properties(PDO $pdo, string $name, ?string $content = null) {
        $pageId = UuidUtils::generateUuidV7();
        $stmt_create_page = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
        $stmt_create_page->execute([$pageId, $name, $content]);
        
        // Skip property processing during initial database setup to avoid namespace issues
        // Properties will be processed when the application is fully loaded
        return $pageId;
    }
} 