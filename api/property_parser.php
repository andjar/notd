<?php
require_once __DIR__ . '/../config.php'; // Ensure config is loaded for APPEND_ONLY_PROPERTIES
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

            // Updated regex to capture key, separator (2 or more colons), and value.
            // It enforces matching braces if present.
            if (preg_match('/^(\{)?([^:]+)(:{2,})(.+?)(?(1)\})$/', $line, $matches)) {
                $key = trim($matches[2]);
                $separator = $matches[3]; // e.g., '::', ':::', '::::'
                $value = trim($matches[4]);

                // Skip empty keys
                if (empty($key)) continue;

                $colonCount = strlen($separator);
                
                $parsedProperties[] = [
                    'name' => $key,
                    'value' => $value,
                    'colon_count' => $colonCount
                ];
            }
        }
        
        return $parsedProperties;
    }

    public function syncNotePropertiesFromContent($noteId, $content) {
        try {
            $this->pdo->beginTransaction();
            
            // Fetch all active properties for the note, including their colon_count
            $stmt = $this->pdo->prepare("SELECT id, name, value, colon_count FROM Properties WHERE note_id = ? AND active = 1");
            $stmt->execute([$noteId]);
            $existingDbProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $existingPropsMap = [];
            foreach ($existingDbProperties as $prop) {
                // Group by name, and then by value, to handle potential duplicate name-value pairs if any exist (though less common)
                // For this logic, primarily grouping by name is important for finding if *any* property with that name exists.
                // Storing the full prop allows access to its ID and colon_count for deletion logic.
                $existingPropsMap[$prop['name']][] = $prop; 
            }
            
            // Parse properties from content (will now include colon_count)
            $parsedContentProperties = $this->parsePropertiesFromContent($content);
            
            $propertiesToAdd = [];
            $propertiesToDeactivateIds = []; // Renamed for clarity, as we deactivate

            // Identify properties to add
            foreach ($parsedContentProperties as $parsedProp) {
                $foundInDb = false;
                if (isset($existingPropsMap[$parsedProp['name']])) {
                    foreach ($existingPropsMap[$parsedProp['name']] as $dbProp) {
                        // A property is considered "existing" if its name, value, AND colon_count match.
                        // This means changing colon count for an existing name/value is treated as add new + remove old.
                        if ($dbProp['value'] === $parsedProp['value'] && (int)$dbProp['colon_count'] === (int)$parsedProp['colon_count']) {
                            $foundInDb = true;
                            break; 
                        }
                    }
                }
                if (!$foundInDb) {
                    $propertiesToAdd[] = [
                        'name' => $parsedProp['name'],
                        'value' => $parsedProp['value'],
                        'colon_count' => $parsedProp['colon_count']
                    ];
                }
            }

            // Identify properties to deactivate based on update_behavior
            foreach ($existingDbProperties as $dbProp) {
                $foundInContent = false;
                foreach ($parsedContentProperties as $parsedProp) {
                    if ($dbProp['name'] === $parsedProp['name'] && 
                        $dbProp['value'] === $parsedProp['value'] &&
                        (int)$dbProp['colon_count'] === (int)$parsedProp['colon_count']) {
                        $foundInContent = true;
                        break;
                    }
                }

                if (!$foundInContent) {
                    $dbColonCount = (int)$dbProp['colon_count'];
                    // Default to behavior of 2 colons if specific count not defined, or if PROPERTY_BEHAVIORS_BY_COLON_COUNT is missing (defensive)
                    $defaultBehavior = defined('PROPERTY_BEHAVIORS_BY_COLON_COUNT') && isset(PROPERTY_BEHAVIORS_BY_COLON_COUNT[2]) 
                                       ? PROPERTY_BEHAVIORS_BY_COLON_COUNT[2] 
                                       : ['update_behavior' => 'replace']; // Fallback default

                    $behavior = defined('PROPERTY_BEHAVIORS_BY_COLON_COUNT') && isset(PROPERTY_BEHAVIORS_BY_COLON_COUNT[$dbColonCount])
                                ? PROPERTY_BEHAVIORS_BY_COLON_COUNT[$dbColonCount]
                                : $defaultBehavior;
                    
                    $updateBehavior = $behavior['update_behavior'] ?? 'replace'; // Default to 'replace' if not specified in behavior

                    if ($updateBehavior !== 'append') {
                        $propertiesToDeactivateIds[] = $dbProp['id'];
                    }
                }
            }
            
            // Add new properties
            if (!empty($propertiesToAdd)) {
                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO Properties (note_id, name, value, colon_count, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                );
                foreach ($propertiesToAdd as $prop) {
                    $insertStmt->execute([$noteId, $prop['name'], $prop['value'], $prop['colon_count']]);
                }
            }
            
            // Deactivate properties that are no longer in content and are not append-only based on their behavior
            if (!empty($propertiesToDeactivateIds)) {
                $deactivateStmt = $this->pdo->prepare("UPDATE Properties SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                foreach ($propertiesToDeactivateIds as $propId) {
                    $deactivateStmt->execute([$propId]);
                }
            }
            
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error syncing note properties: " . $e->getMessage() . " in file " . $e->getFile() . " on line " . $e->getLine());
            return false;
        }
    }
}

// Initialize and handle the request
// This part should be handled by the script that includes/uses PropertyParser,
// e.g., in an API endpoint context.
// $pdo = get_db_connection();
// $propertyParser = new PropertyParser($pdo);
// For the purpose of this class, direct instantiation and request handling is removed.
// It's expected that the calling code (e.g., api.php) will manage this.