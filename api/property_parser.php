<?php
require_once __DIR__ . '/../config.php'; // Required for PROPERTY_WEIGHTS
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/response_utils.php';
require_once __DIR__ . '/property_trigger_service.php';

class PropertyParser {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Parses property strings from a block of content.
     * Understands syntax like {key::value}, key:::value, etc.
     *
     * @param string|null $content The content to parse.
     * @return array An array of parsed properties, each with 'name', 'value', and 'weight'.
     */
    public function parsePropertiesFromContent($content) {
        if (empty($content)) {
            return [];
        }

        $parsedProperties = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Regex to capture key, separator (2+ colons), and value, with optional braces.
            if (preg_match('/^(\{)?([^\s:]+)(:{2,})(.+?)(?(1)\})$/', $line, $matches)) {
                $key = trim($matches[2]);
                $separator = $matches[3];
                $value = trim($matches[4]);

                if (empty($key)) continue;

                $weight = strlen($separator);

                $parsedProperties[] = [
                    'name' => $key,
                    'value' => $value,
                    'weight' => $weight
                ];
            }
        }
        
        return $parsedProperties;
    }

    /**
     * The "Smart Indexer". Synchronizes the Properties table for a note
     * with the properties parsed from its content.
     *
     * @param int $noteId The ID of the note to sync.
     * @param string $content The new content of the note.
     * @return bool True on success, false on failure.
     */
    public function syncNotePropertiesFromContent($noteId, $content) {
        // This logic can be extended for pages as well by parameterizing the entity type and ID.
        $entityType = 'note';
        $idColumn = 'note_id';
        $otherIdColumn = 'page_id';

        try {
            $this->pdo->beginTransaction();
            
            $parsedProps = $this->parsePropertiesFromContent($content);
            
            $stmt = $this->pdo->prepare("SELECT id, name, value, weight FROM Properties WHERE {$idColumn} = ?");
            $stmt->execute([$noteId]);
            $existingProps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $parsedPropsMap = [];
            foreach ($parsedProps as $p) {
                $parsedPropsMap[$p['name']][] = $p;
            }

            $existingPropsMap = [];
            foreach ($existingProps as $e) {
                $existingPropsMap[$e['name']][] = $e;
            }

            // --- Deletion Logic ---
            // Find properties with 'replace' behavior that are in the DB but not in the new content.
            $propsToDelete = [];
            foreach ($existingProps as $eProp) {
                $behavior = PROPERTY_WEIGHTS[$eProp['weight']]['update_behavior'] ?? 'replace';
                if ($behavior === 'replace' && !isset($parsedPropsMap[$eProp['name']])) {
                    $propsToDelete[] = $eProp['id'];
                }
            }
            if (!empty($propsToDelete)) {
                $deletePlaceholders = rtrim(str_repeat('?,', count($propsToDelete)), ',');
                $deleteStmt = $this->pdo->prepare("DELETE FROM Properties WHERE id IN ({$deletePlaceholders})");
                $deleteStmt->execute($propsToDelete);
            }

            // --- Insert/Update Logic ---
            $triggerService = new PropertyTriggerService($this->pdo);

            foreach ($parsedProps as $pProp) {
                $name = $pProp['name'];
                $value = $pProp['value'];
                $weight = $pProp['weight'];
                $behavior = PROPERTY_WEIGHTS[$weight]['update_behavior'] ?? 'replace';

                if ($behavior === 'append') {
                    // For append, always insert if it's a new value for this property name.
                    // This prevents re-inserting the same log entry on every save.
                    $found = false;
                    if (isset($existingPropsMap[$name])) {
                        foreach ($existingPropsMap[$name] as $eProp) {
                            if ($eProp['value'] === $value && (int)$eProp['weight'] === $weight) {
                                $found = true;
                                break;
                            }
                        }
                    }
                    if (!$found) {
                        $stmt = $this->pdo->prepare("INSERT INTO Properties ({$idColumn}, {$otherIdColumn}, name, value, weight) VALUES (?, NULL, ?, ?, ?)");
                        $stmt->execute([$noteId, $name, $value, $weight]);
                        $triggerService->dispatch($entityType, $noteId, $name, $value);
                    }
                } elseif ($behavior === 'replace') {
                    // For replace, update if exists, otherwise insert.
                    $updateStmt = $this->pdo->prepare("UPDATE Properties SET value = ?, weight = ? WHERE {$idColumn} = ? AND name = ?");
                    $updateStmt->execute([$value, $weight, $noteId, $name]);

                    if ($updateStmt->rowCount() === 0) {
                        $insertStmt = $this->pdo->prepare("INSERT INTO Properties ({$idColumn}, {$otherIdColumn}, name, value, weight) VALUES (?, NULL, ?, ?, ?)");
                        $insertStmt->execute([$noteId, $name, $value, $weight]);
                    }
                    $triggerService->dispatch($entityType, $noteId, $name, $value);
                }
            }
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error syncing note properties for note ID {$noteId}: " . $e->getMessage());
            return false;
        }
    }
}