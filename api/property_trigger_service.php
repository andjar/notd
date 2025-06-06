<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/response_utils.php';
require_once __DIR__ . '/webhooks.php'; // Include the new WebhooksManager

class PropertyTriggerService {
    private $pdo;
    private $webhooksManager; // Add WebhooksManager instance

    // Trigger handler functions will be defined as private methods here
    // e.g., private function handleInternalPropertyForNote(...) { ... }
    // e.g., private function handleAliasProperty(...) { ... }

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->webhooksManager = new WebhooksManager($pdo); // Instantiate WebhooksManager
    }

    private function handleInternalPropertyForNote($entityId, $propertyName, $propertyValue) {
        if ($propertyName === 'internal') {
            $stmt = $this->pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?");
            $stmt->execute([(int)$propertyValue, $entityId]);
        }
    }

    private function handleAliasPropertyForPage($entityId, $propertyName, $propertyValue) {
        if ($propertyName === 'alias') {
            // Check if the alias points to a valid page
            $stmt = $this->pdo->prepare("SELECT id FROM Pages WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$propertyValue]);
            $targetPage = $stmt->fetch();

            if (!$targetPage) {
                // If the target page doesn't exist, remove the alias
                $stmt = $this->pdo->prepare("UPDATE Properties SET value = NULL WHERE page_id = ? AND name = 'alias'");
                $stmt->execute([$entityId]);
                error_log("Alias target page '{$propertyValue}' not found. Alias removed.");
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
        // Handle hardcoded triggers
        $triggers = $this->getTriggersForEntityType($entityType);
        if (isset($triggers[$propertyName])) {
            $handler = $triggers[$propertyName];
            $this->$handler($entityId, $propertyName, $propertyValue);
        }

        // Handle dynamic webhook triggers
        $this->handleWebhookTrigger($entityType, $entityId, $propertyName, $propertyValue);
    }
    
    /**
     * Finds and dispatches webhooks for a given property change.
     */
    private function handleWebhookTrigger($entityType, $entityId, $propertyName, $propertyValue) {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM Webhooks WHERE entity_type = ? AND property_name = ? AND active = 1 AND verified = 1"
            );
            $stmt->execute([$entityType, $propertyName]);
            $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($webhooks)) {
                return; // No webhooks to trigger
            }

            // In a real-world scenario with many webhooks, this should be offloaded to a queue.
            // For this application, direct dispatch is acceptable.
            foreach ($webhooks as $webhook) {
                $payload = [
                    'event' => 'property_change',
                    'webhook_id' => $webhook['id'],
                    'timestamp' => time(),
                    'data' => [
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'property_name' => $propertyName,
                        'value' => $propertyValue
                    ]
                ];
                // Use the new WebhooksManager to dispatch the event
                $this->webhooksManager->dispatchEvent($webhook, 'property_change', $payload);
            }
        } catch (Exception $e) {
            error_log("Error during webhook dispatch: " . $e->getMessage());
        }
    }

    /**
     * Get available triggers for an entity type
     * @param string $entityType Entity type ('note' or 'page')
     * @return array Map of property names to handler functions
     */
    private function getTriggersForEntityType($entityType) {
        $triggers = [
            'note' => [
                'internal' => 'handleInternalPropertyForNote'
            ],
            'page' => [
                'alias' => 'handleAliasPropertyForPage'
            ]
        ];

        return $triggers[$entityType] ?? [];
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$propertyTriggerService = new PropertyTriggerService($pdo);
// No direct output from this service file. It's a library.
?>
