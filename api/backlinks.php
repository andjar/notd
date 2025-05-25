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

header('Content-Type: application/json');

// Set error handling for this script
error_reporting(E_ALL);
ini_set('display_errors', 0); // Errors should be logged, not displayed for API
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Consistent error logging

// Database connection details
$db_path = __DIR__ . '/../db/notes.db'; // Make path robust

// Get page_id from query parameters
$page_id = $_GET['page_id'] ?? null;

if (!$page_id) {
    http_response_code(400);
    echo json_encode(['error' => 'page_id is required']);
    exit;
}

try {
    // Connect to SQLite database
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5); // Set busy timeout to 5 seconds for PDO
    // Enable foreign key constraints for this connection
    if (!$pdo->exec('PRAGMA foreign_keys = ON;')) {
        // Log or handle error if PRAGMA command fails.
        // For PDO, exec() returns the number of rows affected. For PRAGMA, this might be 0 or 1.
        // A failure here is unlikely to throw an exception unless ATTR_ERRMODE is set to ERRMODE_EXCEPTION and the PRAGMA itself is malformed.
        // If it returns false, it indicates failure.
        error_log("Notice: Attempted to enable foreign_keys for backlinks.php. Check SQLite logs if issues persist with FKs.");
        // Depending on strictness, one might throw an exception here if exec returns false.
    }

    // SQL query using page_links table and recursive CTEs
    // Using Nowdoc for safety with SQL containing quotes or special characters
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
        dln.source_note_id AS linking_note_id, -- The specific note that contains the link
        n.id AS current_ancestor_id,
        n.parent_id
    FROM DirectLinkingNotes dln
    JOIN notes n ON dln.source_note_id = n.id -- Start with the linking note itself

    UNION ALL

    SELECT
        na.source_page_id,
        na.linking_note_id,
        p.id AS current_ancestor_id,
        p.parent_id
    FROM NoteAncestry na
    JOIN notes p ON na.parent_id = p.id -- Go to the parent (recursion stops when parent_id is NULL)
),

-- 3. Identify the root of each linking note's hierarchy
LinkingNoteHierarchyRoots AS (
    SELECT DISTINCT -- A single linking_note_id will trace to one root
        na.source_page_id,
        na.linking_note_id, 
        na.current_ancestor_id AS root_note_id
    FROM NoteAncestry na
    WHERE (na.parent_id IS NULL OR na.parent_id = 0) -- This is the top-most parent (0 also considered root-like)
),

-- 4. Fetch all notes belonging to these identified root hierarchies (the full "threads")
-- This means all descendants of each root_note_id on its specific source_page_id
FullThreads AS (
    SELECT
        r.source_page_id,
        r.root_note_id, -- This is the key for grouping in PHP
        n.id,
        n.page_id AS note_actual_page_id, 
        n.content,
        n.level,
        n.parent_id AS actual_parent_id, -- Select actual parent_id from notes table
        n.block_id,
        n.created_at,
        n.updated_at
    FROM LinkingNoteHierarchyRoots r
    JOIN notes n ON r.root_note_id = n.id AND r.source_page_id = n.page_id -- Start with the root notes themselves

    UNION ALL

    SELECT
        ft.source_page_id,
        ft.root_note_id, -- Carry over the root_note_id
        child.id,
        child.page_id AS note_actual_page_id,
        child.content,
        child.level,
        child.parent_id AS actual_parent_id, -- Select actual parent_id from notes table (aliased as child)
        child.block_id,
        child.created_at,
        child.updated_at
    FROM FullThreads ft
    JOIN notes child ON ft.id = child.parent_id -- Correct join condition using actual column name
    WHERE child.page_id = ft.source_page_id -- Crucial: Ensure children are on the same page
)
SELECT
    ft.source_page_id AS linking_page_id,
    p.title AS linking_page_title,
    ft.id,
    ft.content,
    ft.level,
    ft.actual_parent_id AS parent_id, -- Use the correctly aliased parent_id
    ft.block_id,
    ft.created_at,
    ft.updated_at,
    ft.root_note_id -- This is critical for the PHP grouping logic
FROM FullThreads ft
JOIN pages p ON ft.source_page_id = p.id
ORDER BY
    ft.source_page_id, 
    ft.root_note_id,   
    ft.level,          
    ft.id;
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':page_id', $page_id, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process results to group notes into threads
    $threads = [];
    $currentRootNoteId = null;
    $currentNotes = [];
    $linkingPageId = null;
    $linkingPageTitle = null;

    foreach ($results as $row) {
        if ($row['root_note_id'] !== $currentRootNoteId) {
            // Starting a new thread
            if ($currentRootNoteId !== null) {
                // Save the previous thread
                $threads[] = [
                    'linking_page_id' => $linkingPageId,
                    'linking_page_title' => $linkingPageTitle,
                    'notes' => $currentNotes
                ];
            }
            // Reset for the new thread
            $currentRootNoteId = $row['root_note_id'];
            $linkingPageId = $row['linking_page_id'];
            $linkingPageTitle = $row['linking_page_title'];
            $currentNotes = [];
        }

        // Add note to the current thread
        $currentNotes[] = [
            'id' => $row['id'],
            'content' => $row['content'],
            'level' => $row['level'],
            'parent_id' => $row['parent_id'],
            'block_id' => $row['block_id'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
            // Note: page_id and page_title are part of the thread, not each note here
        ];
    }

    // Add the last processed thread
    if ($currentRootNoteId !== null) {
        $threads[] = [
            'linking_page_id' => $linkingPageId,
            'linking_page_title' => $linkingPageTitle,
            'notes' => $currentNotes
        ];
    }

    echo json_encode($threads);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} finally {
    // Close the database connection
    if (isset($pdo)) {
        $pdo = null;
    }
}

?>
