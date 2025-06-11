<?php
require_once 'db_connect.php';
require_once 'response_utils.php';

class PropertyParser {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function parsePropertiesFromContent($content) {
        $parsedProperties = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // New regex: /^\{?([^:]+):(:{1,2})?(.+?)\}?$/
            // This regex aims to capture:
            // 1. Optional braces: \{? and \}?
            // 2. Key: ([^:]+)
            // 3. Separator: :(:{1,2})?  (first colon is mandatory, then optionally one or two more)
            //    - Group 2 will capture the optional colons (:, ::)
            // 4. Value: (.+?)
            if (preg_match('/^\{?([^:]+):(:{1,2})?(.+?)\}?$/', $line, $matches)) {
                $key = trim($matches[1]);
                $separator_extra_colons = isset($matches[2]) ? $matches[2] : ''; // :, ::, or empty
                $value = trim($matches[3]);

                // Skip empty keys
                if (empty($key)) continue;

                $is_internal = false;
                // Legacy internal properties might need a different check if they exist.
                // For now, only ::: makes a property internal.
                // Or if it's a legacy internal property (e.g. starts with _)
                if (strpos($key, '_') === 0) { // Example for legacy internal
                    $is_internal = true;
                }

                if ($separator_extra_colons === '::') { // {key:::value}
                    $is_internal = true;
                } else if ($separator_extra_colons === ':') { // {key::value}
                    // This is new normal, is_internal remains false unless key starts with _
                } else { // {key:value} or key:value (legacy)
                    // This is legacy normal, is_internal remains false unless key starts with _
                }
                
                $parsedProperties[] = [
                    'name' => $key,
                    'value' => $value,
                    'is_internal' => $is_internal
                ];
            }
        }
        
        return $parsedProperties;
    }

    public function syncNotePropertiesFromContent($noteId, $content) {
        try {
            $this->pdo->beginTransaction();
            
            // Get existing properties
            $stmt = $this->pdo->prepare("SELECT name, value, internal FROM Properties WHERE note_id = ?");
            $stmt->execute([$noteId]);
            $existingProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert to associative array for easier lookup
            $existingPropsMap = [];
            foreach ($existingProperties as $prop) {
                $existingPropsMap[$prop['name']] = $prop; // Store the whole prop object
            }
            
            // Parse properties from content using the updated function
            $parsedContentProperties = $this->parsePropertiesFromContent($content);
            
            $propertiesToAdd = [];
            $propertiesToUpdate = [];
            
            foreach ($parsedContentProperties as $prop) {
                $name = $prop['name'];
                $value = $prop['value'];
                $is_internal = $prop['is_internal'];
                $internal_db_val = $is_internal ? 1 : 0;

                if (!isset($existingPropsMap[$name])) {
                    $propertiesToAdd[] = [
                        'name' => $name, 
                        'value' => $value, 
                        'internal' => $internal_db_val
                    ];
                } elseif (
                    $existingPropsMap[$name]['value'] !== $value ||
                    (int)$existingPropsMap[$name]['internal'] !== $internal_db_val 
                ) {
                    $propertiesToUpdate[] = [
                        'name' => $name, 
                        'value' => $value, 
                        'internal' => $internal_db_val
                    ];
                }
            }
            
            // Add new properties
            if (!empty($propertiesToAdd)) {
                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO Properties (note_id, name, value, internal) VALUES (?, ?, ?, ?)"
                );
                foreach ($propertiesToAdd as $prop) {
                    $insertStmt->execute([$noteId, $prop['name'], $prop['value'], $prop['internal']]);
                }
            }
            
            // Update existing properties
            if (!empty($propertiesToUpdate)) {
                $updateStmt = $this->pdo->prepare(
                    "UPDATE Properties SET value = ?, internal = ? WHERE note_id = ? AND name = ?"
                );
                foreach ($propertiesToUpdate as $prop) {
                    $updateStmt->execute([$prop['value'], $prop['internal'], $noteId, $prop['name']]);
                }
            }
            
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error syncing note properties: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$propertyParser = new PropertyParser($pdo); 