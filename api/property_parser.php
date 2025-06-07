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
            
            // Match property pattern: key: value
            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                
                // Skip empty keys
                if (empty($key)) continue;
                
                $properties[$key] = $value;
            }
        }
        
        return $properties;
    }

    public function syncNotePropertiesFromContent($noteId, $content) {
        try {
            $this->pdo->beginTransaction();
            
            // Get existing properties
            $stmt = $this->pdo->prepare("SELECT name, value FROM Properties WHERE note_id = ?");
            $stmt->execute([$noteId]);
            $existingProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert to associative array for easier lookup
            $existingProps = [];
            foreach ($existingProperties as $prop) {
                $existingProps[$prop['name']] = $prop['value'];
            }
            
            // Parse properties from content
            $contentProperties = $this->parsePropertiesFromContent($content);
            
            // Find properties to add or update
            $propertiesToAdd = [];
            $propertiesToUpdate = [];
            
            foreach ($contentProperties as $name => $value) {
                if (!isset($existingProps[$name])) {
                    $propertiesToAdd[] = ['name' => $name, 'value' => $value];
                } elseif ($existingProps[$name] !== $value) {
                    $propertiesToUpdate[] = ['name' => $name, 'value' => $value];
                }
            }
            
            // Add new properties
            if (!empty($propertiesToAdd)) {
                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO Properties (note_id, name, value) VALUES (?, ?, ?)"
                );
                foreach ($propertiesToAdd as $prop) {
                    $insertStmt->execute([$noteId, $prop['name'], $prop['value']]);
                }
            }
            
            // Update existing properties
            if (!empty($propertiesToUpdate)) {
                $updateStmt = $this->pdo->prepare(
                    "UPDATE Properties SET value = ? WHERE note_id = ? AND name = ?"
                );
                foreach ($propertiesToUpdate as $prop) {
                    $updateStmt->execute([$prop['value'], $noteId, $prop['name']]);
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