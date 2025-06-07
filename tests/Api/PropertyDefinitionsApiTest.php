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


    // --- Test GET /api/property_definitions.php (List Definitions) ---
    public function testGetPropertyDefinitionsNoDefinitions()
    {
        $response = $this->request('GET', 'api/property_definitions.php');
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertEmpty($response['data']);
    }

    public function testGetPropertyDefinitionsWithDefinitions()
    {
        $this->createPropertyDefinitionDirectly('def1', 0, 1, 'Desc 1');
        $this->createPropertyDefinitionDirectly('def2', 1, 0, 'Desc 2');

        $response = $this->request('GET', 'api/property_definitions.php');
        $this->assertEquals('success', $response['status']);
        $this->assertCount(2, $response['data']);
        $this->assertEquals('def1', $response['data'][0]['name']);
        $this->assertEquals('Desc 2', $response['data'][1]['description']);
    }

    // --- Test POST /api/property_definitions.php (Create/Update Definition) ---
    public function testPostCreateNewPropertyDefinition()
    {
        $data = [
            'name' => 'new_def',
            'internal' => 1,
            'description' => 'A new definition',
            'auto_apply' => 0
        ];
        $response = $this->request('POST', 'api/property_definitions.php', $data);

        $this->assertEquals('success', $response['status']);
        $this->assertStringContainsString('Property definition saved', $response['data']['message']);

        $dbDef = $this->getPropertyDefinitionDirectlyByName('new_def');
        $this->assertNotEmpty($dbDef);
        $this->assertEquals(1, $dbDef['internal']);
        $this->assertEquals('A new definition', $dbDef['description']);
        $this->assertEquals(0, $dbDef['auto_apply']);
    }

    public function testPostUpdateExistingPropertyDefinition()
    {
        $this->createPropertyDefinitionDirectly('update_def', 0, 1, 'Initial desc');
        $data = [
            'name' => 'update_def', // Same name
            'internal' => 1,
            'description' => 'Updated desc',
            'auto_apply' => 0
        ];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('success', $response['status']);

        $dbDef = $this->getPropertyDefinitionDirectlyByName('update_def');
        $this->assertEquals(1, $dbDef['internal']);
        $this->assertEquals('Updated desc', $dbDef['description']);
        $this->assertEquals(0, $dbDef['auto_apply']);
    }

    public function testPostCreateDefinitionWithAutoApplyTrue()
    {
        $propId = $this->addPropertyDirectly('note', self::$testNoteId, 'auto_prop', 'value1', 0); // Initially internal=0

        $data = [
            'name' => 'auto_prop',
            'internal' => 1, // Definition makes it internal
            'description' => 'Test auto apply',
            'auto_apply' => 1
        ];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('success', $response['status']);
        $this->assertStringContainsString('applied to 1 existing properties', $response['data']['message']);

        $updatedProp = $this->getPropertyByIdDirectly($propId);
        $this->assertEquals(1, $updatedProp['internal'], "Property should now be internal=1 due to auto_apply.");
    }

    public function testPostPropertyDefinitionFailureMissingName()
    {
        $data = ['internal' => 0, 'description' => 'No name def'];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Property name is required', $response['message']);
    }
    
    public function testPostPropertyDefinitionInvalidTypes()
    {
        // Note: The API script property_definitions.php uses (int) casting for 'internal' and 'auto_apply'.
        // This means non-numeric strings become 0, and floats are truncated.
        // True validation for "must be 0 or 1" would require more robust checks in the API.
        // For now, we test what happens with stringy numbers.
        $data = ['name' => 'invalid_def_type', 'internal' => '2', 'auto_apply' => 'yes']; // Not strictly 0 or 1
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('success', $response['status']); // It will cast '2' to 2 (int) and 'yes' to 0 (int)
        $dbDef = $this->getPropertyDefinitionDirectlyByName('invalid_def_type');
        $this->assertEquals(2, $dbDef['internal']); // Stored as 2
        $this->assertEquals(0, $dbDef['auto_apply']); // 'yes' cast to 0
        // This highlights a potential area for stricter validation in the API itself if only 0/1 are desired.
    }


    // --- Test POST /api/property_definitions.php with action=delete ---
    public function testPostDeletePropertyDefinitionSuccess()
    {
        $defId = $this->createPropertyDefinitionDirectly('to_delete_def', 0, 0);
        $data = ['action' => 'delete', 'id' => $defId];
        $response = $this->request('POST', 'api/property_definitions.php', $data);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Property definition deleted', $response['data']['message']);
        $this->assertFalse($this->getPropertyDefinitionDirectlyByName('to_delete_def'));
    }

    public function testPostDeletePropertyDefinitionFailureMissingId()
    {
        $data = ['action' => 'delete'];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Definition ID required', $response['message']);
    }
    
    public function testPostDeletePropertyDefinitionNonExistentId()
    {
        // API doesn't check if ID exists before attempting delete, so PDO execute returns true (0 rows affected)
        $data = ['action' => 'delete', 'id' => 9999];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Property definition deleted', $response['data']['message']);
    }


    // --- Test GET /api/property_definitions.php?apply_all=true ---
    public function testGetApplyAllDefinitions()
    {
        $note1PropA_Id = $this->addPropertyDirectly('note', self::$testNoteId, 'prop_a_apply_all', 'val_a', 0);
        $note2_id = self::$pdo->lastInsertId(); // Create another note
        $stmtNote2 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote2->execute([':page_id' => self::$testPageId, ':content' => 'Note 2 for apply_all']);
        $note2Id = self::$pdo->lastInsertId();
        $note2PropB_Id = $this->addPropertyDirectly('note', $note2Id, 'prop_b_apply_all', 'val_b', 1);

        $this->createPropertyDefinitionDirectly('prop_a_apply_all', 1, 1); // Define prop_a to become internal=1
        $this->createPropertyDefinitionDirectly('prop_b_apply_all', 0, 1); // Define prop_b to become internal=0

        $response = $this->request('GET', 'api/property_definitions.php', ['apply_all' => 'true']);
        $this->assertEquals('success', $response['status']);
        $this->assertStringContainsString('Applied property definitions to 2 existing properties', $response['data']['message']);

        $this->assertEquals(1, $this->getPropertyByIdDirectly($note1PropA_Id)['internal']);
        $this->assertEquals(0, $this->getPropertyByIdDirectly($note2PropB_Id)['internal']);
        
        self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)$note2Id);
    }

    // --- Test POST /api/property_definitions.php with action=apply_definition ---
    public function testPostApplySingleDefinition()
    {
        $propId = $this->addPropertyDirectly('note', self::$testNoteId, 'prop_to_apply_single', 'value_s', 0);
        $this->createPropertyDefinitionDirectly('prop_to_apply_single', 1, 0); // auto_apply is OFF

        $data = ['action' => 'apply_definition', 'name' => 'prop_to_apply_single'];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('success', $response['status']);
        $this->assertStringContainsString("Applied definition for 'prop_to_apply_single' to 1 existing properties", $response['data']['message']);
        
        $this->assertEquals(1, $this->getPropertyByIdDirectly($propId)['internal']);
    }

    public function testPostApplySingleDefinitionFailureMissingName()
    {
        $data = ['action' => 'apply_definition'];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Property name required', $response['message']);
    }
    
    public function testPostApplySingleDefinitionNonExistent()
    {
        $data = ['action' => 'apply_definition', 'name' => 'no_such_definition_to_apply'];
        $response = $this->request('POST', 'api/property_definitions.php', $data);
        $this->assertEquals('success', $response['status']); // API returns success as 0 properties were updated.
        $this->assertStringContainsString("Applied definition for 'no_such_definition_to_apply' to 0 existing properties", $response['data']['message']);
    }
}
?>
