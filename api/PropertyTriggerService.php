<?php

namespace App;

use PDO;


class PropertyTriggerService {
    private $pdo;

    /** Webhook deliveries waiting for the end of the request: [webhook, eventType, payload]. */
    private static array $pendingWebhooks = [];
    private static bool $flushRegistered = false;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Webhooks are HTTP calls to other servers, so they are never sent while a
     * write transaction is open (a slow receiver would block every other
     * save). They are queued and sent when the request ends.
     */
    private static function queueWebhook(array $webhook, string $eventType, array $payload): void {
        self::$pendingWebhooks[] = [$webhook, $eventType, $payload];
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function([self::class, 'flushWebhooks']);
        }
    }

    /** Call after rolling back a transaction: its changes did not happen. */
    public static function discardPendingWebhooks(): void {
        self::$pendingWebhooks = [];
    }

    public static function flushWebhooks(): void {
        $pending = self::$pendingWebhooks;
        self::$pendingWebhooks = [];
        if (!$pending) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // the client already has its answer
        }
        try {
            $manager = new WebhooksManager(get_db_connection());
            foreach ($pending as [$webhook, $eventType, $payload]) {
                $manager->dispatchEvent($webhook, $eventType, $payload);
            }
        } catch (\Throwable $e) {
            error_log('Webhook delivery failed: ' . $e->getMessage());
        }
    }

    /**
     * Dispatch triggers for a property change
     * @param string $entityType Entity type ('note' or 'page')
     * @param int $entityId Entity ID
     * @param string $propertyName Property name
     * @param mixed $propertyValue Property value
     */
    public function dispatch($entityType, $entityId, $propertyName, $propertyValue) {
        // Handle dynamic webhook triggers
        $this->handleWebhookTrigger($entityType, $entityId, $propertyName, $propertyValue);
    }
    
    /**
     * Finds and dispatches webhooks for a given property change.
     */
    private function handleWebhookTrigger($entityType, $entityId, $propertyName, $propertyValue) {
        try {
            // Get all active and verified webhooks for this entity type
            $stmt = $this->pdo->prepare(
                "SELECT * FROM Webhooks WHERE entity_type = ? AND active = 1 AND verified = 1"
            );
            $stmt->execute([$entityType]);
            $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($webhooks)) {
                return; // No webhooks to trigger
            }

            foreach ($webhooks as $webhook) {
                // Decode JSON fields
                $propertyNames = json_decode($webhook['property_names'], true);
                $eventTypes = json_decode($webhook['event_types'], true);

                // Skip if property_change is not in event_types
                if (!in_array('property_change', $eventTypes)) {
                    continue;
                }

                // Check if this webhook should trigger for this property
                $shouldTrigger = false;
                if ($propertyNames === '*') {
                    $shouldTrigger = true;
                } else if (is_array($propertyNames)) {
                    $shouldTrigger = in_array($propertyName, $propertyNames);
                } else {
                    $shouldTrigger = ($propertyNames === $propertyName);
                }

                if (!$shouldTrigger) {
                    continue;
                }

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

                self::queueWebhook($webhook, 'property_change', $payload);
            }
        } catch (\Exception $e) {
            error_log("Error during webhook dispatch: " . $e->getMessage());
        }
    }

    /**
     * Dispatch entity lifecycle events (create, update, delete)
     */
    public function dispatchEntityEvent($eventType, $entityType, $entityId, $data = []) {
        try {
            // Get all active and verified webhooks for this entity type
            $stmt = $this->pdo->prepare(
                "SELECT * FROM Webhooks WHERE entity_type = ? AND active = 1 AND verified = 1"
            );
            $stmt->execute([$entityType]);
            $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($webhooks)) {
                return; // No webhooks to trigger
            }

            foreach ($webhooks as $webhook) {
                $eventTypes = json_decode($webhook['event_types'], true);
                
                // Skip if this event type is not in the webhook's event_types
                if (!in_array($eventType, $eventTypes)) {
                    continue;
                }

                $payload = [
                    'event' => $eventType,
                    'webhook_id' => $webhook['id'],
                    'timestamp' => time(),
                    'data' => array_merge([
                        'entity_type' => $entityType,
                        'entity_id' => $entityId
                    ], $data)
                ];

                self::queueWebhook($webhook, $eventType, $payload);
            }
        } catch (\Exception $e) {
            error_log("Error during entity event webhook dispatch: " . $e->getMessage());
        }
    }
}
