<?php
require_once 'db_connect.php';

header('Content-Type: application/json');
$pdo = get_db_connection();
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

if ($method === 'GET') {
    try {
        if (isset($_GET['q'])) {
            // Full-text search
            $term = sanitize_fts_term($_GET['q']);
            if (empty($term)) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            // Check if FTS5 is available
            $fts_check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Notes_fts'")->fetch();
            
            if ($fts_check) {
                // Use FTS5 for better performance
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
                    LIMIT 100
                ");
                $stmt->execute([$term]);
            } else {
                // Fallback to LIKE search
                $stmt = $pdo->prepare("
                    SELECT 
                        N.id as note_id,
                        N.content,
                        N.page_id,
                        P.name as page_name
                    FROM Notes N 
                    JOIN Pages P ON N.page_id = P.id 
                    WHERE N.content LIKE ? OR P.name LIKE ?
                    LIMIT 100
                ");
                $like_term = '%' . str_replace('%', '\\%', $term) . '%';
                $stmt->execute([$like_term, $like_term]);
            }

            $results = $stmt->fetchAll();
            
            // If using LIKE search, generate snippets manually
            if (!$fts_check) {
                foreach ($results as &$result) {
                    $result['content_snippet'] = get_content_snippet($result['content'], $term);
                }
            }

            echo json_encode(['success' => true, 'data' => $results]);

        } elseif (isset($_GET['backlinks_for_page_name'])) {
            // Backlink search
            $page_name = trim($_GET['backlinks_for_page_name']);
            if (empty($page_name)) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    N.id as note_id,
                    N.content,
                    N.page_id,
                    P.name as source_page_name
                FROM Notes N 
                JOIN Pages P ON N.page_id = P.id 
                WHERE N.content LIKE ?
                ORDER BY N.updated_at DESC
            ");
            
            $link_pattern = '%[[' . str_replace(['%', '_'], ['\\%', '\\_'], $page_name) . ']]%';
            $stmt->execute([$link_pattern]);
            $results = $stmt->fetchAll();

            // Generate snippets with context around the backlink
            foreach ($results as &$result) {
                $result['content_snippet'] = get_content_snippet($result['content'], '[[' . $page_name . ']]');
            }

            echo json_encode(['success' => true, 'data' => $results]);

        } elseif (isset($_GET['tasks'])) {
            // Task search
            $status = strtolower($_GET['tasks']);
            if (!in_array($status, ['todo', 'done'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid task status. Use "todo" or "done"']);
                exit;
            }

            $pattern = $status === 'todo' ? '%TODO%' : '%DONE%';
            
            $stmt = $pdo->prepare("
                SELECT 
                    N.id as note_id,
                    N.content,
                    N.page_id,
                    P.name as page_name
                FROM Notes N 
                JOIN Pages P ON N.page_id = P.id 
                WHERE N.content LIKE ?
                ORDER BY N.updated_at DESC
            ");
            $stmt->execute([$pattern]);
            $results = $stmt->fetchAll();

            // Get properties for each note
            foreach ($results as &$result) {
                $prop_stmt = $pdo->prepare("SELECT name, value FROM Properties WHERE note_id = ?");
                $prop_stmt->execute([$result['note_id']]);
                $properties = $prop_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $result['properties'] = $properties;
                
                // Generate snippet with context around the task marker
                $result['content_snippet'] = get_content_snippet($result['content'], $status === 'todo' ? 'TODO' : 'DONE');
            }

            echo json_encode(['success' => true, 'data' => $results]);

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing search parameter. Use q, backlinks_for_page_name, or tasks']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Search failed: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
}
