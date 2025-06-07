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
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertEmpty($response['data']);
    }

    public function testGetPropertyDefinitionsWithDefinitions()
    {
        $this->createPropertyDefinitionDirectly('def1', 0, 1, 'Desc 1');
        $this->createPropertyDefinitionDirectly('def2', 1, 0, 'Desc 2');

        $response = $this->request('GET', 'api/property_definitions.php');
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertCount(2, $response['data']);
        $this->assertEquals('def1', $response['data'][0]['name']);
        $this->assertEquals('Desc 2', $response['data'][1]['description']);
    }

    // --- Test POST /api/property_definitions.php (Create/Update Definition) ---
    public function testPostCreateNewPropertyDefinition()
    {
        $response = $this->request('POST', 'api/property_definitions.php', [
            'name' => 'new_prop',
            'internal' => 1,
            'auto_apply' => 1,
            'description' => 'A new property'
        ]);
        $this->assertTrue($response['success']);
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
        $response = $this->request('POST', 'api/property_definitions.php', [
            'name' => 'existing_prop',
            'internal' => 0,
            'auto_apply' => 0,
            'description' => 'Updated description'
        ]);
        $this->assertTrue($response['success']);
        $this->assertEquals('existing_prop', $response['data']['name']);
        $this->assertEquals(0, $response['data']['internal']);
        $this->assertEquals(0, $response['data']['auto_apply']);
        $this->assertEquals('Updated description', $response['data']['description']);

        $dbDef = $this->getPropertyDefinitionDirectlyByName('existing_prop');
        $this->assertEquals(0, $dbDef['internal']);
        $this->assertEquals('Updated description', $dbDef['description']);
        $this->assertEquals(0, $dbDef['auto_apply']);
    }

    public function testPostCreateDefinitionWithAutoApplyTrue()
    {
        $propId = $this->addPropertyDirectly('note', self::$testNoteId, 'auto_prop', 'value1', 0); // Initially internal=0

        $response = $this->request('POST', 'api/property_definitions.php', [
            'name' => 'auto_prop',
            'internal' => 1,
            'auto_apply' => 1
        ]);
        $this->assertTrue($response['success']);
        $this->assertEquals('auto_prop', $response['data']['name']);
        $this->assertEquals(1, $response['data']['internal']);
        $this->assertEquals(1, $response['data']['auto_apply']);

        $updatedProp = $this->getPropertyByIdDirectly($propId);
        $this->assertEquals(1, $updatedProp['internal'], "Property should now be internal=1 due to auto_apply.");
    }

    public function testPostPropertyDefinitionFailureMissingName()
    {
        $response = $this->request('POST', 'api/property_definitions.php', [
            'internal' => 1,
            'auto_apply' => 1
        ]);
        $this->assertFalse($response['success']);
        $this->assertEquals('Missing required POST parameters: name', $response['error']['message']);
    }
    
    public function testPostPropertyDefinitionInvalidTypes()
    {
        // Note: The API script property_definitions.php uses (int) casting for 'internal' and 'auto_apply'.
        // This means non-numeric strings become 0, and floats are truncated.
        // True validation for "must be 0 or 1" would require more robust checks in the API.
        // For now, we test what happens with stringy numbers.
        $response = $this->request('POST', 'api/property_definitions.php', [
            'name' => 'invalid_prop',
            'internal' => '2', // Invalid value
            'auto_apply' => 'yes' // Invalid value
        ]);
        $this->assertTrue($response['success']); // It will cast '2' to 2 (int) and 'yes' to 0 (int)
        $this->assertEquals('invalid_prop', $response['data']['name']);
        $this->assertEquals(2, $response['data']['internal']);
        $this->assertEquals(0, $response['data']['auto_apply']);
        // This highlights a potential area for stricter validation in the API itself if only 0/1 are desired.
    }


    // --- Test POST /api/property_definitions.php with action=delete ---
    public function testPostDeletePropertyDefinitionSuccess()
    {
        $defId = $this->createPropertyDefinitionDirectly('to_delete_def', 0, 0);
        $response = $this->request('POST', 'api/property_definitions.php', [
            'name' => 'to_delete_def',
            '_method' => 'DELETE'
        ]);
        $this->assertTrue($response['success']);
        $this->assertEquals('Property definition deleted successfully.', $response['data']['message']);
        $this->assertFalse($this->getPropertyDefinitionDirectlyByName('to_delete_def'));
    }

    public function testPostDeletePropertyDefinitionFailureMissingId()
    {
        $response = $this->request('POST', 'api/property_definitions.php', [
            '_method' => 'DELETE'
        ]);
        $this->assertFalse($response['success']);
        $this->assertEquals('Missing required POST parameters: name', $response['error']['message']);
    }
    
    public function testPostDeletePropertyDefinitionNonExistentId()
    {
        // API doesn't check if ID exists before attempting delete, so PDO execute returns true (0 rows affected)
        $response = $this->request('POST', 'api/property_definitions.php', [
            'name' => 'non_existent_prop',
            '_method' => 'DELETE'
        ]);
        $this->assertTrue($response['success']); // API returns success as 0 properties were updated.
        $this->assertEquals('Property definition deleted successfully.', $response['data']['message']);
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

        $response = $this->request('POST', 'api/property_definitions.php', [
            'action' => 'apply_all'
        ]);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertArrayHasKey('applied_count', $response['data']);

        $this->assertEquals(1, $this->getPropertyByIdDirectly($note1PropA_Id)['internal']);
        $this->assertEquals(0, $this->getPropertyByIdDirectly($note2PropB_Id)['internal']);
        
        self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)$note2Id);
    }

    // --- Test POST /api/property_definitions.php with action=apply_definition ---
    public function testPostApplySingleDefinition()
    {
        $propId = $this->addPropertyDirectly('note', self::$testNoteId, 'prop_to_apply_single', 'value_s', 0);
        $this->createPropertyDefinitionDirectly('prop_to_apply_single', 1, 0); // auto_apply is OFF

        $response = $this->request('POST', 'api/property_definitions.php', [
            'action' => 'apply',
            'name' => 'prop_to_apply_single'
        ]);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertArrayHasKey('applied_count', $response['data']);
        
        $this->assertEquals(1, $this->getPropertyByIdDirectly($propId)['internal']);
    }

    public function testPostApplySingleDefinitionFailureMissingName()
    {
        $response = $this->request('POST', 'api/property_definitions.php', [
            'action' => 'apply'
        ]);
        $this->assertFalse($response['success']);
        $this->assertEquals('Missing required POST parameters: name', $response['error']['message']);
    }
    
    public function testPostApplySingleDefinitionNonExistent()
    {
        $response = $this->request('POST', 'api/property_definitions.php', [
            'action' => 'apply',
            'name' => 'non_existent_prop'
        ]);
        $this->assertTrue($response['success']); // API returns success as 0 properties were updated.
        $this->assertEquals('Property definition applied successfully.', $response['data']['message']);
    }
}
?>
