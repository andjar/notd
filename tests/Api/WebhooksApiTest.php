<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;

class WebhooksApiTest extends TestCase {
    private static $client;
    private static $testNoteId;
    private static $dbPath;

    public static function setUpBeforeClass(): void {
        self::$dbPath = __DIR__ . '/../../db/test_database.sqlite';
        // Ensure the test database file is clean before starting
        if (file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
        
        // Setup Guzzle client
        self::$client = new Client([
            'base_uri' => 'http://localhost:8000', // Assuming the app is served here for testing
            'http_errors' => false // Don't throw exceptions for 4xx/5xx responses
        ]);

        // Manually trigger the database setup for the test database
        $_ENV['DB_PATH'] = self::$dbPath;
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
        // Clean up the test database
        if (file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
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
} 