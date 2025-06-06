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

    public function dispatch($entityType, $entityId, $propertyName, $propertyValue) {
        error_log("PropertyTriggerService dispatch: entityType={$entityType}, entityId={$entityId}, propertyName={$propertyName}, value={$propertyValue}");

        // Define triggers internally. This could be made more dynamic if needed.
        $triggers = [
            'note' => [
                'internal' => [$this, 'handleInternalPropertyForNote'],
                // Add other note-specific property triggers here
                // 'status' => [$this, 'handleStatusPropertyForNote'], 
            ],
            'page' => [
                'alias' => [$this, 'handleAliasPropertyForPage'],
                // Add other page-specific property triggers here
            ]
        ];

        if (isset($triggers[$entityType]) && isset($triggers[$entityType][$propertyName])) {
            $handler = $triggers[$entityType][$propertyName];
            if (is_callable($handler)) {
                try {
                    call_user_func($handler, $entityId, $propertyName, $propertyValue);
                    error_log("Executed trigger for {$entityType} {$entityId}, property {$propertyName}");
                } catch (Exception $e) {
                    error_log("Error executing trigger for {$entityType} {$entityId}, property {$propertyName}: " . $e->getMessage());
                    // Optionally re-throw or handle more gracefully
                }
            } else {
                error_log("Handler not callable for {$entityType} {$entityId}, property {$propertyName}");
            }
        } else {
            error_log("No trigger found for {$entityType}, property {$propertyName}");
        }
    }
}
?>
