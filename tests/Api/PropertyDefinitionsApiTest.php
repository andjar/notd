<?php
// tests/Api/PropertyDefinitionsApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO;

class PropertyDefinitionsApiTest extends BaseTestCase
{
    protected static $testPageId;
    protected static $testNoteId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$pdo) {
            $this->fail("PDO connection not available in PropertyDefinitionsApiTest::setUp");
        }

        // Create a dummy page
        $stmtPage = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmtPage->execute([':name' => 'Test Page for PropDefs']);
        self::$testPageId = self::$pdo->lastInsertId();

        // Create a dummy note
        $stmtNote = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote->execute([':page_id' => self::$testPageId, ':content' => 'Test Note for PropDefs']);
        self::$testNoteId = self::$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$pdo) {
            self::$pdo->exec("DELETE FROM PropertyDefinitions");
            self::$pdo->exec("DELETE FROM Properties");
            self::$pdo->exec("DELETE FROM Notes");
            self::$pdo->exec("DELETE FROM Pages");
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
        return self::$pdo->lastInsertId();
    }
    
    private function getPropertyByIdDirectly(int $propId): array|false
    {
        $stmt = self::$pdo->prepare("SELECT * FROM Properties WHERE id = :id");
        $stmt->execute([':id' => $propId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function createPropertyDefinitionDirectly(string $name, int $internal, int $autoApply, string $description = ''): int
    {
        $sql = "INSERT INTO PropertyDefinitions (name, internal, auto_apply, description) VALUES (:name, :internal, :auto_apply, :description)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':internal' => $internal,
            ':auto_apply' => $autoApply,
            ':description' => $description
        ]);
        return self::$pdo->lastInsertId();
    }
    
    private function getPropertyDefinitionDirectlyByName(string $name): array|false
    {
        $stmt = self::$pdo->prepare("SELECT * FROM PropertyDefinitions WHERE name = :name");
        $stmt->execute([':name' => $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    // --- Test GET /v1/api/property_definitions.php (List Definitions) ---
    public function testGetPropertyDefinitionsNoDefinitions()
    {
        $response = $this->request('GET', '/v1/api/property_definitions.php', ['page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }

    public function testGetPropertyDefinitionsWithDefinitions()
    {
        $this->createPropertyDefinitionDirectly('def1', 0, 1, 'Desc 1');
        $this->createPropertyDefinitionDirectly('def2', 1, 0, 'Desc 2');

        $response = $this->request('GET', '/v1/api/property_definitions.php', ['page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(2, $response['data']['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertEquals(2, $response['data']['pagination']['total_items']);
        
        // Order might not be guaranteed, check for presence
        $foundDef1 = false; $foundDef2 = false;
        foreach($response['data']['data'] as $def) {
            if ($def['name'] === 'def1') $foundDef1 = true;
            if ($def['name'] === 'def2' && $def['description'] === 'Desc 2') $foundDef2 = true;
        }
        $this->assertTrue($foundDef1, "Definition 'def1' not found.");
        $this->assertTrue($foundDef2, "Definition 'def2' with 'Desc 2' not found.");
    }

    // --- Test POST /v1/api/property_definitions.php (Create/Update Definition) ---
    public function testPostCreateNewPropertyDefinition()
    {
        $definitionData = [
            'name' => 'new_prop',
            'internal' => 1,
            'auto_apply' => 1,
            'description' => 'A new property'
        ];
        $payload = ['action' => 'set', 'data' => $definitionData];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('new_prop', $response['data']['name']);
        $this->assertEquals(1, $response['data']['internal']);
        $this->assertEquals(1, $response['data']['auto_apply']);
        $this->assertEquals('A new property', $response['data']['description']);

        $dbDef = $this->getPropertyDefinitionDirectlyByName('new_prop');
        $this->assertNotEmpty($dbDef);
        $this->assertEquals(1, $dbDef['internal']);
        $this->assertEquals('A new property', $dbDef['description']);
        $this->assertEquals(1, $dbDef['auto_apply']);
    }

    public function testPostUpdateExistingPropertyDefinition()
    {
        $this->createPropertyDefinitionDirectly('existing_prop', 0, 0, 'Initial description');
        $definitionData = [
            'name' => 'existing_prop',
            'internal' => 0, // ensure it's treated as boolean/int by API
            'auto_apply' => 0, // ensure it's treated as boolean/int by API
            'description' => 'Updated description'
        ];
        $payload = ['action' => 'set', 'data' => $definitionData];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('existing_prop', $response['data']['name']);
        $this->assertEquals(0, $response['data']['internal']);
        $this->assertEquals(0, $response['data']['auto_apply']);
        $this->assertEquals('Updated description', $response['data']['description']);

        $dbDef = $this->getPropertyDefinitionDirectlyByName('existing_prop');
        $this->assertNotNull($dbDef, "Definition should exist in DB.");
        $this->assertEquals(0, $dbDef['internal']);
        $this->assertEquals('Updated description', $dbDef['description']);
        $this->assertEquals(0, $dbDef['auto_apply']);
    }

    public function testPostCreateDefinitionWithAutoApplyTrue()
    {
        $propId = $this->addPropertyDirectly('note', self::$testNoteId, 'auto_prop', 'value1', 0); // Initially internal=0

        $definitionData = [
            'name' => 'auto_prop',
            'internal' => 1,
            'auto_apply' => 1
        ];
        $payload = ['action' => 'set', 'data' => $definitionData];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('auto_prop', $response['data']['name']);
        $this->assertEquals(1, $response['data']['internal']);
        $this->assertEquals(1, $response['data']['auto_apply']);

        $updatedProp = $this->getPropertyByIdDirectly($propId);
        $this->assertEquals(1, $updatedProp['internal'], "Property should now be internal=1 due to auto_apply.");
    }

    public function testPostPropertyDefinitionFailureMissingName()
    {
        $definitionData = [
            'internal' => 1,
            'auto_apply' => 1
        ];
        $payload = ['action' => 'set', 'data' => $definitionData];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required parameters in data: name', $response['message']);
    }
    
    public function testPostPropertyDefinitionInvalidTypes()
    {
        // Note: The API script property_definitions.php uses (int) casting for 'internal' and 'auto_apply'.
        // This means non-numeric strings become 0, and floats are truncated.
        // True validation for "must be 0 or 1" would require more robust checks in the API.
        // For now, we test what happens with stringy numbers.
        $definitionData = [
            'name' => 'invalid_prop',
            'internal' => '2', // Invalid value, API should validate and reject or coerce.
            'auto_apply' => 'yes' // Invalid value
        ];
        $payload = ['action' => 'set', 'data' => $definitionData];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));

        // Assuming API now has stricter validation for boolean/integer fields.
        // If it coerces '2' to 1 (or true) and 'yes' to 1 (or true), then success.
        // If it rejects, then error. Let's assume rejection for non 0/1 values for internal/auto_apply.
        // However, current PHP code does (int) casting, so '2' becomes 2, 'yes' becomes 0.
        // The spec should clarify this. For now, matching existing casting behavior.
        $this->assertEquals('success', $response['status']); 
        $this->assertEquals('invalid_prop', $response['data']['name']);
        $this->assertEquals(2, $response['data']['internal']); // (int)'2' is 2
        $this->assertEquals(0, $response['data']['auto_apply']); // (int)'yes' is 0
    }


    // --- Test POST /v1/api/property_definitions.php with action=delete ---
    public function testPostDeletePropertyDefinitionSuccess()
    {
        $defId = $this->createPropertyDefinitionDirectly('to_delete_def', 0, 0);
        $payload = ['action' => 'delete', 'data' => ['id' => $defId]];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('deleted_definition_id', $response['data']);
        $this->assertEquals($defId, $response['data']['deleted_definition_id']);
        $this->assertFalse($this->getPropertyDefinitionDirectlyByName('to_delete_def'));
    }

    public function testPostDeletePropertyDefinitionFailureMissingId()
    {
        $payload = ['action' => 'delete', 'data' => []]; // Missing id
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required parameters in data: id', $response['message']);
    }
    
    public function testPostDeletePropertyDefinitionNonExistentId()
    {
        $payload = ['action' => 'delete', 'data' => ['id' => 9999]]; // Non-existent ID
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));
        // Deleting a non-existent definition should be an error or a specific status.
        // Let's assume error if not found.
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Property definition not found.', $response['message']);
    }


    // --- Test POST /v1/api/property_definitions.php with action=apply_all ---
    public function testPostApplyAllDefinitions() // Renamed from testGetApplyAllDefinitions
    {
        $note1PropA_Id = $this->addPropertyDirectly('note', self::$testNoteId, 'prop_a_apply_all', 'val_a', 0);
        $note2_id = self::$pdo->lastInsertId(); // Create another note
        $stmtNote2 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote2->execute([':page_id' => self::$testPageId, ':content' => 'Note 2 for apply_all']);
        $note2Id = self::$pdo->lastInsertId();
        $note2PropB_Id = $this->addPropertyDirectly('note', $note2Id, 'prop_b_apply_all', 'val_b', 1);

        $this->createPropertyDefinitionDirectly('prop_a_apply_all', 1, 1); // Define prop_a to become internal=1
        $this->createPropertyDefinitionDirectly('prop_b_apply_all', 0, 1); // Define prop_b to become internal=0

        $payload = ['action' => 'apply_all', 'data' => new \stdClass()]; // Empty data object
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertArrayHasKey('updated_properties_count', $response['data']); // Example response key

        $this->assertEquals(1, $this->getPropertyByIdDirectly($note1PropA_Id)['internal']);
        $this->assertEquals(0, $this->getPropertyByIdDirectly($note2PropB_Id)['internal']);
        
        self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)$note2Id);
    }

    // --- Test POST /v1/api/property_definitions.php with action=apply_definition ---
    public function testPostApplySingleDefinition()
    {
        $propId = $this->addPropertyDirectly('note', self::$testNoteId, 'prop_to_apply_single', 'value_s', 0);
        $this->createPropertyDefinitionDirectly('prop_to_apply_single', 1, 0); // auto_apply is OFF

        $payload = ['action' => 'apply_definition', 'data' => ['name' => 'prop_to_apply_single']];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertArrayHasKey('updated_properties_count', $response['data']); // Example response key
        
        $this->assertEquals(1, $this->getPropertyByIdDirectly($propId)['internal']);
    }

    public function testPostApplySingleDefinitionFailureMissingName()
    {
        $payload = ['action' => 'apply_definition', 'data' => []]; // Missing name
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required parameters in data: name', $response['message']);
    }
    
    public function testPostApplySingleDefinitionNonExistent()
    {
        $payload = ['action' => 'apply_definition', 'data' => ['name' => 'non_existent_prop_def']];
        $response = $this->request('POST', '/v1/api/property_definitions.php', [], [], json_encode($payload));
        // Applying a non-existent definition should likely be an error or specific status.
        // If it's success with 0 applied, the message should reflect that.
        // Let's assume error if definition not found.
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Property definition not found.', $response['message']);
    }
}
?>
