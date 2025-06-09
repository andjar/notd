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
        $responseDefault = $this->request('GET', '/v1/api/properties.php', ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'include_internal' => '0']);
        $this->assertEquals('success', $responseDefault['status']);
        $this->assertIsArray($responseDefault['data']);
        $this->assertArrayHasKey('color', $responseDefault['data']);
        $this->assertIsArray($responseDefault['data']['color']);
        $this->assertCount(1, $responseDefault['data']['color']);
        $this->assertEquals('blue', $responseDefault['data']['color'][0]['value']);
        $this->assertEquals(0, $responseDefault['data']['color'][0]['internal']);
        
        $this->assertArrayHasKey('tags', $responseDefault['data']);
        $this->assertIsArray($responseDefault['data']['tags']);
        $this->assertCount(2, $responseDefault['data']['tags']); // Expecting two tag objects
        // Order might not be guaranteed, so check for presence
        $foundUrgent = false; $foundImportant = false;
        foreach($responseDefault['data']['tags'] as $tag) {
            if ($tag['value'] === 'urgent') { $foundUrgent = true; $this->assertEquals(0, $tag['internal']); }
            if ($tag['value'] === 'important') { $foundImportant = true; $this->assertEquals(0, $tag['internal']); }
        }
        $this->assertTrue($foundUrgent, "Tag 'urgent' not found or incorrect structure.");
        $this->assertTrue($foundImportant, "Tag 'important' not found or incorrect structure.");
        $this->assertArrayNotHasKey('internal_code', $responseDefault['data']);

        // include_internal=true
        $responseInternal = $this->request('GET', '/v1/api/properties.php', ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'include_internal' => '1']);
        $this->assertEquals('success', $responseInternal['status']);
        $this->assertIsArray($responseInternal['data']);
        
        $this->assertArrayHasKey('color', $responseInternal['data']);
        $this->assertEquals('blue', $responseInternal['data']['color'][0]['value']);
        $this->assertEquals(0, $responseInternal['data']['color'][0]['internal']);
        
        $this->assertArrayHasKey('internal_code', $responseInternal['data']);
        $this->assertEquals('xyz123', $responseInternal['data']['internal_code'][0]['value']);
        $this->assertEquals(1, $responseInternal['data']['internal_code'][0]['internal']);
        
        $this->assertArrayHasKey('tags', $responseInternal['data']);
        $this->assertIsArray($responseInternal['data']['tags']);
        $this->assertCount(2, $responseInternal['data']['tags']);
        // Re-check for presence due to order for tags
        $foundUrgentInternal = false; $foundImportantInternal = false;
        foreach($responseInternal['data']['tags'] as $tag) {
            if ($tag['value'] === 'urgent') { $foundUrgentInternal = true; $this->assertEquals(0, $tag['internal']); }
            if ($tag['value'] === 'important') { $foundImportantInternal = true; $this->assertEquals(0, $tag['internal']); }
        }
        $this->assertTrue($foundUrgentInternal, "Tag 'urgent' not found or incorrect structure in internal response.");
        $this->assertTrue($foundImportantInternal, "Tag 'important' not found or incorrect structure in internal response.");
    }
    
    public function testGetPropertiesForPage()
    {
        $this->addPropertyDirectly('page', self::$testPageId, 'status', 'draft', 0);
        $this->addPropertyDirectly('page', self::$testPageId, 'internal_ref', 'ref456', 1);

        // Default (include_internal=false)
        $responseDefault = $this->request('GET', '/v1/api/properties.php', ['entity_type' => 'page', 'entity_id' => self::$testPageId, 'include_internal' => '0']);
        $this->assertEquals('success', $responseDefault['status']);
        $this->assertIsArray($responseDefault['data']);
        $this->assertArrayHasKey('status', $responseDefault['data']);
        $this->assertEquals('draft', $responseDefault['data']['status'][0]['value']);
        $this->assertEquals(0, $responseDefault['data']['status'][0]['internal']);
        $this->assertArrayNotHasKey('internal_ref', $responseDefault['data']);

        // include_internal=true
        $responseInternal = $this->request('GET', '/v1/api/properties.php', ['entity_type' => 'page', 'entity_id' => self::$testPageId, 'include_internal' => '1']);
        $this->assertEquals('success', $responseInternal['status']);
        $this->assertIsArray($responseInternal['data']);
        $this->assertArrayHasKey('status', $responseInternal['data']);
        $this->assertEquals('draft', $responseInternal['data']['status'][0]['value']);
        $this->assertEquals(0, $responseInternal['data']['status'][0]['internal']);
        $this->assertArrayHasKey('internal_ref', $responseInternal['data']);
        $this->assertEquals('ref456', $responseInternal['data']['internal_ref'][0]['value']);
        $this->assertEquals(1, $responseInternal['data']['internal_ref'][0]['internal']);
    }

    public function testGetPropertiesFailureCases()
    {
        $response = $this->request('GET', '/v1/api/properties.php', ['entity_id' => self::$testNoteId]); // Missing entity_type
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required GET parameters: entity_type, entity_id', $response['message']);

        $response = $this->request('GET', '/v1/api/properties.php', ['entity_type' => 'note']); // Missing entity_id
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required GET parameters: entity_type, entity_id', $response['message']);

        $response = $this->request('GET', '/v1/api/properties.php', ['entity_type' => 'invalid', 'entity_id' => self::$testNoteId]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid entity type. Must be "note" or "page".', $response['message']);

        // Non-existent entity_id (should return empty properties with success status)
        $response = $this->request('GET', '/v1/api/properties.php', ['entity_type' => 'note', 'entity_id' => 99999]);
        $this->assertEquals('success', $response['status']);
        $this->assertEmpty($response['data']); // Data should be an empty object/array
    }

    // --- Test POST /api/properties.php (Create/Update) ---
    public function testPostCreateUpdatePropertyForNote()
    {
        // Create
        $createData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'size', 'value' => 'M'];
        $payloadCreate = ['action' => 'set', 'data' => $createData];
        $responseCreate = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadCreate));
        
        $this->assertEquals('success', $responseCreate['status']);
        // Assuming response returns the set property in the new format
        $this->assertArrayHasKey('size', $responseCreate['data']);
        $this->assertEquals('M', $responseCreate['data']['size'][0]['value']);
        $this->assertEquals(0, $responseCreate['data']['size'][0]['internal']);

        $dbProp = $this->getPropertyDirectly('note', self::$testNoteId, 'size');
        $this->assertEquals('M', $dbProp[0]['value']);

        // Update
        $updateData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'size', 'value' => 'L'];
        $payloadUpdate = ['action' => 'set', 'data' => $updateData];
        $responseUpdate = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadUpdate));
        $this->assertEquals('success', $responseUpdate['status']);
        $this->assertArrayHasKey('size', $responseUpdate['data']);
        $this->assertEquals('L', $responseUpdate['data']['size'][0]['value']);
        $this->assertEquals(0, $responseUpdate['data']['size'][0]['internal']);
        
        $dbPropUpdated = $this->getPropertyDirectly('note', self::$testNoteId, 'size');
        $this->assertEquals('L', $dbPropUpdated[0]['value']);
    }

    public function testPostPropertyTagNormalizationForNote()
    {
        $data = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'tag::My Test Tag', 'value' => 'this should be replaced'];
        $payload = ['action' => 'set', 'data' => $data];
        $response = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        $propName = 'tag::My Test Tag';
        $this->assertArrayHasKey($propName, $response['data']);
        $this->assertEquals('My Test Tag', $response['data'][$propName][0]['value']); // Value normalized

        $dbProp = $this->getPropertyDirectly('note', self::$testNoteId, 'tag::My Test Tag');
        $this->assertEquals('My Test Tag', $dbProp[0]['value']);
    }

    public function testPostPropertyExplicitInternalStatusForNote()
    {
        // Explicitly internal
        $dataInternal = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'secret_key', 'value' => 'abc', 'internal' => 1];
        $payloadInternal = ['action' => 'set', 'data' => $dataInternal];
        $responseInternal = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadInternal));
        $this->assertEquals('success', $responseInternal['status']);
        $this->assertArrayHasKey('secret_key', $responseInternal['data']);
        $this->assertEquals(1, $responseInternal['data']['secret_key'][0]['internal']);
        $dbPropInternal = $this->getPropertyDirectly('note', self::$testNoteId, 'secret_key');
        $this->assertEquals(1, $dbPropInternal[0]['internal']);

        // Explicitly public
        $dataPublic = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'public_key', 'value' => 'def', 'internal' => 0];
        $payloadPublic = ['action' => 'set', 'data' => $dataPublic];
        $responsePublic = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadPublic));
        $this->assertEquals('success', $responsePublic['status']);
        $this->assertArrayHasKey('public_key', $responsePublic['data']);
        $this->assertEquals(0, $responsePublic['data']['public_key'][0]['internal']);
        $dbPropPublic = $this->getPropertyDirectly('note', self::$testNoteId, 'public_key');
        $this->assertEquals(0, $dbPropPublic[0]['internal']);
    }
    
    public function testPostPropertyInteractionWithDefinition()
    {
        // Define "defined_prop" as internal
        $this->createPropertyDefinitionDirectly('defined_prop', 1, 1);
        
        // Post without explicit internal status - should pick up from definition
        $data = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'defined_prop', 'value' => 'val1'];
        $payload = ['action' => 'set', 'data' => $data];
        $response = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('defined_prop', $response['data']);
        $this->assertEquals(1, $response['data']['defined_prop'][0]['internal'], "Should be internal due to definition.");

        // Post with explicit internal=0 - should override definition if allowed by property_auto_internal.php logic
        // Current property_auto_internal.php: $explicitInternal takes precedence.
        $dataOverride = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'defined_prop', 'value' => 'val2', 'internal' => 0];
        $payloadOverride = ['action' => 'set', 'data' => $dataOverride];
        $responseOverride = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadOverride));
        $this->assertEquals('success', $responseOverride['status']);
        $this->assertArrayHasKey('defined_prop', $responseOverride['data']);
        $this->assertEquals(0, $responseOverride['data']['defined_prop'][0]['internal'], "Explicit internal=0 should override definition.");
    }


    public function testPostCreatePropertyForPage()
    {
        $data = ['entity_type' => 'page', 'entity_id' => self::$testPageId, 'name' => 'page_status', 'value' => 'published'];
        $payload = ['action' => 'set', 'data' => $data];
        $response = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('page_status', $response['data']);
        $this->assertEquals('published', $response['data']['page_status'][0]['value']);
        $this->assertEquals(0, $response['data']['page_status'][0]['internal']);
        $dbProp = $this->getPropertyDirectly('page', self::$testPageId, 'page_status');
        $this->assertEquals('published', $dbProp[0]['value']);
    }

    public function testPostPropertyFailureCases()
    {
        // Valid "set" action for baseline
        $validSetData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'test_prop_valid', 'value' => 'val_valid'];
        $validSetPayload = ['action' => 'set', 'data' => $validSetData];
        $responseValid = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($validSetPayload));
        $this->assertEquals('success', $responseValid['status']);
        $this->assertArrayHasKey('test_prop_valid', $responseValid['data']);

        // Missing value in "set" data
        $missingValueData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'test_prop_noval'];
        $missingValuePayload = ['action' => 'set', 'data' => $missingValueData];
        $responseMissingValue = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($missingValuePayload));
        $this->assertEquals('error', $responseMissingValue['status']);
        $this->assertEquals('Missing required parameters in data: value', $responseMissingValue['message']);

        // Invalid entity type
        $invalidEntityTypeData = ['entity_type' => 'invalid', 'entity_id' => self::$testNoteId, 'name' => 'test_prop_invalidtype', 'value' => 'val_invalidtype'];
        $invalidEntityTypePayload = ['action' => 'set', 'data' => $invalidEntityTypeData];
        $responseInvalidType = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($invalidEntityTypePayload));
        $this->assertEquals('error', $responseInvalidType['status']);
        $this->assertEquals('Invalid entity type. Must be "note" or "page".', $responseInvalidType['message']);
        
        // Non-existent entity_id (properties.php doesn't check existence before REPLACE, triggers might fail)
        // API should ideally validate entity existence. If it does, this should be an error.
        // If it doesn't, and DB constraints are deferred or FKs not present, it might succeed or give a DB error.
        $dataInvalidEntity = ['entity_type' => 'note', 'entity_id' => 88888, 'name' => 'orphan_prop', 'value' => 'orphan_val'];
        $payloadInvalidEntity = ['action' => 'set', 'data' => $dataInvalidEntity];
        $responseInvalidEntity = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadInvalidEntity));
        // A robust API should validate entity existence and return an error.
        $this->assertEquals('error', $responseInvalidEntity['status'], "Setting property for non-existent entity should fail.");
        $this->assertStringContainsStringIgnoringCase('entity not found', $responseInvalidEntity['message'], "Error message should indicate entity not found.");
    }

    // --- Test POST /api/properties.php with action=delete ---
    public function testPostDeletePropertyForNote()
    {
        $this->addPropertyDirectly('note', self::$testNoteId, 'temp_color', 'red');
        $this->assertNotEmpty($this->getPropertyDirectly('note', self::$testNoteId, 'temp_color'));

        $deleteData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'temp_color'];
        $payload = ['action' => 'delete', 'data' => $deleteData];
        $response = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        // Optionally check response data for what was deleted, e.g.
        // $this->assertEquals('temp_color', $response['data']['deleted_property_name']);
        $this->assertEmpty($this->getPropertyDirectly('note', self::$testNoteId, 'temp_color'));
    }

    public function testPostDeletePropertyForPage()
    {
        $this->addPropertyDirectly('page', self::$testPageId, 'temp_status', 'archived');
        $this->assertNotEmpty($this->getPropertyDirectly('page', self::$testPageId, 'temp_status'));

        $deleteData = ['entity_type' => 'page', 'entity_id' => self::$testPageId, 'name' => 'temp_status'];
        $payload = ['action' => 'delete', 'data' => $deleteData];
        $response = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        $this->assertEmpty($this->getPropertyDirectly('page', self::$testPageId, 'temp_status'));
    }

    public function testPostDeletePropertyFailureCases()
    {
        // Missing name
        $deleteDataMissingName = ['entity_type' => 'note', 'entity_id' => self::$testNoteId];
        $payloadMissingName = ['action' => 'delete', 'data' => $deleteDataMissingName];
        $responseMissingName = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadMissingName));
        $this->assertEquals('error', $responseMissingName['status']);
        $this->assertEquals('Missing required parameters in data: name', $responseMissingName['message']);

        // Delete non-existent property (should be success - idempotent)
        $deleteDataNonExistent = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'non_existent_prop'];
        $payloadNonExistent = ['action' => 'delete', 'data' => $deleteDataNonExistent];
        $responseNonExistent = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadNonExistent));
        $this->assertEquals('success', $responseNonExistent['status']); // Deleting non-existent is idempotent
    }

    public function testPropertyUpdateDeactivatesOldInstance()
    {
        // Test for entity_type = 'note'
        $this->runPropertyUpdateDeactivationTest('note', self::$testNoteId);

        // Test for entity_type = 'page'
        $this->runPropertyUpdateDeactivationTest('page', self::$testPageId);
    }

    private function runPropertyUpdateDeactivationTest(string $entityType, int $entityId)
    {
        $propertyName = 'test_deactivation_prop';
        $initialValue = 'initial_value';
        $updatedValue = 'updated_value';

        // Add initial property
        $createData = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'name' => $propertyName,
            'value' => $initialValue
        ];
        $payloadCreate = ['action' => 'set', 'data' => $createData];
        $responseCreate = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadCreate));
        $this->assertEquals('success', $responseCreate['status'], "Failed to create initial property for {$entityType}");

        // Update the same property
        $updateData = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'name' => $propertyName,
            'value' => $updatedValue
        ];
        $payloadUpdate = ['action' => 'set', 'data' => $updateData];
        $responseUpdate = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadUpdate));
        $this->assertEquals('success', $responseUpdate['status'], "Failed to update property for {$entityType}");
        // Assert response structure for update if necessary, e.g.
        // $this->assertArrayHasKey($propertyName, $responseUpdate['data']);
        // $this->assertEquals($updatedValue, $responseUpdate['data'][$propertyName][0]['value']);

        // Assertions directly from DB
        $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';
        $stmt = self::$pdo->prepare("SELECT value, active FROM Properties WHERE {$idColumn} = :entityId AND name = :name ORDER BY id ASC");
        $stmt->execute([':entityId' => $entityId, ':name' => $propertyName]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $properties, "Should be two instances of the property for {$entityType}");

        $this->assertEquals($initialValue, $properties[0]['value'], "Initial value mismatch for {$entityType}");
        $this->assertEquals(0, $properties[0]['active'], "Old property instance should be inactive for {$entityType}");

        $this->assertEquals($updatedValue, $properties[1]['value'], "Updated value mismatch for {$entityType}");
        $this->assertEquals(1, $properties[1]['active'], "New property instance should be active for {$entityType}");
    }

    public function testPostDeleteSpecificPropertyValue()
    {
        // Add multiple values for a 'tags' property on a note
        $this->addPropertyDirectly('note', self::$testNoteId, 'tags', 'tag1', 0);
        $this->addPropertyDirectly('note', self::$testNoteId, 'tags', 'tag2', 0);
        $this->addPropertyDirectly('note', self::$testNoteId, 'tags', 'tag3', 0);

        $initialProps = $this->getPropertyDirectly('note', self::$testNoteId, 'tags');
        $this->assertCount(3, $initialProps, "Should have 3 tag values initially.");

        // Delete only 'tag2'
        $deleteData = [
            'entity_type' => 'note', 
            'entity_id' => self::$testNoteId, 
            'name' => 'tags', 
            'value' => 'tag2' // Specify the value to delete
        ];
        $payload = ['action' => 'delete', 'data' => $deleteData];
        $response = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        
        // Verify 'tag2' is removed, 'tag1' and 'tag3' remain
        $remainingProps = $this->getPropertyDirectly('note', self::$testNoteId, 'tags');
        $this->assertCount(2, $remainingProps, "Should have 2 tag values remaining.");
        $values = array_column($remainingProps, 'value');
        $this->assertContains('tag1', $values);
        $this->assertNotContains('tag2', $values);
        $this->assertContains('tag3', $values);

        // Test deleting the last value of a property
        $this->addPropertyDirectly('note', self::$testNoteId, 'single_prop', 'only_value', 0);
        $deleteSingleData = [
            'entity_type' => 'note',
            'entity_id' => self::$testNoteId,
            'name' => 'single_prop',
            'value' => 'only_value'
        ];
        $payloadSingle = ['action' => 'delete', 'data' => $deleteSingleData];
        $responseSingle = $this->request('POST', '/v1/api/properties.php', [], [], json_encode($payloadSingle));
        $this->assertEquals('success', $responseSingle['status']);
        $this->assertEmpty($this->getPropertyDirectly('note', self::$testNoteId, 'single_prop'), "Property should be fully removed if last value is deleted.");
    }
}
?>
