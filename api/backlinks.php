<?php
header('Content-Type: application/json');

// Database connection details
$db_path = '../db/notes.db';

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

    // Construct the search term for backlinks
    $searchTerm = '%[[' . $page_id . ']]%';

    // SQL query with recursive CTE
    $sql = "
        WITH RECURSIVE NoteHierarchy AS (
            -- Anchor member: Select initial linking notes and their page titles
            SELECT
                n.id,
                n.page_id,
                p.title AS page_title,
                n.content,
                n.level,
                n.parent_id,
                n.block_id,
                n.created_at,
                n.updated_at,
                n.id AS root_note_id -- Keep track of the root linking note for grouping
            FROM
                notes n
            JOIN
                pages p ON n.page_id = p.page_id
            WHERE
                n.content LIKE :searchTerm

            UNION ALL

            -- Recursive member: Select descendant notes
            SELECT
                child.id,
                child.page_id,
                nh.page_title, -- Carry over the page_title from the anchor
                child.content,
                child.level,
                child.parent_id,
                child.block_id,
                child.created_at,
                child.updated_at,
                nh.root_note_id -- Carry over the root_note_id
            FROM
                notes child
            JOIN
                NoteHierarchy nh ON child.parent_id = nh.id
            WHERE child.page_id = nh.page_id -- Ensure descendants are within the same page as the linking note
        )
        SELECT
            nh.page_id AS linking_page_id,
            nh.page_title AS linking_page_title,
            nh.id,
            nh.content,
            nh.level,
            nh.parent_id,
            nh.block_id,
            nh.created_at,
            nh.updated_at,
            nh.root_note_id
        FROM
            NoteHierarchy nh
        ORDER BY
            nh.root_note_id, -- Group by the original linking note
            nh.level,        -- Order by level within each hierarchy
            nh.id            -- Consistent ordering for notes at the same level
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':searchTerm', $searchTerm, PDO::PARAM_STR);
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
