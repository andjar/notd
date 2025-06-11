<?php
require_once 'db_connect.php';
require_once 'response_utils.php';

class PropertyParser {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function parsePropertiesFromContent($content) {
        $properties = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Match property pattern: key::value or key:::value
            // The third capturing group ($matches[2]) will be ':' for '::' and '::' for ':::'
            if (preg_match('/^([^:]+):(:|::)\s*(.+)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $separator = trim($matches[2]);
                $value = trim($matches[3]);

                // Skip empty keys
                if (empty($key)) continue;

                $is_internal = ($separator === '::'); // '::' (third group is '::') means internal, single ':' (third group is ':') is not

                $properties[$key] = ['value' => $value, 'is_internal' => $is_internal];
            }
        }

        return $properties;
    }

    public function syncNotePropertiesFromContent($noteId, $content) {
        try {
            $this->pdo->beginTransaction();

            // Get existing properties
            $stmt = $this->pdo->prepare("SELECT name, value, internal FROM Properties WHERE note_id = ?");
            $stmt->execute([$noteId]);
            $existingProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert to associative array for easier lookup
            $existingProps = [];
            foreach ($existingProperties as $prop) {
                $existingProps[$prop['name']] = ['value' => $prop['value'], 'internal' => (bool)$prop['internal']];
            }

            // Parse properties from content
            $contentProperties = $this->parsePropertiesFromContent($content);

            // Find properties to add or update
            $propertiesToAdd = [];
            $propertiesToUpdate = [];

            foreach ($contentProperties as $name => $propData) {
                $value = $propData['value'];
                $is_internal = $propData['is_internal'];

                if (!isset($existingProps[$name])) {
                    $propertiesToAdd[] = ['name' => $name, 'value' => $value, 'is_internal' => $is_internal];
                } elseif ($existingProps[$name]['value'] !== $value || $existingProps[$name]['internal'] !== $is_internal) {
                    $propertiesToUpdate[] = ['name' => $name, 'value' => $value, 'is_internal' => $is_internal];
                }
            }

            // Add new properties
            if (!empty($propertiesToAdd)) {
                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO Properties (note_id, name, value, internal) VALUES (?, ?, ?, ?)"
                );
                foreach ($propertiesToAdd as $prop) {
                    $insertStmt->execute([$noteId, $prop['name'], $prop['value'], (int)$prop['is_internal']]);
                }
            }

            // Update existing properties
            if (!empty($propertiesToUpdate)) {
                $updateStmt = $this->pdo->prepare(
                    "UPDATE Properties SET value = ?, internal = ? WHERE note_id = ? AND name = ?"
                );
                foreach ($propertiesToUpdate as $prop) {
                    $updateStmt->execute([$prop['value'], (int)$prop['is_internal'], $noteId, $prop['name']]);
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