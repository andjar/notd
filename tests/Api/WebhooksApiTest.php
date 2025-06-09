<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;

class WebhooksApiTest extends TestCase {
    private static $client;
    private static $testNoteId;
    private static $dbPath;

    public static function setUpBeforeClass(): void {
        // Setup Guzzle client
        self::$client = new Client([
            'base_uri' => 'http://localhost:8000', // Assuming the app is served here for testing
            'http_errors' => false // Don't throw exceptions for 4xx/5xx responses
        ]);

        // Use in-memory database instead of file-based
        $_ENV['DB_PATH'] = ':memory:';
        require_once __DIR__ . '/../../api/db_connect.php';
        run_database_setup(get_db_connection());

        // Create a test note to work with
        $pdo = get_db_connection();
        $pageStmt = $pdo->prepare("INSERT INTO Pages (name) VALUES ('Test Page')");
        $pageStmt->execute();
        $pageId = $pdo->lastInsertId();

        $noteStmt = $pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (?, 'Test note for webhooks')");
        $noteStmt->execute([$pageId]);
        self::$testNoteId = $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void {
        // No need to clean up database file since we're using in-memory
        self::$client = null;
    }

    protected function tearDown(): void {
        // Clean up webhooks and events between tests
        $pdo = get_db_connection();
        $pdo->exec("DELETE FROM Webhooks");
        $pdo->exec("DELETE FROM WebhookEvents");
        $pdo->exec("DELETE FROM Properties WHERE note_id = " . self::$testNoteId);
    }
    
    public function testCreateWebhook() {
        $response = self::$client->post('/api/webhooks.php', [
            'json' => [
                'url' => 'https://example.com/webhook-listener',
                'entity_type' => 'note',
                'property_name' => 'status'
            ]
        ]);
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('https://example.com/webhook-listener', $data['data']['url']);
        $this->assertArrayHasKey('secret', $data['data']);
        
        return $data['data']['id'];
    }

    /**
     * @depends testCreateWebhook
     */
    public function testGetWebhook($id) {
        $response = self::$client->get("/api/webhooks.php?id={$id}");
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals($id, $data['data']['id']);
    }

    /**
     * @depends testCreateWebhook
     */
    public function testUpdateWebhook($id) {
        $response = self::$client->put("/api/webhooks.php?id={$id}", [
            'json' => [
                'url' => 'https://example.com/updated-listener',
                'active' => 0
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('https://example.com/updated-listener', $data['data']['url']);
        $this->assertEquals(0, $data['data']['active']);
    }
    
    /**
     * Tests the full end-to-end flow of a webhook being triggered.
     * @depends testCreateWebhook
     */
    public function testWebhookTriggerOnPropertyChange($id) {
        // Step 1: Manually activate and verify the webhook in the DB for the test
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("UPDATE Webhooks SET active = 1, verified = 1 WHERE id = ?");
        $stmt->execute([$id]);

        // Step 2: Trigger the property change
        $propResponse = self::$client->post('/api/properties.php', [
            'json' => [
                'entity_type' => 'note',
                'entity_id' => self::$testNoteId,
                'name' => 'status',
                'value' => 'DONE'
            ]
        ]);
        $this->assertEquals(200, $propResponse->getStatusCode());

        // Step 3: Check the webhook event history
        // Add a small delay to allow the webhook to be processed
        sleep(1);

        $historyResponse = self::$client->get("/api/webhooks.php?action=history&id={$id}");
        $this->assertEquals(200, $historyResponse->getStatusCode());
        
        $historyData = json_decode($historyResponse->getBody(), true);
        $this->assertTrue($historyData['success']);
        $this->assertEquals(1, $historyData['pagination']['total']);
        
        $event = $historyData['history'][0];
        $this->assertEquals('property_change', $event['event_type']);
        $this->assertEquals(1, $event['success']); // Assuming example.com returns 200
        
        $payload = json_decode($event['payload'], true);
        $this->assertEquals('property_change', $payload['event']);
        $this->assertEquals(self::$testNoteId, $payload['data']['entity_id']);
        $this->assertEquals('status', $payload['data']['property_name']);
        $this->assertEquals('DONE', $payload['data']['value']);
    }

    /**
     * @depends testCreateWebhook
     */
    public function testDeleteWebhook($id) {
        $response = self::$client->delete("/api/webhooks.php?id={$id}");
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        
        // Verify it's gone
        $getResponse = self::$client->get("/api/webhooks.php?id={$id}");
        $this->assertEquals(404, $getResponse->getStatusCode());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWebhookDispatchDisabledByConfig() {
        // Define WEBHOOKS_ENABLED as false for this test process
        define('WEBHOOKS_ENABLED', false);

        // Re-include dependent files that use this constant if necessary,
        // or ensure the app's entry points re-read config.
        // The WebhooksManager will read config.php when instantiated or when dispatchEvent is called.
        // Since api/webhooks.php includes config.php, this should be fine.

        // Create a webhook
        $createResponse = self::$client->post('/api/webhooks.php', [
            'json' => [
                'url' => 'https://example.com/webhook-listener-disabled-test',
                'entity_type' => 'note',
                'property_name' => 'status_disabled_test'
            ]
        ]);
        $this->assertEquals(200, $createResponse->getStatusCode(), "Failed to create webhook for disabled test.");
        $createData = json_decode($createResponse->getBody(), true);
        $this->assertTrue($createData['success']);
        $webhookId = $createData['data']['id'];

        // Manually activate and verify the webhook in the DB for the test
        // This is important because dispatchEvent won't run if not active/verified
        // Need to get DB connection within this process
        require_once __DIR__ . '/../../api/db_connect.php'; // Ensures get_db_connection is available
        $pdo = get_db_connection(); 
        $stmt = $pdo->prepare("UPDATE Webhooks SET active = 1, verified = 1 WHERE id = ?");
        $stmt->execute([$webhookId]);
        $this->assertEquals(1, $stmt->rowCount(), "Failed to activate/verify webhook for disabled test.");
        
        // Trigger the property change that would normally dispatch a webhook
        $propResponse = self::$client->post('/api/properties.php', [
            'json' => [
                'entity_type' => 'note',
                'entity_id' => self::$testNoteId, // Ensure this ID is valid
                'name' => 'status_disabled_test', // Match property_name
                'value' => 'TRIGGER_DISABLED'
            ]
        ]);
        $this->assertEquals(200, $propResponse->getStatusCode(), "Property change failed for disabled test.");

        // Add a small delay to allow any async processing (if any, though current dispatch is synchronous)
        sleep(1);

        // Check the webhook event history - it should be empty or not contain the new event
        $historyResponse = self::$client->get("/api/webhooks.php?action=history&id={$webhookId}");
        $this->assertEquals(200, $historyResponse->getStatusCode(), "Failed to get history for disabled test.");
        
        $historyData = json_decode($historyResponse->getBody(), true);
        $this->assertTrue($historyData['success']);
        // Assert that no event was logged because webhooks are disabled.
        $this->assertEquals(0, $historyData['pagination']['total'], "Webhook event was logged even when WEBHOOKS_ENABLED=false.");
    }
} 