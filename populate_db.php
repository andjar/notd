<?php
$db_path = __DIR__ . '/db/notes.db'; // Adjusted path to be relative to this script's location if it's in root.
// If script is in /app/ then path is 'db/notes.db'

// For this execution, assuming script is in /app/ as that's the root for bash commands.
$db_path = 'db/notes.db';

try {
    $db = new SQLite3($db_path);
    if (!$db) {
        die("Error: Failed to connect to database at " . $db_path . " " . $db->lastErrorMsg());
    }
    $db->busyTimeout(5000);
    if (!$db->exec('PRAGMA foreign_keys = ON;')) {
        error_log("Notice: Attempted to enable foreign_keys. Check SQLite logs if issues persist with FKs.");
    }

    // Clear existing data
    $delete_stmts = [
        "DELETE FROM properties;",
        "DELETE FROM page_links;",
        "DELETE FROM notes_fts;", // Assuming this table exists from init.php
        "DELETE FROM notes;",
        "DELETE FROM pages;"
    ];

    foreach ($delete_stmts as $stmt) {
        if (!$db->exec($stmt)) {
            // Allow errors if tables don't exist yet (e.g. notes_fts might not if init was partial)
            // error_log("Notice: Error executing delete statement: " . $stmt . " - " . $db->lastErrorMsg());
        }
    }
    error_log("Existing data cleared (or tables did not exist yet).");

    $sql_statements = [
        // Pages
        "INSERT INTO pages (id, title, type, created_at, updated_at) VALUES ('page1', 'Page One', 'note', '2023-10-01T10:00:00Z', '2023-10-01T10:00:00Z');",
        "INSERT INTO pages (id, title, type, created_at, updated_at) VALUES ('page2', 'Page Two (Journal)', 'journal', '2023-10-02T10:00:00Z', '2023-10-02T10:00:00Z');",

        // Notes for Page One (page1)
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (1, 'page1', 'P1: Root Note 1', NULL, 0, '2023-10-27T08:00:00Z', '2023-10-27T09:00:00Z');",
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (2, 'page1', 'P1: Child of Note 1', 1, 0, '2023-10-27T09:05:00Z', '2023-10-27T09:05:00Z');",
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (3, 'page1', 'P1: Grandchild, Target Note', 2, 0, '2023-10-27T10:00:00Z', '2023-10-27T11:00:00Z');",
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (4, 'page1', 'P1: Child of Target Note', 3, 0, '2023-10-27T11:05:00Z', '2023-10-27T11:05:00Z');",
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (5, 'page1', 'P1: Another Child of Target', 3, 1, '2023-10-27T11:10:00Z', '2023-10-27T11:10:00Z');",
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (6, 'page1', 'P1: Root Note 2 (Recent)', NULL, 1, '2023-10-28T14:00:00Z', '2023-10-28T15:00:00Z');",
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (7, 'page1', 'P1: Old Note', NULL, 2, '2023-01-01T10:00:00Z', '2023-01-01T11:00:00Z');",

        // Notes for Page Two (page2)
        "INSERT INTO notes (id, page_id, content, parent_id, \"order\", created_at, updated_at) VALUES (8, 'page2', 'P2: Note updated same time as P1 Target', NULL, 0, '2023-10-27T10:30:00Z', '2023-10-27T11:00:00Z');",

        // Properties for Note 3 (Target Note)
        "INSERT INTO properties (note_id, property_key, property_value) VALUES (3, 'status', 'pending_review');",
        "INSERT INTO properties (note_id, property_key, property_value) VALUES (3, 'priority', 'high');",
        // Properties for Page One (page1) - This was an error in the prompt, properties table does not link directly to pages like this, it should be associated with a note or page_id is NULL if not note-specific.
        // Assuming the intent was a page-level property not tied to a specific note, or it's a property of a note on page1.
        // For now, I'll insert it as is, but the schema for properties has page_id and note_id, implying it can link to a page OR a note.
        // If it's a general page property, note_id would be NULL.
        "INSERT INTO properties (page_id, note_id, property_key, property_value) VALUES ('page1', NULL, 'category', 'research');"
    ];

    foreach ($sql_statements as $sql) {
        error_log("Executing: " . $sql); // Log each statement
        if (!$db->exec($sql)) {
            die("Error inserting data: " . $sql . " - " . $db->lastErrorMsg());
        }
    }

    echo "Database populated successfully!\n";

} catch (Exception $e) {
    die("An exception occurred: " . $e->getMessage());
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?>
