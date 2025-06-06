<?php

class PropertyTriggerService {
    private $pdo;

    // Trigger handler functions will be defined as private methods here
    // e.g., private function handleInternalPropertyForNote(...) { ... }
    // e.g., private function handleAliasProperty(...) { ... }

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function handleInternalPropertyForNote($entityId, $propertyName, $propertyValue) {
        if ($propertyName === 'internal' && $entityId) {
            $internalValue = filter_var($propertyValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($internalValue !== null) {
                try {
                    $stmt = $this->pdo->prepare("UPDATE Notes SET internal = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$internalValue ? 1 : 0, $entityId]);
                    error_log("Updated internal status for note {$entityId} to " . ($internalValue ? 1 : 0));
                } catch (PDOException $e) {
                    error_log("Error updating internal status for note {$entityId}: " . $e->getMessage());
                }
            }
        }
    }

    private function handleAliasPropertyForPage($entityId, $propertyName, $propertyValue) {
        if ($propertyName === 'alias' && $entityId) {
            // Ensure alias is not self-referential to the page's own name
            try {
                $stmtCheckName = $this->pdo->prepare("SELECT name FROM Pages WHERE id = ?");
                $stmtCheckName->execute([$entityId]);
                $page = $stmtCheckName->fetch(PDO::FETCH_ASSOC);

                if ($page && strtolower($page['name']) === strtolower($propertyValue)) {
                    error_log("Cannot set alias for page {$entityId} to its own name '{$propertyValue}'. Clearing alias.");
                    // Set alias to NULL or empty string to prevent self-reference
                    $stmt = $this->pdo->prepare("UPDATE Pages SET alias = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$entityId]);
                } else {
                    // Standard alias update (or it's already handled by the main property save if 'alias' is a direct column)
                    // This trigger might be more about validation or side effects than direct update if 'alias' is a main column.
                    // If 'alias' is a property in Properties table, then this trigger would update Pages.alias
                    // For now, assuming 'alias' is a main column on Pages table, this trigger is for validation.
                    // If it were a property to sync, it would look like:
                    // $stmt = $this->pdo->prepare("UPDATE Pages SET alias = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    // $stmt->execute([$propertyValue, $entityId]);
                    error_log("Alias property '{$propertyValue}' for page {$entityId} processed (validation executed).");
                }
            } catch (PDOException $e) {
                error_log("Error processing alias for page {$entityId}: " . $e->getMessage());
            }
        }
    }
    
    // Placeholder for other trigger handlers if any, e.g., status changes, etc.

    /**
     * Dispatch triggers for a property change
     * @param string $entityType Entity type ('note' or 'page')
     * @param int $entityId Entity ID
     * @param string $propertyName Property name
     * @param mixed $propertyValue Property value
     */
    public function dispatch($entityType, $entityId, $propertyName, $propertyValue) {
        error_log("[PROPERTY_TRIGGER_DEBUG] Dispatching trigger for {$entityType} {$entityId}, property: {$propertyName}");
        error_log("[PROPERTY_TRIGGER_DEBUG] Property value: " . json_encode($propertyValue));
        
        // Get available triggers for this entity type
        $triggers = $this->getTriggersForEntityType($entityType);
        error_log("[PROPERTY_TRIGGER_DEBUG] Available triggers: " . json_encode(array_keys($triggers)));
        
        // Check if we have a handler for this property
        if (isset($triggers[$propertyName])) {
            $handler = $triggers[$propertyName];
            error_log("[PROPERTY_TRIGGER_DEBUG] Found handler for {$propertyName}");
            
            try {
                if (is_callable($handler)) {
                    error_log("[PROPERTY_TRIGGER_DEBUG] Executing handler for {$propertyName}");
                    $handler($entityId, $propertyName, $propertyValue);
                    error_log("[PROPERTY_TRIGGER_DEBUG] Handler executed successfully");
                } else {
                    error_log("[PROPERTY_TRIGGER_ERROR] Handler for {$propertyName} is not callable");
                }
            } catch (Exception $e) {
                error_log("[PROPERTY_TRIGGER_ERROR] Error executing handler for {$propertyName}: " . $e->getMessage());
                error_log("[PROPERTY_TRIGGER_ERROR] Stack trace: " . $e->getTraceAsString());
                throw $e; // Re-throw to be caught by caller
            }
        } else {
            error_log("[PROPERTY_TRIGGER_DEBUG] No trigger found for {$propertyName}");
        }
    }
    
    /**
     * Get available triggers for an entity type
     * @param string $entityType Entity type ('note' or 'page')
     * @return array Map of property names to handler functions
     */
    private function getTriggersForEntityType($entityType) {
        $triggers = [];
        
        if ($entityType === 'note') {
            $triggers = [
                'internal' => [$this, 'handleInternalPropertyForNote']
            ];
        } elseif ($entityType === 'page') {
            $triggers = [
                'alias' => [$this, 'handleAliasPropertyForPage']
            ];
        }
        
        return $triggers;
    }
}
?>
