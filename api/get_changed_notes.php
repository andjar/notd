<?php
// CLI SAPI specific adaptations
if (php_sapi_name() == 'cli') {
    // Allow REQUEST_METHOD to be set via environment variable for CLI testing
    if (getenv('REQUEST_METHOD')) {
        $_SERVER['REQUEST_METHOD'] = getenv('REQUEST_METHOD');
    } else {
        // Default to GET if not specified for CLI
        $_SERVER['REQUEST_METHOD'] = 'GET'; 
    }

    // Populate $_GET from command line arguments (e.g., for id=value)
    // This should run for any CLI request type if $argv is present.
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg_idx => $arg_val) {
            if ($arg_idx == 0) continue; // skip script name itself
            if (strpos($arg_val, '=') !== false) {
                list($key, $value) = explode('=', $arg_val, 2);
                $_GET[$key] = $value; // Populate $_GET for CLI
            }
        }
    }
}

// Prevent any output before headers
ob_start();

// Set error handling
error_reporting(E_ERROR);
ini_set('display_errors', 0); // Display errors are off, check log file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Use absolute path for log

// Set JSON header
header('Content-Type: application/json');

// Custom error handler to convert errors to ErrorExceptions
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Respect error_reporting level
    if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    $db = new SQLite3(__DIR__ . '/../db/notes.db'); // Use absolute path for database
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }
    $db->busyTimeout(5000); // Set busy timeout to 5000 milliseconds (5 seconds)
    // Enable foreign key constraints for this connection
    if (!$db->exec('PRAGMA foreign_keys = ON;')) {
        // Log or handle error if PRAGMA command fails
        error_log("Notice: Attempted to enable foreign_keys. Check SQLite logs if issues persist with FKs.");
    }

    // Handle the request
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $since_timestamp = $_GET['since_timestamp'] ?? null;

        if (empty($since_timestamp)) {
            throw new Exception('since_timestamp parameter is required.');
        }

        // Validate ISO 8601 format
        // Regex covers YYYY-MM-DDTHH:MM:SSZ, YYYY-MM-DDTHH:MM:SS+HH:MM, YYYY-MM-DDTHH:MM:SS-HH:MM
        // and optionally milliseconds YYYY-MM-DDTHH:MM:SS.sssZ etc.
        $iso8601_pattern = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?)(Z|[\+\-]\d{2}:\d{2})$/';
        if (!preg_match($iso8601_pattern, $since_timestamp)) {
            // Attempt to parse with DateTime to be more robust, as regex might miss some valid edge cases or be too strict.
            // PHP's DateTime::ATOM or DateTime::ISO8601 is good for this.
            // However, the specific regex from the prompt is used here.
            // A more robust check might involve:
            // $dateTime = DateTime::createFromFormat(DateTime::ATOM, $since_timestamp);
            // if ($dateTime === false) { ... error ... }
            // For this task, sticking to the provided regex pattern.
            throw new Exception('Invalid since_timestamp format. Please use ISO 8601 format (e.g., YYYY-MM-DDTHH:MM:SSZ).');
        }

        // If validation passes, proceed to fetch notes
        $stmt = $db->prepare('SELECT id, page_id, content, parent_id, "order", updated_at FROM notes WHERE updated_at > :since_timestamp ORDER BY updated_at ASC');
        if (!$stmt) {
            throw new Exception('Failed to prepare statement to fetch notes: ' . $db->lastErrorMsg());
        }
        
        // Bind the validated since_timestamp
        $stmt->bindValue(':since_timestamp', $since_timestamp, SQLITE3_TEXT); 

        $result = $stmt->execute();
        if (!$result) {
            throw new Exception('Failed to execute statement to fetch notes: ' . $db->lastErrorMsg());
        }

        $changed_notes = [];
        while ($note = $result->fetchArray(SQLITE3_ASSOC)) {
            $initial_notes[] = $note;
        }
        $stmt->close(); // Close the initial statement

        $note_packages = [];
        foreach ($initial_notes as $note_data) {
            $package = [
                'note_id' => $note_data['id'],
                'page_id' => $note_data['page_id'],
                'note_content' => $note_data['content'],
                'parent_id' => $note_data['parent_id'], // Keep original parent_id for context
                'order' => $note_data['order'],         // Keep original order for context
                'note_updated_at' => $note_data['updated_at'],
                'page_title' => null,
                'parent_note' => null,
                'children_notes' => [],
                'properties' => [],
                'ancestor_path_titles' => [] // Placeholder
            ];

            // Fetch Page Title
            $stmt_page = $db->prepare('SELECT title FROM pages WHERE id = :page_id');
            if (!$stmt_page) throw new Exception('Failed to prepare page title statement: ' . $db->lastErrorMsg());
            $stmt_page->bindValue(':page_id', $note_data['page_id'], SQLITE3_TEXT);
            $page_result = $stmt_page->execute();
            if ($page_row = $page_result->fetchArray(SQLITE3_ASSOC)) {
                $package['page_title'] = $page_row['title'];
            }
            $stmt_page->close();

            // Fetch Parent Note Content
            if (!empty($note_data['parent_id'])) {
                $stmt_parent = $db->prepare('SELECT id, content FROM notes WHERE id = :parent_id');
                if (!$stmt_parent) throw new Exception('Failed to prepare parent note statement: ' . $db->lastErrorMsg());
                $stmt_parent->bindValue(':parent_id', $note_data['parent_id'], SQLITE3_INTEGER);
                $parent_result = $stmt_parent->execute();
                if ($parent_row = $parent_result->fetchArray(SQLITE3_ASSOC)) {
                    $package['parent_note'] = ['id' => $parent_row['id'], 'content' => $parent_row['content']];
                }
                $stmt_parent->close();
            }

            // Fetch Children Notes
            $stmt_children = $db->prepare('SELECT id, content, "order" FROM notes WHERE parent_id = :current_note_id ORDER BY "order" ASC');
            if (!$stmt_children) throw new Exception('Failed to prepare children notes statement: ' . $db->lastErrorMsg());
            $stmt_children->bindValue(':current_note_id', $note_data['id'], SQLITE3_INTEGER);
            $children_result = $stmt_children->execute();
            $children_array = [];
            while ($child_row = $children_result->fetchArray(SQLITE3_ASSOC)) {
                $children_array[] = ['id' => $child_row['id'], 'content' => $child_row['content'], 'order' => $child_row['order']];
            }
            $package['children_notes'] = $children_array;
            $stmt_children->close();

            // Fetch Note Properties
            $stmt_props = $db->prepare('SELECT property_key, property_value FROM properties WHERE note_id = :current_note_id');
            if (!$stmt_props) throw new Exception('Failed to prepare properties statement: ' . $db->lastErrorMsg());
            $stmt_props->bindValue(':current_note_id', $note_data['id'], SQLITE3_INTEGER);
            $props_result = $stmt_props->execute();
            $props_array = [];
            while ($prop_row = $props_result->fetchArray(SQLITE3_ASSOC)) {
                $props_array[] = ['property_key' => $prop_row['property_key'], 'property_value' => $prop_row['property_value']];
            }
            $package['properties'] = $props_array;
            $stmt_props->close();

            // Fetch Ancestor Path Titles (using note content as title)
            $package['ancestor_path_titles'] = [];
            $current_parent_id_for_ancestors = $note_data['parent_id'];
            
            // Prepare statement for ancestor lookup once per note_data processing
            $stmt_ancestor = $db->prepare('SELECT id, content, parent_id FROM notes WHERE id = :ancestor_id');
            if (!$stmt_ancestor) {
                // Log or throw, for now, let's assume it prepares, or rely on general error handling
                // For a production system, more robust error handling here would be good.
                error_log("Failed to prepare ancestor lookup statement for note {$note_data['id']}: " . $db->lastErrorMsg());
            } else {
                while ($current_parent_id_for_ancestors) {
                    $stmt_ancestor->reset(); // Reset bindings and results from previous iteration
                    $stmt_ancestor->bindValue(':ancestor_id', $current_parent_id_for_ancestors, SQLITE3_INTEGER);
                    $ancestor_result = $stmt_ancestor->execute();

                    if ($ancestor_result && ($ancestor_note = $ancestor_result->fetchArray(SQLITE3_ASSOC))) {
                        array_unshift($package['ancestor_path_titles'], $ancestor_note['content']); // Add content to the beginning
                        $current_parent_id_for_ancestors = $ancestor_note['parent_id']; // Move to the next ancestor
                    } else {
                        // Ancestor not found or query failed, log and break
                        error_log("Ancestor note with ID {$current_parent_id_for_ancestors} not found for note {$note_data['id']} or query failed.");
                        break; 
                    }
                }
                $stmt_ancestor->close(); // Close the statement after the while loop
            }

            // Fetch Full Page Outline Context
            $package['full_page_outline_context'] = null; // Default to null
            $stmt_outline = $db->prepare('SELECT content FROM notes WHERE page_id = :page_id AND parent_id IS NULL ORDER BY "order" ASC');
            if (!$stmt_outline) {
                error_log("Failed to prepare statement for full_page_outline_context for page_id: " . $package['page_id'] . " - " . $db->lastErrorMsg());
                // $package['full_page_outline_context'] remains null or you could set an error string.
            } else {
                $stmt_outline->bindValue(':page_id', $package['page_id'], SQLITE3_TEXT);
                $outline_result = $stmt_outline->execute();
                $outline_parts = [];
                if ($outline_result) {
                    while ($outline_note = $outline_result->fetchArray(SQLITE3_ASSOC)) {
                        $outline_parts[] = $outline_note['content'];
                    }
                } else {
                    error_log("Failed to execute statement for full_page_outline_context for page_id: " . $package['page_id'] . " - " . $db->lastErrorMsg());
                }
                // Only set if parts were found, otherwise it correctly remains null (or empty string if preferred)
                if (!empty($outline_parts)) {
                    $package['full_page_outline_context'] = implode("\n\n", $outline_parts); 
                } else {
                    // If no outline parts found (e.g., page has no top-level notes), set to empty string as per typical expectation for "context" fields
                    $package['full_page_outline_context'] = ""; 
                }
                $stmt_outline->close();
            }
            
            $note_packages[] = $package;
        }
        
        // Output the augmented note packages as JSON
        echo json_encode($note_packages);

    } else {
        throw new Exception('Method not allowed: ' . $method);
    }

} catch (Throwable $e) { // Catch Throwable to include Errors
    if (ob_get_length()) {
        ob_clean(); // Clean the output buffer
    }
    
    $errorMessage = $e->getMessage();
    $errorCode = $e->getCode();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();

    error_log(sprintf(
        "api/get_changed_notes.php: Throwable caught: Message: %s | Code: %s | File: %s | Line: %s",
        $errorMessage,
        $errorCode,
        $errorFile,
        $errorLine
    ));

    $errorResponseArray = ['error' => $errorMessage];
    
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500); // Internal Server Error
    }
    
    echo json_encode($errorResponseArray);
} finally {
    if (isset($db)) {
        $db->close();
    }
    if (ob_get_level() > 0) { // Check if buffering is active
        ob_end_flush(); // Send output buffer
    }
}
?>
