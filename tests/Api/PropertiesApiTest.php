<?php
// tests/Api/PropertiesApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO;

class PropertiesApiTest extends BaseTestCase
{
    protected static $testPageId;
    protected static $testNoteId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$pdo) {
            $this->fail("PDO connection not available in PropertiesApiTest::setUp");
        }

        // Create a dummy page
        $stmtPage = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmtPage->execute([':name' => 'Test Page for Properties']);
        self::$testPageId = self::$pdo->lastInsertId();
        if (!self::$testPageId) {
            $this->fail("Failed to create test page in PropertiesApiTest::setUp");
        }

        // Create a dummy note associated with the dummy page
        $stmtNote = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote->execute([':page_id' => self::$testPageId, ':content' => 'Test Note for Properties']);
        self::$testNoteId = self::$pdo->lastInsertId();
        if (!self::$testNoteId) {
            $this->fail("Failed to create test note in PropertiesApiTest::setUp");
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo) {
            self::$pdo->exec("DELETE FROM PropertyDefinitions");
            self::$pdo->exec("DELETE FROM Properties");
            self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)self::$testNoteId);
            self::$pdo->exec("DELETE FROM Pages WHERE id = " . (int)self::$testPageId);
        }
        parent::tearDown();
    }

    // --- Helper Methods ---
    private function addPropertyDirectly(string $entityType, int $entityId, string $name, string $value, int $internal = 0)
    {
        $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';
        $otherIdColumn = ($entityType === 'page') ? 'note_id' : 'page_id';

        $sql = "INSERT INTO Properties ({$idColumn}, {$otherIdColumn}, name, value, internal) VALUES (:entityId, NULL, :name, :value, :internal)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([
            ':entityId' => $entityId,
            ':name' => $name,
            ':value' => $value,
            ':internal' => $internal
        ]);
    }

    private function getPropertyDirectly(string $entityType, int $entityId, string $name): array|false
    {
        $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';
        $sql = "SELECT * FROM Properties WHERE {$idColumn} = :entityId AND name = :name";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([':entityId' => $entityId, ':name' => $name]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch all in case of multi-value, though this helper might be for single
    }
    
    private function createPropertyDefinitionDirectly(string $name, int $internalStatus, int $autoApply = 1, string $description = '')
    {
        $sql = "INSERT INTO PropertyDefinitions (name, internal, auto_apply, description) VALUES (:name, :internal, :auto_apply, :description)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':internal' => $internalStatus,
            ':auto_apply' => $autoApply,
            ':description' => $description
        ]);
    }

    // --- Test GET /api/properties.php ---

    public function testGetPropertiesForNote()
    {
        $this->addPropertyDirectly('note', self::$testNoteId, 'color', 'blue', 0);
        $this->addPropertyDirectly('note', self::$testNoteId, 'internal_code', 'xyz123', 1);
        $this->addPropertyDirectly('note', self::$testNoteId, 'tags', 'urgent', 0);
        $this->addPropertyDirectly('note', self::$testNoteId, 'tags', 'important', 0);


        // Default (include_internal=false)
        $responseDefault = $this->request('GET', 'api/properties.php', ['entity_type' => 'note', 'entity_id' => self::$testNoteId]);
        $this->assertEquals('success', $responseDefault['status']);
        $this->assertArrayHasKey('color', $responseDefault['data']);
        $this->assertEquals('blue', $responseDefault['data']['color']);
        $this->assertArrayHasKey('tags', $responseDefault['data']);
        $this->assertIsArray($responseDefault['data']['tags']); // Multi-value is array of strings
        $this->assertContains('urgent', $responseDefault['data']['tags']);
        $this->assertContains('important', $responseDefault['data']['tags']);
        $this->assertArrayNotHasKey('internal_code', $responseDefault['data']);

        // include_internal=true
        $responseInternal = $this->request('GET', 'api/properties.php', ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'include_internal' => 'true']);
        $this->assertEquals('success', $responseInternal['status']);
        $this->assertEquals('blue', $responseInternal['data']['color']['value']); // Structure changes
        $this->assertEquals(0, $responseInternal['data']['color']['internal']);
        $this->assertArrayHasKey('internal_code', $responseInternal['data']);
        $this->assertEquals('xyz123', $responseInternal['data']['internal_code']['value']);
        $this->assertEquals(1, $responseInternal['data']['internal_code']['internal']);
        $this->assertIsArray($responseInternal['data']['tags']); // Multi-value is array of objects
        $this->assertEquals('urgent', $responseInternal['data']['tags'][0]['value']);
        $this->assertEquals(0, $responseInternal['data']['tags'][0]['internal']);
    }
    
    public function testGetPropertiesForPage()
    {
        $this->addPropertyDirectly('page', self::$testPageId, 'status', 'draft', 0);
        $this->addPropertyDirectly('page', self::$testPageId, 'internal_ref', 'ref456', 1);

        // Default (include_internal=false)
        $responseDefault = $this->request('GET', 'api/properties.php', ['entity_type' => 'page', 'entity_id' => self::$testPageId]);
        $this->assertEquals('success', $responseDefault['status']);
        $this->assertEquals('draft', $responseDefault['data']['status']);
        $this->assertArrayNotHasKey('internal_ref', $responseDefault['data']);

        // include_internal=true
        $responseInternal = $this->request('GET', 'api/properties.php', ['entity_type' => 'page', 'entity_id' => self::$testPageId, 'include_internal' => 'true']);
        $this->assertEquals('success', $responseInternal['status']);
        $this->assertEquals('draft', $responseInternal['data']['status']['value']);
        $this->assertEquals(0, $responseInternal['data']['status']['internal']);
        $this->assertArrayHasKey('internal_ref', $responseInternal['data']);
        $this->assertEquals('ref456', $responseInternal['data']['internal_ref']['value']);
        $this->assertEquals(1, $responseInternal['data']['internal_ref']['internal']);
    }

    public function testGetPropertiesFailureCases()
    {
        $response = $this->request('GET', 'api/properties.php', ['entity_id' => self::$testNoteId]); // Missing entity_type
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input parameters', $response['message']);

        $response = $this->request('GET', 'api/properties.php', ['entity_type' => 'note']); // Missing entity_id
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input parameters', $response['message']);

        $response = $this->request('GET', 'api/properties.php', ['entity_type' => 'invalid_type', 'entity_id' => self::$testNoteId]);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input parameters', $response['message']); // Validator catches this

        // Non-existent entity_id (should return empty properties)
        $response = $this->request('GET', 'api/properties.php', ['entity_type' => 'note', 'entity_id' => 99999]);
        $this->assertEquals('success', $response['status']);
        $this->assertEmpty($response['data']);
    }

    // --- Test POST /api/properties.php (Create/Update) ---
    public function testPostCreateUpdatePropertyForNote()
    {
        // Create
        $createData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'size', 'value' => 'M'];
        $responseCreate = $this->request('POST', 'api/properties.php', $createData);
        $this->assertEquals('success', $responseCreate['status']);
        $this->assertEquals('size', $responseCreate['data']['property']['name']);
        $this->assertEquals('M', $responseCreate['data']['property']['value']);
        $this->assertEquals(0, $responseCreate['data']['property']['internal']); // Default internal status

        $dbProp = $this->getPropertyDirectly('note', self::$testNoteId, 'size');
        $this->assertEquals('M', $dbProp[0]['value']);

        // Update
        $updateData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'size', 'value' => 'L'];
        $responseUpdate = $this->request('POST', 'api/properties.php', $updateData);
        $this->assertEquals('success', $responseUpdate['status']);
        $this->assertEquals('L', $responseUpdate['data']['property']['value']);
        
        $dbPropUpdated = $this->getPropertyDirectly('note', self::$testNoteId, 'size');
        $this->assertEquals('L', $dbPropUpdated[0]['value']);
    }

    public function testPostPropertyTagNormalizationForNote()
    {
        $data = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'tag::My Test Tag', 'value' => 'this should be replaced'];
        $response = $this->request('POST', 'api/properties.php', $data);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('tag::My Test Tag', $response['data']['property']['name']);
        $this->assertEquals('My Test Tag', $response['data']['property']['value']); // Value normalized

        $dbProp = $this->getPropertyDirectly('note', self::$testNoteId, 'tag::My Test Tag');
        $this->assertEquals('My Test Tag', $dbProp[0]['value']);
    }

    public function testPostPropertyExplicitInternalStatusForNote()
    {
        // Explicitly internal
        $dataInternal = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'secret_key', 'value' => 'abc', 'internal' => 1];
        $responseInternal = $this->request('POST', 'api/properties.php', $dataInternal);
        $this->assertEquals(1, $responseInternal['data']['property']['internal']);
        $dbPropInternal = $this->getPropertyDirectly('note', self::$testNoteId, 'secret_key');
        $this->assertEquals(1, $dbPropInternal[0]['internal']);

        // Explicitly public
        $dataPublic = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'public_key', 'value' => 'def', 'internal' => 0];
        $responsePublic = $this->request('POST', 'api/properties.php', $dataPublic);
        $this->assertEquals(0, $responsePublic['data']['property']['internal']);
        $dbPropPublic = $this->getPropertyDirectly('note', self::$testNoteId, 'public_key');
        $this->assertEquals(0, $dbPropPublic[0]['internal']);
    }
    
    public function testPostPropertyInteractionWithDefinition()
    {
        // Define "defined_prop" as internal
        $this->createPropertyDefinitionDirectly('defined_prop', 1, 1);
        
        // Post without explicit internal status - should pick up from definition
        $data = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'defined_prop', 'value' => 'val1'];
        $response = $this->request('POST', 'api/properties.php', $data);
        $this->assertEquals(1, $response['data']['property']['internal'], "Should be internal due to definition.");

        // Post with explicit internal=0 - should override definition if allowed by property_auto_internal.php logic
        // Current property_auto_internal.php: $explicitInternal takes precedence.
        $dataOverride = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'defined_prop', 'value' => 'val2', 'internal' => 0];
        $responseOverride = $this->request('POST', 'api/properties.php', $dataOverride);
        $this->assertEquals(0, $responseOverride['data']['property']['internal'], "Explicit internal=0 should override definition.");
    }


    public function testPostCreatePropertyForPage()
    {
        $data = ['entity_type' => 'page', 'entity_id' => self::$testPageId, 'name' => 'page_status', 'value' => 'published'];
        $response = $this->request('POST', 'api/properties.php', $data);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('published', $response['data']['property']['value']);
        $dbProp = $this->getPropertyDirectly('page', self::$testPageId, 'page_status');
        $this->assertEquals('published', $dbProp[0]['value']);
    }

    public function testPostPropertyFailureCases()
    {
        $response = $this->request('POST', 'api/properties.php', ['entity_id' => self::$testNoteId, 'name' => 'n1', 'value' => 'v1']); // Missing entity_type
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input', $response['message']);

        $response = $this->request('POST', 'api/properties.php', ['entity_type' => 'note', 'name' => 'n1', 'value' => 'v1']); // Missing entity_id
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input', $response['message']);
        
        // Non-existent entity_id (properties.php doesn't check existence before REPLACE, triggers might fail)
        // This could lead to an orphaned property or a 500 if a trigger expects a valid entity.
        // The _updateOrAddPropertyAndDispatchTriggers function itself doesn't validate entityId existence.
        // Triggers might fail. For now, let's expect success from the property save itself.
        $dataInvalidEntity = ['entity_type' => 'note', 'entity_id' => 88888, 'name' => 'orphan_prop', 'value' => 'orphan_val'];
        $responseInvalidEntity = $this->request('POST', 'api/properties.php', $dataInvalidEntity);
        // Depending on trigger implementation, this might be success or error.
        // Assuming for now property save is successful, but triggers might log errors.
        // If PropertyTriggerService->dispatch throws on invalid entity, then it would be an error.
        // The current trigger service just logs, so it might appear as success.
        // Let's test for success of property creation, acknowledge trigger might fail silently in current impl.
         $this->assertEquals('success', $responseInvalidEntity['status'], "Response: ".print_r($responseInvalidEntity, true));
         // Clean up orphaned property if created
         self::$pdo->exec("DELETE FROM Properties WHERE entity_id = 88888 AND name = 'orphan_prop'");

    }

    // --- Test POST /api/properties.php with action=delete ---
    public function testPostDeletePropertyForNote()
    {
        $this->addPropertyDirectly('note', self::$testNoteId, 'temp_color', 'red');
        $this->assertNotEmpty($this->getPropertyDirectly('note', self::$testNoteId, 'temp_color'));

        $deleteData = ['action' => 'delete', 'entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'temp_color'];
        $response = $this->request('POST', 'api/properties.php', $deleteData);
        $this->assertEquals('success', $response['status']);
        $this->assertEmpty($this->getPropertyDirectly('note', self::$testNoteId, 'temp_color'));
    }

    public function testPostDeletePropertyForPage()
    {
        $this->addPropertyDirectly('page', self::$testPageId, 'temp_status', 'archived');
        $this->assertNotEmpty($this->getPropertyDirectly('page', self::$testPageId, 'temp_status'));

        $deleteData = ['action' => 'delete', 'entity_type' => 'page', 'entity_id' => self::$testPageId, 'name' => 'temp_status'];
        $response = $this->request('POST', 'api/properties.php', $deleteData);
        $this->assertEquals('success', $response['status']);
        $this->assertEmpty($this->getPropertyDirectly('page', self::$testPageId, 'temp_status'));
    }

    public function testPostDeletePropertyFailureCases()
    {
        // Missing name
        $deleteData = ['action' => 'delete', 'entity_type' => 'note', 'entity_id' => self::$testNoteId];
        $response = $this->request('POST', 'api/properties.php', $deleteData);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input for deleting property', $response['message']);

        // Delete non-existent property (should be success - idempotent)
        $deleteData = ['action' => 'delete', 'entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'non_existent_prop'];
        $response = $this->request('POST', 'api/properties.php', $deleteData);
        $this->assertEquals('success', $response['status']); 
    }
}
?>
