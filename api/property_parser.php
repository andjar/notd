<?php
require_once __DIR__ . '/property_auto_internal.php';

/**
 * Parses content for properties in the format key::value.
 *
 * @param string $content The text content to parse.
 * @return array An associative array of properties found, with keys as property names and values as arrays of property values.
 */
function parsePropertiesFromContent($content) {
    $properties = [];
    // Regex to find 'key::value' pairs, ignoring code blocks.
    // It looks for a key (word chars, -, _) followed by :: and captures the rest of the line as the value.
    $pattern = '/(?<=\s|^)([a-zA-Z0-9_-]+)::([^\n\r]+)/';
    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $key = trim($match[1]);
        $value = trim($match[2]);
        
        // Don't add if key or value is empty
        if (!empty($key) && !empty($value)) {
            // Allow multiple values for the same key (e.g., tags)
            if (!isset($properties[$key])) {
                $properties[$key] = [];
            }
            $properties[$key][] = $value;
        }
    }
    
    return $properties;
}

/**
 * Syncs properties found in note content with the Properties table in the database.
 * This function is designed to be the single source of truth for note properties derived from its content.
 * It will add, update, and delete properties as necessary to match what is in the text.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $noteId The ID of the note to sync properties for.
 * @param string $content The new content of the note.
 * @return void
 */
function syncNotePropertiesFromContent($pdo, $noteId, $content) {
    $propertiesFromContent = parsePropertiesFromContent($content);
    
    // Flatten the array for easier processing.
    // from ['tags' => ['a', 'b']] to [['name' => 'tags', 'value' => 'a'], ['name' => 'tags', 'value' => 'b']]
    $newProperties = [];
    foreach ($propertiesFromContent as $name => $values) {
        foreach ($values as $value) {
            $newProperties[] = ['name' => $name, 'value' => $value];
        }
    }
    
    $pdo->beginTransaction();
    try {
        // 1. Delete all existing, non-internal properties for this note, EXCEPT for 'status' properties.
        // We only sync non-internal properties. Internal ones are managed separately.
        // 'status' properties are preserved to maintain task history.
        $stmtDelete = $pdo->prepare("DELETE FROM Properties WHERE note_id = ? AND internal = 0 AND name != 'status'");
        $stmtDelete->execute([$noteId]);
        
        // 2. Insert the new properties found in the content.
        $stmtInsert = $pdo->prepare(
            "INSERT INTO Properties (note_id, name, value, internal) VALUES (?, ?, ?, ?)"
        );
        
        foreach ($newProperties as $prop) {
            // Determine the internal status for the property before inserting.
            $internalStatus = determinePropertyInternalStatus($pdo, $prop['name']);
            $stmtInsert->execute([$noteId, $prop['name'], $prop['value'], $internalStatus]);
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Log error or re-throw
        error_log("Failed to sync properties for note $noteId: " . $e->getMessage());
        throw $e;
    }
} 