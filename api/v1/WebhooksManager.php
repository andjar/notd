<?php

namespace App;

use PDO;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../validator_utils.php';

class WebhooksManager {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Main request router.
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? null;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        try {
            switch ($method) {
                case 'GET':
                    if ($action === 'history' && $id) {
                        $this->getWebhookHistory($id);
                    } elseif ($id) {
                        $this->getWebhook($id);
                    } else {
                        $this->listWebhooks();
                    }
                    break;
                case 'POST':
                    $input = json_decode(file_get_contents('php://input'), true);
                    if ($action === 'verify' && $id) {
                        $this->verifyWebhookEndpoint($id);
                    } elseif ($action === 'test' && $id) {
                        $this->sendTestEvent($id);
                    } else {
                        $this->createWebhook($input);
                    }
                    break;
                case 'PUT':
                    if ($id) {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $this->updateWebhook($id, $input);
                    } else {
                        \App\ApiResponse::error('Webhook ID is required for updating.', 400);
                    }
                    break;
                case 'DELETE':
                    if ($id) {
                        $this->deleteWebhook($id);
                    } else {
                        \App\ApiResponse::error('Webhook ID is required for deleting.', 400);
                    }
                    break;
                default:
                    \App\ApiResponse::error('Method Not Allowed', 405);
                    break;
            }
        } catch (Exception $e) {
            \App\ApiResponse::error('An unexpected error occurred: ' . $e->getMessage(), 500);
        }
    }

    private function listWebhooks() {
        $stmt = $this->pdo->query("SELECT id, url, entity_type, property_names, event_types, active, verified, last_verified, last_triggered FROM Webhooks ORDER BY created_at DESC");
        $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON fields for better readability
        foreach ($webhooks as &$webhook) {
            $webhook['property_names'] = json_decode($webhook['property_names'], true);
            $webhook['event_types'] = json_decode($webhook['event_types'], true);
        }
        
        \App\ApiResponse::success($webhooks);
    }

    private function getWebhook($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM Webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($webhook) {
            // Decode JSON fields for better readability
            $webhook['property_names'] = json_decode($webhook['property_names'], true);
            $webhook['event_types'] = json_decode($webhook['event_types'], true);
            \App\ApiResponse::success($webhook);
        } else {
            \App\ApiResponse::error('Webhook not found.', 404);
        }
    }

    private function createWebhook($data) {
        $validationRules = [
            'url' => 'required',
            'entity_type' => 'required|isValidEntityType',
            'property_names' => 'required|isValidPropertyNames',
            'event_types' => 'optional|isValidEventTypes'
        ];
        $errors = Validator::validate($data, $validationRules);
        if (!empty($errors)) {
            \App\ApiResponse::error('Invalid input.', 400, $errors);
            return;
        }

        if (filter_var($data['url'], FILTER_VALIDATE_URL) === false) {
             \App\ApiResponse::error('Invalid URL format.', 400, ['url' => 'Must be a valid URL.']);
             return;
        }

        // Convert property_names to JSON if it's an array
        $propertyNames = is_array($data['property_names']) ? json_encode($data['property_names']) : $data['property_names'];
        
        // Set default event types if not provided
        $eventTypes = isset($data['event_types']) ? 
            (is_array($data['event_types']) ? json_encode($data['event_types']) : $data['event_types']) : 
            '["property_change"]';

        $secret = 'whsec_' . bin2hex(random_bytes(32)); // Generate a secure secret
        $active = isset($data['active']) ? (int)(bool)$data['active'] : 1;

        $sql = "INSERT INTO Webhooks (url, entity_type, property_names, event_types, secret, active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$data['url'], $data['entity_type'], $propertyNames, $eventTypes, $secret, $active]);
        
        $newId = $this->pdo->lastInsertId();
        $this->getWebhook($newId);
    }

    private function updateWebhook($id, $data) {
        $stmt = $this->pdo->prepare("SELECT id FROM Webhooks WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            \App\ApiResponse::error('Webhook not found.', 404);
            return;
        }
        
        $fields = [];
        $params = [];

        if (isset($data['url'])) {
            if (filter_var($data['url'], FILTER_VALIDATE_URL) === false) {
                 \App\ApiResponse::error('Invalid URL format.', 400, ['url' => 'Must be a valid URL.']);
                 return;
            }
            $fields[] = 'url = ?';
            $params[] = $data['url'];
        }
        if (isset($data['entity_type'])) {
            $fields[] = 'entity_type = ?';
            $params[] = $data['entity_type'];
        }
        if (isset($data['property_names'])) {
            $propertyNames = is_array($data['property_names']) ? json_encode($data['property_names']) : $data['property_names'];
            $fields[] = 'property_names = ?';
            $params[] = $propertyNames;
        }
        if (isset($data['event_types'])) {
            $eventTypes = is_array($data['event_types']) ? json_encode($data['event_types']) : $data['event_types'];
            $fields[] = 'event_types = ?';
            $params[] = $eventTypes;
        }
        if (isset($data['active'])) {
            $fields[] = 'active = ?';
            $params[] = (int)(bool)$data['active'];
        }
        
        if (empty($fields)) {
            \App\ApiResponse::error('No valid fields provided for update.', 400);
            return;
        }

        $fields[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = "UPDATE Webhooks SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->getWebhook($id);
    }

    private function deleteWebhook($id) {
        $stmt = $this->pdo->prepare("DELETE FROM Webhooks WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            // Also delete associated events
            $stmt_events = $this->pdo->prepare("DELETE FROM WebhookEvents WHERE webhook_id = ?");
            $stmt_events->execute([$id]);
            \App\ApiResponse::success(['message' => "Webhook {$id} and its events deleted successfully."]);
        } else {
            \App\ApiResponse::error('Webhook not found.', 404);
        }
    }
    
    public function sendTestEvent($id) {
        $webhook = $this->findWebhookOrFail($id);
        if (!$webhook) return;

        $payload = [
            'event' => 'test',
            'webhook_id' => $id,
            'timestamp' => time(),
            'data' => [
                'message' => 'This is a test event from Notd.'
            ]
        ];
        
        $this->dispatchEvent($webhook, 'test', $payload);
        \App\ApiResponse::success(['message' => 'Test event sent. Check history for result.']);
    }

    private function verifyWebhookEndpoint($id) {
        $webhook = $this->findWebhookOrFail($id);
        if (!$webhook) return;

        $payload = [
            'event' => 'verification',
            'webhook_id' => $id,
            'timestamp' => time()
        ];
        
        list($success, $httpCode, $responseBody) = $this->dispatchEvent($webhook, 'verification', $payload, true);

        $this->pdo->beginTransaction();
        $stmt_verify = $this->pdo->prepare("UPDATE Webhooks SET verified = ?, last_verified = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt_verify->execute([$success ? 1 : 0, $id]);
        $this->pdo->commit();

        if ($success) {
            \App\ApiResponse::success(['message' => "Webhook verified successfully with status {$httpCode}."]);
        } else {
            \App\ApiResponse::error("Webhook verification failed with status {$httpCode}.", 502, ['response' => $responseBody]);
        }
    }

    /**
     * Finds a webhook by ID or fails with a JSON error response.
     */
    private function findWebhookOrFail($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM Webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$webhook) {
            \App\ApiResponse::error('Webhook not found.', 404);
            return null;
        }
        return $webhook;
    }

    /**
     * Core function to send a webhook event via cURL and log the result.
     * @return array [success (bool), http_code (int), response_body (string)]
     */
    public function dispatchEvent(array $webhook, string $eventType, array $payload, bool $isVerification = false): array {
        if (defined('WEBHOOKS_ENABLED') && WEBHOOKS_ENABLED === false) {
            // Log that webhooks are disabled (optional, but good for debugging)
            error_log("Webhook dispatch aborted: WEBHOOKS_ENABLED is false. Webhook ID: " . $webhook['id'] . ", Event: " . $eventType);
            
            // Return a specific response indicating the service is unavailable or feature is disabled
            // Using 0 for httpCode as cURL error would, and a clear message.
            return [false, 0, 'Webhook dispatch failed: Webhooks are disabled by configuration.'];
        }
        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, $webhook['secret']);

        $ch = curl_init($webhook['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: Notd-Webhook/1.0',
            'X-Notd-Signature: ' . $signature,
            'X-Notd-Event: ' . $eventType
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $responseBody = "cURL Error: " . $error;
            $httpCode = 0; // Indicate a connection error
        }

        $success = $httpCode >= 200 && $httpCode < 300;

        // Log the event
        $logStmt = $this->pdo->prepare(
            "INSERT INTO WebhookEvents (webhook_id, event_type, payload, response_code, response_body, success, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $logStmt->execute([
            $webhook['id'],
            $eventType,
            $jsonPayload,
            $httpCode,
            $responseBody,
            (int)$success
        ]);
        
        // Update last_triggered timestamp, unless it's a verification that failed
        // (we only want successful verifications to count as a "trigger")
        if (!$isVerification || $success) {
            $updateStmt = $this->pdo->prepare("UPDATE Webhooks SET last_triggered = CURRENT_TIMESTAMP WHERE id = ?");
            $updateStmt->execute([$webhook['id']]);
        }
        
        return [$success, $httpCode, $responseBody];
    }

    private function getWebhookHistory($id) {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = ($page - 1) * $limit;

        $stmt = $this->pdo->prepare("SELECT * FROM WebhookEvents WHERE webhook_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$id, $limit, $offset]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalStmt = $this->pdo->prepare("SELECT COUNT(*) FROM WebhookEvents WHERE webhook_id = ?");
        $totalStmt->execute([$id]);
        $total = $totalStmt->fetchColumn();

        \App\ApiResponse::success([
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ],
            'history' => $history
        ]);
    }
}

// Initialize and handle the request
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    try {
        $pdo = get_db_connection();
        $manager = new \App\WebhooksManager($pdo);
        $manager->handleRequest();
    } catch (Exception $e) {
        \App\ApiResponse::error('Database connection failed or other critical error.', 500, ['details' => $e->getMessage()]);
    }
}