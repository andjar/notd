<?php
require_once __DIR__ . '/../config.php'; // Ensure config is loaded for PROPERTY_BEHAVIORS_BY_COLON_COUNT
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/property_trigger_service.php'; // Triggers will be handled by a different service now

class PropertyParser {
    private $pdo;
    private $propertyTriggerService;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->propertyTriggerService = new PropertyTriggerService($pdo);
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

    private function updateNoteInternalFlag($noteId) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM Properties WHERE note_id = ? AND name = 'internal' AND value = 'true' AND active = 1 AND colon_count >= 3 LIMIT 1");
        $stmt->execute([$noteId]);
        $isInternal = $stmt->fetchColumn() !== false;

        $updateStmt = $this->pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?");
        $updateStmt->execute([(int)$isInternal, $noteId]);
    }

    public function syncNotePropertiesFromContent($noteId, $content) {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT id, name, value, colon_count FROM Properties WHERE note_id = ? AND active = 1");
            $stmt->execute([$noteId]);
            $existingDbProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $parsedContentProperties = $this->parsePropertiesFromContent($content);
            $propertiesToAdd = [];
            $propertiesToDeactivateIds = [];
            $propertiesToKeepIds = [];

            foreach ($parsedContentProperties as $parsedProp) {
                $foundMatch = false;
                foreach ($existingDbProperties as $dbProp) {
                    if ($dbProp['name'] === $parsedProp['name'] && $dbProp['value'] === $parsedProp['value'] && (int)$dbProp['colon_count'] === (int)$parsedProp['colon_count']) {
                        $propertiesToKeepIds[] = $dbProp['id'];
                        $foundMatch = true;
                        break;
                    }
                }
                if (!$foundMatch) {
                    $propertiesToAdd[] = $parsedProp;
                }
            }
            
            foreach ($existingDbProperties as $dbProp) {
                if (!in_array($dbProp['id'], $propertiesToKeepIds)) {
                    $behavior = PROPERTY_BEHAVIORS_BY_COLON_COUNT[$dbProp['colon_count']] ?? PROPERTY_BEHAVIORS_BY_COLON_COUNT[2];
                    if (($behavior['update_behavior'] ?? 'replace') !== 'append') {
                        $propertiesToDeactivateIds[] = $dbProp['id'];
                    }
                }
            }

            if (!empty($propertiesToAdd)) {
                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO Properties (note_id, name, value, colon_count, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                );
                foreach ($propertiesToAdd as $prop) {
                    $insertStmt->execute([$noteId, $prop['name'], $prop['value'], $prop['colon_count']]);
                    $this->propertyTriggerService->dispatch('note', $noteId, $prop['name'], $prop['value']);
                }
            }

            if (!empty($propertiesToDeactivateIds)) {
                $placeholders = rtrim(str_repeat('?,', count($propertiesToDeactivateIds)), ',');
                $deactivateStmt = $this->pdo->prepare("UPDATE Properties SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)");
                $deactivateStmt->execute($propertiesToDeactivateIds);
            }

            // After syncing, update the note's internal flag
            $this->updateNoteInternalFlag($noteId);

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error in syncNotePropertiesFromContent: " . $e->getMessage());
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