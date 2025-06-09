<?php
require_once 'db_connect.php';
require_once 'response_utils.php'; // Include the new response utility
require_once 'data_manager.php';   // Include the new DataManager

// header('Content-Type: application/json'); // Will be handled by ApiResponse
$pdo = get_db_connection();
$dataManager = new DataManager($pdo); // Instantiate DataManager
$method = $_SERVER['REQUEST_METHOD'];

// Helper function to get content snippet with context
function get_content_snippet($content, $term, $context_length = 100) {
    $term_pos = stripos($content, $term);
    if ($term_pos === false) {
        return substr($content, 0, $context_length) . '...';
    }

    $start = max(0, $term_pos - $context_length / 2);
    $snippet = substr($content, $start, $context_length);
    
    if ($start > 0) {
        $snippet = '...' . $snippet;
    }
    if ($start + $context_length < strlen($content)) {
        $snippet .= '...';
    }
    
    return $snippet;
}

// Helper function to sanitize search term for FTS5
function sanitize_fts_term($term) {
    // Remove special FTS5 characters and escape quotes
    $term = str_replace(['"', "'", '(', ')', '[', ']', '{', '}', ':', ';', '!', '@', '#', '$', '%', '^', '&', '*', '+', '=', '|', '\\', '/', '<', '>', '?', '~', '`'], ' ', $term);
    // Trim and collapse whitespace
    $term = preg_replace('/\s+/', ' ', trim($term));
    return $term;
}

// Helper function to get pagination parameters with defaults
function get_pagination_params() {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;
    $offset = ($page - 1) * $per_page;
    return [$page, $per_page, $offset];
}

