<?php
/**
 * GET /api/v1/search.php: one of
 *
 *   ?q=words                       full-text search in note content
 *   ?backlinks_for_page_name=Name  notes that link to a page
 *   ?tasks=TODO|DONE|...|ALL       notes that currently are tasks in that state
 *   ?favorites=1                   pages marked {favorite::true}
 *
 * Common: ?page, ?per_page (max 100), ?include_parent_properties=1
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\DataManager;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method Not Allowed', 405);
    return;
}

/**
 * Turns what a person typed into a full-text query that cannot be a syntax
 * error: every word becomes a quoted term (all must match); a trailing * on a
 * word, and the last word while typing, match by prefix.
 */
function notd_fts_query(string $input, bool $prefixLastWord = true): string
{
    preg_match_all('/[\p{L}\p{N}]+\*?/u', $input, $m);
    $terms = [];
    $last = count($m[0]) - 1;
    foreach ($m[0] as $i => $word) {
        $prefix = substr($word, -1) === '*' || ($prefixLastWord && $i === $last);
        $word = rtrim($word, '*');
        if ($word !== '') {
            // Quoting keeps AND, OR, NOT and NEAR from being read as operators.
            $terms[] = $prefix ? $word . '*' : '"' . $word . '"';
        }
    }
    return implode(' ', $terms);
}

/** A short excerpt of $content around the first occurrence of $term. */
function notd_snippet(string $content, string $term, int $length = 100): string
{
    $pos = $term === '' ? false : mb_stripos($content, $term);
    $start = $pos === false ? 0 : max(0, $pos - intdiv($length, 2));
    return ($start > 0 ? '...' : '') . mb_substr($content, $start, $length) . ($start + $length < mb_strlen($content) ? '...' : '');
}

$pdo = get_db_connection();
$dataManager = new DataManager($pdo);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;
$total = 0;
$results = [];

if (isset($_GET['q'])) {
    $match = notd_fts_query((string)$_GET['q']);
    if ($match === '') {
        ApiResponse::error('Search term is required.', 400);
        return;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM Notes_fts WHERE Notes_fts MATCH ?');
    $count->execute([$match]);
    $total = (int)$count->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT N.id AS note_id, N.content, N.page_id, P.name AS page_name,
                snippet(Notes_fts, '<mark>', '</mark>', '...', -1, 64) AS content_snippet
           FROM Notes_fts JOIN Notes N ON N.rid = Notes_fts.docid JOIN Pages P ON N.page_id = P.id
          WHERE Notes_fts MATCH ? ORDER BY N.updated_at DESC, N.id LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $match);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
} elseif (isset($_GET['backlinks_for_page_name'])) {
    $target = trim((string)$_GET['backlinks_for_page_name']);
    if ($target === '') {
        ApiResponse::error('Target page name is required.', 400);
        return;
    }
    $total = $dataManager->countBacklinks($target);
    $results = $dataManager->getBacklinks($target, $perPage, $offset);
    foreach ($results as &$result) {
        $result['content_snippet'] = notd_snippet((string)$result['content'], '[[' . $target . ']]');
    }
    unset($result);
} elseif (isset($_GET['tasks'])) {
    $status = strtoupper(trim((string)$_GET['tasks']));
    if ($status !== 'ALL' && !in_array($status, TASK_STATES, true)) {
        ApiResponse::error('Invalid task status. Use "all" or one of: ' . implode(', ', TASK_STATES), 400);
        return;
    }
    // A note is a task in state S when a line of its content starts with "S ".
    // The status property is a log of every state the note ever had, so the
    // property narrows the search and the content decides.
    $states = $status === 'ALL' ? TASK_STATES : [$status];
    $conditions = [];
    $params = [];
    foreach ($states as $state) {
        $conditions[] = "(N.content LIKE ? ESCAPE '\\' OR N.content LIKE ? ESCAPE '\\')";
        $like = \App\PageStore::likeEscape($state);
        $params[] = $like . ' %';
        $params[] = "%\n" . $like . ' %';
    }
    $where = "N.id IN (SELECT note_id FROM Properties WHERE name = 'status' AND value IN (" . implode(',', array_fill(0, count($states), '?')) . '))'
        . ' AND (' . implode(' OR ', $conditions) . ')';
    $params = array_merge($states, $params);

    $count = $pdo->prepare("SELECT COUNT(*) FROM Notes N WHERE $where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT N.id AS note_id, N.content, N.page_id, Pg.name AS page_name
           FROM Notes N JOIN Pages Pg ON N.page_id = Pg.id
          WHERE $where ORDER BY N.updated_at DESC, N.id LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    $statePattern = '/^(' . implode('|', array_map('preg_quote', $states)) . ')\s/m';
    foreach ($results as &$result) {
        $result['status'] = preg_match($statePattern, (string)$result['content'], $m) ? $m[1] : null;
        $result['content_snippet'] = notd_snippet((string)$result['content'], (string)$result['status']);
    }
    unset($result);
} elseif (isset($_GET['favorites'])) {
    $total = $dataManager->countFavoritePages();
    foreach ($dataManager->getFavoritePages($perPage, $offset) as $favorite) {
        $results[] = ['page_id' => $favorite['id'], 'page_name' => $favorite['name']];
    }
} else {
    ApiResponse::error('Missing search parameter. Use q, backlinks_for_page_name, tasks, or favorites', 400);
    return;
}

// Notes in the results carry their properties (and, on request, their ancestors').
$noteIds = array_values(array_filter(array_column($results, 'note_id'), 'strlen'));
if ($noteIds) {
    $properties = $dataManager->getPropertiesForNoteIds($noteIds, true);
    $parentProperties = notd_query_flag('include_parent_properties')
        ? $dataManager->getParentPropertiesForNoteIds($noteIds, true)
        : [];
    foreach ($results as &$result) {
        $result['page_id'] = (int)$result['page_id'];
        $result['properties'] = $properties[(string)$result['note_id']] ?? [];
        $result['parent_properties'] = $parentProperties[(string)$result['note_id']] ?? [];
    }
    unset($result);
}

ApiResponse::success([
    'results' => $results,
    'pagination' => [
        'total_items' => $total,
        'current_page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ],
]);
