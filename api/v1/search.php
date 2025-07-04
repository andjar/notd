<?php

namespace App;

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../../config.php';

$pdo = get_db_connection();
$dataManager = new \App\DataManager($pdo);
$method = $_SERVER['REQUEST_METHOD'];

function get_content_snippet($content, $term, $context_length = 100) {
    $term_pos = stripos($content, $term);
    if ($term_pos === false) return substr($content, 0, $context_length) . (strlen($content) > $context_length ? '...' : '');
    $start = max(0, $term_pos - ($context_length / 2));
    $snippet = substr($content, $start, $context_length);
    if ($start > 0) $snippet = '...' . $snippet;
    if (($start + $context_length) < strlen($content)) $snippet .= '...';
    return $snippet;
}

function sanitize_fts_term($term) {
    $term = preg_replace('/[^\p{L}\p{N}\s\-_*]/u', ' ', $term);
    return trim(preg_replace('/\s+/', ' ', $term));
}

function get_pagination_params() {
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, min(100, intval($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $per_page;
    return [$page, $per_page, $offset];
}

function attach_properties_to_results($dataManager, &$results, $includeParentProps = false) {
    $noteIds = [];
    // Collect note_ids only if they exist in the results (e.g. 'favorites' search might not have note_id)
    foreach ($results as $result) {
        if (isset($result['note_id'])) {
            $noteIds[] = $result['note_id'];
        }
    }

    if (empty($noteIds)) return;

    // Pass the $includeParentProps parameter to getPropertiesForNoteIds
    $propertiesByNoteId = $dataManager->getPropertiesForNoteIds($noteIds, true, $includeParentProps);

    foreach ($results as &$result) {
        if (isset($result['note_id']) && isset($propertiesByNoteId[$result['note_id']])) {
            // Direct properties are keys in $propertiesByNoteId[$result['note_id']] itself, excluding 'parent_properties'
            $directProperties = $propertiesByNoteId[$result['note_id']];
            unset($directProperties['parent_properties']); // Remove parent_properties from direct
            $result['properties'] = $directProperties ?? [];

            // Parent properties are under the 'parent_properties' key
            $result['parent_properties'] = $propertiesByNoteId[$result['note_id']]['parent_properties'] ?? [];
        } else {
            // Ensure keys exist even if no properties were found or no note_id
            $result['properties'] = [];
            $result['parent_properties'] = [];
        }
    }
}

if ($method === 'GET') {
    try {
        // Convert include_parent_properties to boolean, defaulting to false if not set
        $includeParentProps = filter_input(INPUT_GET, 'include_parent_properties', FILTER_VALIDATE_BOOLEAN) ?? false;
        [$page, $per_page, $offset] = get_pagination_params();
        $total = 0;
        $results = [];

        if (isset($_GET['q'])) {
            $term = sanitize_fts_term($_GET['q']);
            if (empty($term)) {
                \App\ApiResponse::error('Search term is required.', 400);
            }
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM Notes_fts WHERE Notes_fts MATCH ?");
            $count_stmt->execute([$term]);
            $total = (int)$count_stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT N.id as note_id, N.content, N.page_id, P.name as page_name, " .
                "snippet(Notes_fts, '<mark>', '</mark>', '...', -1, 64) as content_snippet " .
                "FROM Notes N JOIN Pages P ON N.page_id = P.id JOIN Notes_fts FTS ON N.id = FTS.rowid WHERE Notes_fts MATCH ? ORDER BY N.updated_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->execute([$term, $per_page, $offset]);
            $results = $stmt->fetchAll();

        } elseif (isset($_GET['backlinks_for_page_name'])) {
            $target_page_name = trim($_GET['backlinks_for_page_name']);
            if (empty($target_page_name)) \App\ApiResponse::error('Target page name is required.', 400);

            $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT N.id) FROM Properties Prop JOIN Notes N ON Prop.note_id = N.id WHERE Prop.name = 'links_to_page' AND Prop.value = ?");
            $count_stmt->execute([$target_page_name]);
            $total = (int)$count_stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT N.id as note_id, N.content, N.page_id, P.name as page_name FROM Properties Prop JOIN Notes N ON Prop.note_id = N.id JOIN Pages P ON N.page_id = P.id WHERE Prop.name = 'links_to_page' AND Prop.value = ? GROUP BY N.id ORDER BY N.updated_at DESC LIMIT ? OFFSET ?");
            $stmt->execute([$target_page_name, $per_page, $offset]);
            $results = $stmt->fetchAll();
            foreach ($results as &$result) $result['content_snippet'] = get_content_snippet($result['content'], '[[' . $target_page_name . ']]');

        } elseif (isset($_GET['tasks'])) {
            $status = strtoupper(trim($_GET['tasks']));
            if ($status !== 'ALL' && !in_array($status, TASK_STATES)) {
                \App\ApiResponse::error('Invalid task status. Use "all" or one of: ' . implode(', ', TASK_STATES), 400);
            }

            if ($status === 'ALL') {
                $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT N.id) FROM Properties Prop JOIN Notes N ON Prop.note_id = N.id WHERE Prop.name = 'status'");
                $count_stmt->execute();
                $total = (int)$count_stmt->fetchColumn();

                $stmt = $pdo->prepare(<<<SQL
SELECT N.id as note_id, N.content, N.page_id, Pg.name as page_name, Prop.value as status
FROM Notes N
JOIN Pages Pg ON N.page_id = Pg.id
JOIN Properties Prop ON Prop.note_id = N.id AND Prop.name = 'status'
  AND Prop.id = (
    SELECT MAX(p2.id) FROM Properties p2 WHERE p2.note_id = N.id AND p2.name = 'status'
  )
ORDER BY N.updated_at DESC
LIMIT ? OFFSET ?
SQL
                );
                $stmt->execute([$per_page, $offset]);
                $results = $stmt->fetchAll();
                foreach ($results as &$result) $result['content_snippet'] = get_content_snippet($result['content'], '{status::' . $result['status'] . '}');
            } else {
                $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT N.id) FROM Properties Prop JOIN Notes N ON Prop.note_id = N.id WHERE Prop.name = 'status' AND Prop.value = ?");
                $count_stmt->execute([$status]);
                $total = (int)$count_stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT N.id as note_id, N.content, N.page_id, Pg.name as page_name FROM Properties Prop JOIN Notes N ON Prop.note_id = N.id JOIN Pages Pg ON N.page_id = Pg.id WHERE Prop.name = 'status' AND Prop.value = ? GROUP BY N.id ORDER BY N.updated_at DESC LIMIT ? OFFSET ?");
                $stmt->execute([$status, $per_page, $offset]);
                $results = $stmt->fetchAll();
                foreach ($results as &$result) $result['content_snippet'] = get_content_snippet($result['content'], '{status::' . $status . '}');
            }

        } elseif (isset($_GET['favorites'])) {
            // Get pages that have a favorite property
            $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT P.id) FROM Pages P JOIN Properties Prop ON P.id = Prop.page_id WHERE Prop.name = 'favorite' AND Prop.value = 'true'");
            $count_stmt->execute();
            $total = (int)$count_stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT DISTINCT P.id as page_id, P.name as page_name FROM Pages P JOIN Properties Prop ON P.id = Prop.page_id WHERE Prop.name = 'favorite' AND Prop.value = 'true' ORDER BY P.updated_at DESC LIMIT ? OFFSET ?");
            $stmt->execute([$per_page, $offset]);
            $results = $stmt->fetchAll();

        } else {
            \App\ApiResponse::error('Missing search parameter. Use q, backlinks_for_page_name, tasks, or favorites', 400);
        }

        // Attach properties to all results, regardless of search type
        attach_properties_to_results($dataManager, $results, $includeParentProps);

        \App\ApiResponse::success([
            'results' => $results,
            'pagination' => [
                'total_items' => $total,
                'current_page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            ]
        ]);

    } catch (PDOException $e) {
        // FTS5 might throw an error on invalid syntax
        error_log("Search Error: " . $e->getMessage());
        \App\ApiResponse::error('Search failed. Please check your search term or contact support.', 500, ['details' => $e->getMessage()]);
    }
} else {
    \App\ApiResponse::error('Method Not Allowed', 405);
}