if ($method === 'GET') {
    try {
        // Get pagination parameters
        [$page, $per_page, $offset] = get_pagination_params();

        if (isset($_GET['q'])) {
            // Full-text search
            $term = sanitize_fts_term($_GET['q']);
            if (empty($term)) {
                ApiResponse::success(['results' => [], 'pagination' => ['total' => 0, 'page' => $page, 'per_page' => $per_page, 'total_pages' => 0]]);
                exit;
            }

            // Check if FTS5 is available
            $fts_check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Notes_fts'")->fetch();
            
            if ($fts_check) {
                // Get total count for FTS5
                $count_stmt = $pdo->prepare("
                    SELECT COUNT(*) as total
                    FROM Notes N 
                    JOIN Notes_fts FTS ON N.id = FTS.rowid 
                    WHERE Notes_fts MATCH ?
                ");
                $count_stmt->execute([$term]);
                $total = $count_stmt->fetch()['total'];

                // Use FTS5 for better performance with pagination
                $stmt = $pdo->prepare("
                    SELECT 
                        N.id as note_id,
                        N.content,
                        N.page_id,
                        P.name as page_name,
                        snippet(Notes_fts, 0, '<mark>', '</mark>', '...', 64) as content_snippet
                    FROM Notes N 
                    JOIN Pages P ON N.page_id = P.id 
                    JOIN Notes_fts FTS ON N.id = FTS.rowid 
                    WHERE Notes_fts MATCH ?
                    ORDER BY rank
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$term, $per_page, $offset]);
            } else {
                // Get total count for LIKE search
                $count_stmt = $pdo->prepare("
                    SELECT COUNT(*) as total
                    FROM Notes N 
                    JOIN Pages P ON N.page_id = P.id 
                    WHERE N.content LIKE ? OR P.name LIKE ?
                ");
                $like_term = '%' . str_replace('%', '\\%', $term) . '%';
                $count_stmt->execute([$like_term, $like_term]);
                $total = $count_stmt->fetch()['total'];

                // Fallback to LIKE search with pagination
                $stmt = $pdo->prepare("
                    SELECT 
                        N.id as note_id,
                        N.content,
                        N.page_id,
                        P.name as page_name
                    FROM Notes N 
                    JOIN Pages P ON N.page_id = P.id 
                    WHERE N.content LIKE ? OR P.name LIKE ?
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$like_term, $like_term, $per_page, $offset]);
            }

            $results = $stmt->fetchAll();
            
            // If using LIKE search, generate snippets manually
            if (!$fts_check) {
                foreach ($results as &$result) {
                    $result['content_snippet'] = get_content_snippet($result['content'], $term);
                }
            }

            $total_pages = ceil($total / $per_page);
            ApiResponse::success([
                'results' => $results,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => $total_pages
                ]
            ]);

        } elseif (isset($_GET['backlinks_for_page_name'])) {
            // Backlink search using 'links_to_page' properties
            $target_page_name = trim($_GET['backlinks_for_page_name']);
            if (empty($target_page_name)) {
                ApiResponse::success(['results' => [], 'pagination' => ['total' => 0, 'page' => $page, 'per_page' => $per_page, 'total_pages' => 0]]);
                exit;
            }

            // Get total count for backlinks
            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM Properties Prop
                JOIN Notes N ON Prop.note_id = N.id
                WHERE Prop.name = 'links_to_page' AND Prop.value = ? AND Prop.note_id IS NOT NULL
            ");
            $count_stmt->execute([$target_page_name]);
            $total = $count_stmt->fetch()['total'];

            $stmt = $pdo->prepare("
                SELECT 
                    N.id as note_id,
                    N.content,
                    N.page_id,
                    P.name as source_page_name
                FROM Properties Prop
                JOIN Notes N ON Prop.note_id = N.id
                JOIN Pages P ON N.page_id = P.id
                WHERE Prop.name = 'links_to_page' AND Prop.value = ? AND Prop.note_id IS NOT NULL
                ORDER BY N.updated_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute([$target_page_name, $per_page, $offset]);
            $results = $stmt->fetchAll();

            foreach ($results as &$result) {
                $result['content_snippet'] = get_content_snippet($result['content'], '[[' . $target_page_name . ']]');
            }
            unset($result);

            $total_pages = ceil($total / $per_page);
            ApiResponse::success([
                'results' => $results,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => $total_pages
                ]
            ]);

        } elseif (isset($_GET['tasks'])) {
            // Task search
            $status_filter = strtolower($_GET['tasks']);
            if (!in_array($status_filter, ['todo', 'done'])) {
                ApiResponse::error('Invalid task status. Use "todo" or "done"', 400);
                exit;
            }

            $property_value = $status_filter === 'todo' ? 'TODO' : 'DONE';
            
            // Get total count for tasks
            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM Properties Prop
                JOIN Notes N ON Prop.note_id = N.id
                WHERE Prop.name = 'status' AND Prop.value = ? AND Prop.note_id IS NOT NULL
            ");
            $count_stmt->execute([$property_value]);
            $total = $count_stmt->fetch()['total'];
            
            $stmt = $pdo->prepare("
                SELECT 
                    N.id as note_id,
                    N.content,
                    N.page_id,
                    Pg.name as page_name,
                    Prop.name as property_name,
                    Prop.value as property_value
                FROM Properties Prop
                JOIN Notes N ON Prop.note_id = N.id
                JOIN Pages Pg ON N.page_id = Pg.id
                WHERE Prop.name = 'status' AND Prop.value = ? AND Prop.note_id IS NOT NULL
                ORDER BY N.updated_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$property_value, $per_page, $offset]);
            $results = $stmt->fetchAll();

            $noteIds = array_column($results, 'note_id');
            $propertiesByNoteId = [];

            if (!empty($noteIds)) {
                $includeInternalProperties = true;
                $propertiesByNoteId = $dataManager->getPropertiesForNoteIds($noteIds, $includeInternalProperties);
            }

            foreach ($results as &$result) {
                $result['content_snippet'] = get_content_snippet($result['content'], $property_value);
                $result['properties'] = $propertiesByNoteId[$result['note_id']] ?? [];
            }
            unset($result);

            $total_pages = ceil($total / $per_page);
            ApiResponse::success([
                'results' => $results,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => $total_pages
                ]
            ]);

        } else {
            ApiResponse::error('Missing search parameter. Use q, backlinks_for_page_name, or tasks', 400);
        }
    } catch (PDOException $e) {
        ApiResponse::error('Search failed: ' . $e->getMessage(), 500);
    }
} else {
    ApiResponse::error('Method Not Allowed', 405);
}
