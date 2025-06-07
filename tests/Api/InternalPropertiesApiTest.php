<?php
// tests/Api/InternalPropertiesApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO;

class InternalPropertiesApiTest extends BaseTestCase
{
    protected static $testPageId;
    protected static $testNoteId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$pdo) {
            $this->fail("PDO connection not available in InternalPropertiesApiTest::setUp");
        }

        // Create a dummy page
        $stmtPage = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmtPage->execute([':name' => 'Test Page for InternalProps']);
        self::$testPageId = self::$pdo->lastInsertId();

        // Create a dummy note
        $stmtNote = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote->execute([':page_id' => self::$testPageId, ':content' => 'Test Note for InternalProps']);
        self::$testNoteId = self::$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$pdo) {
            self::$pdo->exec("DELETE FROM Properties");
            self::$pdo->exec("DELETE FROM Notes");
            self::$pdo->exec("DELETE FROM Pages");
        }
        parent::tearDown();
    }

    // --- Helper Methods ---
    private function addPropertyDirectly(string $entityType, int $entityId, string $name, string $value, int $internal = 0): int
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

    private function getPropertyDirectly(string $entityType, int $entityId, string $name): array|false
    {
        $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';
        $sql = "SELECT * FROM Properties WHERE {$idColumn} = :entityId AND name = :name";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([':entityId' => $entityId, ':name' => $name]);
        // Assuming a property is unique by name for an entity for this helper
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function getNoteDirectly(int $noteId): array|false
    {
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // --- Test GET /api/internal_properties.php ---

    public function testGetInternalStatusForNoteProperty()
    {
        $this->addPropertyDirectly('note', self::$testNoteId, 'prop1_internal', 'val1', 1);
        $this->addPropertyDirectly('note', self::$testNoteId, 'prop2_public', 'val2', 0);

        // Test for internal=1
        $response1 = $this->request('GET', 'api/internal_properties.php', [
            'entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'prop1_internal'
        ]);
        $this->assertEquals('success', $response1['status']);
        $this->assertEquals('prop1_internal', $response1['data']['name']);
        $this->assertEquals(1, $response1['data']['internal']);

        // Test for internal=0
        $response2 = $this->request('GET', 'api/internal_properties.php', [
            'entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'prop2_public'
        ]);
        $this->assertEquals('success', $response2['status']);
        $this->assertEquals('prop2_public', $response2['data']['name']);
        $this->assertEquals(0, $response2['data']['internal']);
    }

    public function testGetInternalStatusForPageProperty()
    {
        $this->addPropertyDirectly('page', self::$testPageId, 'page_prop_internal', 'pval1', 1);
        $this->addPropertyDirectly('page', self::$testPageId, 'page_prop_public', 'pval2', 0);

        $response1 = $this->request('GET', 'api/internal_properties.php', [
            'entity_type' => 'page', 'entity_id' => self::$testPageId, 'name' => 'page_prop_internal'
        ]);
        $this->assertEquals('success', $response1['status']);
        $this->assertEquals(1, $response1['data']['internal']);

        $response2 = $this->request('GET', 'api/internal_properties.php', [
            'entity_type' => 'page', 'entity_id' => self::$testPageId, 'name' => 'page_prop_public'
        ]);
        $this->assertEquals('success', $response2['status']);
        $this->assertEquals(0, $response2['data']['internal']);
    }

    public function testGetInternalStatusFailureCases()
    {
        // Missing entity_type
        $response = $this->request('GET', 'api/internal_properties.php', ['entity_id' => self::$testNoteId, 'name' => 'p1']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required GET parameters: entity_type, entity_id, name', $response['message']);

        // Missing entity_id
        $response = $this->request('GET', 'api/internal_properties.php', ['entity_type' => 'note', 'name' => 'p1']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required GET parameters: entity_type, entity_id, name', $response['message']);
        
        // Missing name
        $response = $this->request('GET', 'api/internal_properties.php', ['entity_type' => 'note', 'entity_id' => self::$testNoteId]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required GET parameters: entity_type, entity_id, name', $response['message']);

        // Property not found
        $response = $this->request('GET', 'api/internal_properties.php', [
            'entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'non_existent_prop'
        ]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Property not found', $response['message']);
        
        // Invalid entity_type (though API might not have specific validation for this string beyond 'note'/'page' for column name choice)
        // The current API uses htmlspecialchars which doesn't validate against a list. It would likely fail at DB query prep or property not found.
        $response = $this->request('GET', 'api/internal_properties.php', [
            'entity_type' => 'invalid', 'entity_id' => self::$testNoteId, 'name' => 'p1'
        ]);
         $this->assertEquals('error', $response['status']); // Expecting error due to bad SQL or property not found logic path.
         // The message might vary, e.g., "Property not found" or a DB error if the column name becomes invalid.
         // Given the code, if $idColumn is not 'page_id' or 'note_id', it might try ` = :entityId` which is invalid SQL.
         // However, the current script uses $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id'; so 'invalid' becomes 'note_id'.
         // So it would try to find it and likely result in "Property not found".
         $this->assertEquals('Property not found', $response['message']);


    }

    // --- Test POST /api/internal_properties.php ---
    public function testSetInternalStatusForNoteProperty()
    {
        $this->addPropertyDirectly('note', self::$testNoteId, 'note_prop_to_toggle', 'val', 0);

        // Set to internal=1
        $postData1 = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'note_prop_to_toggle', 'internal' => 1];
        $response1 = $this->request('POST', 'api/internal_properties.php', $postData1);
        $this->assertEquals('success', $response1['status']);
        $this->assertEquals('Property internal status updated.', $response1['data']['message']);
        $dbProp1 = $this->getPropertyDirectly('note', self::$testNoteId, 'note_prop_to_toggle');
        $this->assertEquals(1, $dbProp1['internal']);

        // Set back to internal=0
        $postData0 = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'note_prop_to_toggle', 'internal' => 0];
        $response0 = $this->request('POST', 'api/internal_properties.php', $postData0);
        $this->assertEquals('success', $response0['status']);
        $dbProp0 = $this->getPropertyDirectly('note', self::$testNoteId, 'note_prop_to_toggle');
        $this->assertEquals(0, $dbProp0['internal']);
    }

    public function testSetInternalStatusForPageProperty()
    {
        $this->addPropertyDirectly('page', self::$testPageId, 'page_prop_to_toggle', 'pval', 0);

        $postData1 = ['entity_type' => 'page', 'entity_id' => self::$testPageId, 'name' => 'page_prop_to_toggle', 'internal' => 1];
        $this->request('POST', 'api/internal_properties.php', $postData1);
        $dbProp1 = $this->getPropertyDirectly('page', self::$testPageId, 'page_prop_to_toggle');
        $this->assertEquals(1, $dbProp1['internal']);
    }
    
    public function testSetInternalStatusTriggerInteraction()
    {
        // 1. Add property 'internal::false' to the note.
        // This should, via normal property creation logic (not this API endpoint),
        // set Notes.internal to 0. The property 'internal' itself will have Properties.internal=0 (by default or definition).
        $propAddData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'internal', 'value' => 'false'];
        $this->request('POST', 'api/properties.php', $propAddData); // Use properties.php to set value and trigger Notes.internal update
        
        $noteState1 = $this->getNoteDirectly(self::$testNoteId);
        $this->assertEquals(0, $noteState1['internal'], "Note.internal should be 0 after setting 'internal::false'.");
        
        $internalPropState1 = $this->getPropertyDirectly('note', self::$testNoteId, 'internal');
        $this->assertEquals(0, $internalPropState1['internal'], "The 'internal' property's own internal flag should be 0 initially.");

        // 2. POST to internal_properties.php to change Properties.internal for the 'internal' property.
        // This action should set the 'internal' property's own flag (Properties.internal) to 1.
        // The PropertyTriggerService will be called. Since propertyName is 'internal', handleInternalPropertyForNote will run.
        // It will use the *value* of the 'internal' property (which is still "false") to update Notes.internal.
        $setData = ['entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'internal', 'internal' => 1];
        $responseSet = $this->request('POST', 'api/internal_properties.php', $setData);
        $this->assertEquals('success', $responseSet['status']);

        // 3. Verify Properties.internal for the 'internal' property is now 1.
        $internalPropState2 = $this->getPropertyDirectly('note', self::$testNoteId, 'internal');
        $this->assertEquals(1, $internalPropState2['internal'], "The 'internal' property's own internal flag should now be 1.");

        // 4. Verify Notes.internal is still 0 (because the *value* of the "internal" property is "false").
        $noteState2 = $this->getNoteDirectly(self::$testNoteId);
        $this->assertEquals(0, $noteState2['internal'], "Note.internal should still be 0 as the 'internal' property's value is 'false'.");
    }


    public function testSetInternalStatusFailureCases()
    {
        // Missing fields
        $response = $this->request('POST', 'api/internal_properties.php', ['entity_id' => self::$testNoteId, 'name' => 'p1', 'internal' => 1]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required POST parameters: entity_type, entity_id, name, internal', $response['message']);
        
        // Property not found
        $response = $this->request('POST', 'api/internal_properties.php', [
            'entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'non_existent_prop_post', 'internal' => 1
        ]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Property not found. Cannot set internal status for a non-existent property.', $response['message']);

        // Invalid 'internal' value
        $this->addPropertyDirectly('note', self::$testNoteId, 'prop_for_invalid_internal', 'val', 0);
        $response = $this->request('POST', 'api/internal_properties.php', [
            'entity_type' => 'note', 'entity_id' => self::$testNoteId, 'name' => 'prop_for_invalid_internal', 'internal' => 2
        ]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid internal flag value. Must be 0 or 1.', $response['message']);
    }
}
?>
