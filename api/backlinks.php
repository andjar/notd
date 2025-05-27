<?php
// CLI SAPI specific adaptations
if (php_sapi_name() == 'cli') {
    $_SERVER['REQUEST_METHOD'] = 'GET'; // backlinks.php is always GET

    // Populate $_GET from command line arguments (e.g., for page_id=value)
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg_idx => $arg_val) {
            if ($arg_idx == 0) continue; // skip script name itself
            if (strpos($arg_val, '=') !== false) {
                list($key, $value) = explode('=', $arg_val, 2);
                $_GET[$key] = $value;
            }
        }
    }
}

ob_start(); // Start output buffering

header('Content-Type: application/json');

// Set error handling for this script
error_reporting(E_ERROR);
ini_set('display_errors', 0); // Errors should be logged, not displayed for API
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Consistent error logging

// Custom error handler to convert errors to ErrorExceptions
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Database connection details
$db_path = __DIR__ . '/../db/notes.db'; // Make path robust

// Get page_id from query parameters
$page_id = $_GET['page_id'] ?? null;

if (!$page_id) {
    http_response_code(400);
    echo json_encode(['error' => 'page_id is required']);
    exit;
}

// Get limit and offset for pagination
$limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['default' => 20, 'min_range' => 1]]) : 20;
$offset = isset($_GET['offset']) ? filter_var($_GET['offset'], FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]) : 0;
if ($limit === false || $limit < 1) $limit = 20; // Ensure limit is positive
if ($offset === false || $offset < 0) $offset = 0; // Ensure offset is non-negative


try {
    // Connect to SQLite database
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5); // Set busy timeout to 5 seconds for PDO
    if (!$pdo->exec('PRAGMA foreign_keys = ON;')) {
        // error_log("Notice: Failed to enable foreign_keys for backlinks.php.");
    }

    // --- Count Total Threads ---
    $countSql = <<<'SQL'
WITH DirectLinkingNotes AS (
    SELECT DISTINCT pl.source_page_id, pl.source_note_id
    FROM page_links pl
    WHERE pl.target_page_id = :page_id AND pl.source_note_id IS NOT NULL
),
NoteAncestry AS (
    SELECT dln.source_page_id, dln.source_note_id AS linking_note_id, n.id AS current_ancestor_id, n.parent_id
    FROM DirectLinkingNotes dln JOIN notes n ON dln.source_note_id = n.id
    UNION ALL
    SELECT na.source_page_id, na.linking_note_id, p.id AS current_ancestor_id, p.parent_id
    FROM NoteAncestry na JOIN notes p ON na.parent_id = p.id
),
LinkingNoteHierarchyRoots AS (
    SELECT DISTINCT na.source_page_id, na.current_ancestor_id AS root_note_id
    FROM NoteAncestry na
    WHERE (na.parent_id IS NULL OR na.parent_id = 0)
)
SELECT COUNT(DISTINCT r.source_page_id || '-' || r.root_note_id) as total_threads
FROM LinkingNoteHierarchyRoots r;
SQL;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->bindParam(':page_id', $page_id, PDO::PARAM_STR);
    $countStmt->execute();
    $total_threads = (int)$countStmt->fetchColumn();
    $countStmt->closeCursor(); // Close cursor for count query


    // --- Main SQL Query for Paginated Threads ---
    $sql = <<<'SQL'
WITH
-- 1. Get the notes that directly link to the target_page_id
DirectLinkingNotes AS (
    SELECT DISTINCT
        pl.source_page_id,
        pl.source_note_id
    FROM page_links pl
    WHERE pl.target_page_id = :page_id AND pl.source_note_id IS NOT NULL
),

-- 2. For each linking note, trace its ancestry up to the root note on its page
NoteAncestry AS (
    SELECT
        dln.source_page_id,
        dln.source_note_id AS linking_note_id, 
        n.id AS current_ancestor_id,
        n.parent_id
    FROM DirectLinkingNotes dln
    JOIN notes n ON dln.source_note_id = n.id

    UNION ALL

    SELECT
        na.source_page_id,
        na.linking_note_id,
        p.id AS current_ancestor_id,
        p.parent_id
    FROM NoteAncestry na
    JOIN notes p ON na.parent_id = p.id
),

-- 3. Identify the root of each linking note's hierarchy
LinkingNoteHierarchyRoots AS (
    SELECT DISTINCT 
        na.source_page_id,
        na.current_ancestor_id AS root_note_id -- Removed linking_note_id as it's not needed for PaginatedRoots
    FROM NoteAncestry na
    WHERE (na.parent_id IS NULL OR na.parent_id = 0)
),

-- 3.5. Paginate the identified roots
PaginatedRoots AS (
    SELECT DISTINCT
        r.source_page_id,
        r.root_note_id
    FROM LinkingNoteHierarchyRoots r
    ORDER BY r.source_page_id, r.root_note_id -- Define a stable order for pagination
    LIMIT :limit OFFSET :offset
),

-- 4. Fetch all notes belonging to these PAGINATED root hierarchies
FullThreadsForSelectedRoots AS (
    SELECT
        pr.source_page_id,
        pr.root_note_id, 
        n.id,
        n.page_id AS note_actual_page_id,
        n.content,
        n.parent_id AS actual_parent_id, 
        n.block_id,
        n.created_at,
        n.updated_at,
        n."order" AS note_order
    FROM PaginatedRoots pr -- Start with PaginatedRoots
    JOIN notes n ON pr.root_note_id = n.id AND pr.source_page_id = n.page_id

    UNION ALL

    SELECT
        ft.source_page_id,
        ft.root_note_id, 
        child.id,
        child.page_id AS note_actual_page_id,
        child.content,
        child.parent_id AS actual_parent_id,
        child.block_id,
        child.created_at,
        child.updated_at,
        child."order" AS note_order
    FROM FullThreadsForSelectedRoots ft -- Recursive part uses the new CTE name
    JOIN notes child ON ft.id = child.parent_id 
    WHERE child.page_id = ft.source_page_id 
)
SELECT
    ft.source_page_id AS linking_page_id,
    p.title AS linking_page_title,
    ft.id,
    ft.content,
    ft.actual_parent_id AS parent_id, 
    ft.block_id,
    ft.created_at,
    ft.updated_at,
    ft.root_note_id, 
    ft.note_order
FROM FullThreadsForSelectedRoots ft -- Final select from the new CTE
JOIN pages p ON ft.source_page_id = p.id
ORDER BY
    ft.source_page_id,
    ft.root_note_id,
    ft.note_order,
    ft.id;
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':page_id', $page_id, PDO::PARAM_STR);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor(); // Close cursor for main query

    // Process results to group notes into threads
    $threads = [];
    $currentRootNoteId = null;
    $currentSourcePageId = null; // Need to track source_page_id as well for robust thread grouping
    $currentNotes = [];
    $linkingPageId = null;
    $linkingPageTitle = null;

    foreach ($results as $row) {
        // A thread is uniquely identified by the combination of source_page_id and root_note_id
        if ($row['linking_page_id'] !== $currentSourcePageId || $row['root_note_id'] !== $currentRootNoteId) {
            if ($currentRootNoteId !== null && $currentSourcePageId !== null) {
                $threads[] = [
                    'linking_page_id' => $linkingPageId, // This was $currentSourcePageId before variable rename
                    'linking_page_title' => $linkingPageTitle,
                    'notes' => $currentNotes
                ];
            }
            $currentSourcePageId = $row['linking_page_id'];
            $currentRootNoteId = $row['root_note_id'];
            $linkingPageId = $row['linking_page_id']; // Keep for clarity, same as currentSourcePageId
            $linkingPageTitle = $row['linking_page_title'];
            $currentNotes = [];
        }

        $currentNotes[] = [
            'id' => $row['id'],
            'content' => $row['content'],
            'parent_id' => $row['parent_id'],
            'block_id' => $row['block_id'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    if ($currentRootNoteId !== null && $currentSourcePageId !== null) {
        $threads[] = [
            'linking_page_id' => $linkingPageId,
            'linking_page_title' => $linkingPageTitle,
            'notes' => $currentNotes
        ];
    }

    // Prepare the final response structure
    $response = [
        'threads' => $threads,
        'total_threads' => $total_threads,
        'limit' => $limit,
        'offset' => $offset
    ];

    if (ob_get_level() > 0 && ob_get_length() > 0) {
        ob_clean(); 
    }
    if (!headers_sent()) {
         header('Content-Type: application/json');
    }
    echo json_encode($response);

} catch (Throwable $e) { 
    if (ob_get_level() > 0 && ob_get_length() > 0) { 
        ob_clean(); 
    }
    if (!headers_sent()) { 
      header('Content-Type: application/json'); 
      http_response_code(500); 
    }
    error_log("Error in backlinks.php: " . $e->getMessage() . "\nStack trace:\n" . $e->getTraceAsString());
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
} finally {
    if (isset($countStmt)) {
        $countStmt->closeCursor();
    }
    if (isset($stmt)) {
        $stmt->closeCursor();
    }
    if (isset($pdo)) {
        $pdo = null;
    }
    if (ob_get_level() > 0) { 
        ob_end_flush(); 
    }
}

?>
