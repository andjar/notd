<?php
// tests/Api/NotesApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO;

class NotesApiTest extends BaseTestCase
{
    protected static $testPageId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$pdo) {
            $this->fail("PDO connection not available in NotesApiTest::setUp");
        }

        // Create a dummy page to associate notes with
        $stmt = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmt->execute([':name' => 'Test Page for Notes']);
        self::$testPageId = self::$pdo->lastInsertId();
        if (!self::$testPageId) {
            $this->fail("Failed to create test page in NotesApiTest::setUp");
        }
    }

    protected function tearDown(): void
    {
        // Clean up: Delete notes and pages created during tests
        // The in-memory DB is wiped, but this is good practice for other DB types
        if (self::$pdo) {
            // Order matters due to foreign key constraints if they exist and are enforced
            self::$pdo->exec("DELETE FROM Properties WHERE note_id IN (SELECT id FROM Notes WHERE page_id = " . (int)self::$testPageId . ")");
            self::$pdo->exec("DELETE FROM Notes WHERE page_id = " . (int)self::$testPageId);
            self::$pdo->exec("DELETE FROM Pages WHERE id = " . (int)self::$testPageId);
        }
        parent::tearDown();
    }

    // Helper to create a note directly for setup purposes
    private function createNoteDirectly(string $content, int $pageId, array $properties = []): int
    {
        $stmt = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmt->execute([':page_id' => $pageId, ':content' => $content]);
        $noteId = self::$pdo->lastInsertId();

        foreach ($properties as $name => $value) {
            $internal = (strpos($name, 'internal_') === 0) ? 1 : 0; // Simple convention for testing
            if (is_array($value)) { // For multi-value properties
                foreach($value as $v_item) {
                    $stmt = self::$pdo->prepare("INSERT INTO Properties (note_id, name, value, internal) VALUES (:note_id, :name, :value, :internal)");
                    $stmt->execute([':note_id' => $noteId, ':name' => $name, ':value' => $v_item, ':internal' => $internal]);
                }
            } else {
                 $stmt = self::$pdo->prepare("INSERT INTO Properties (note_id, name, value, internal) VALUES (:note_id, :name, :value, :internal)");
                 $stmt->execute([':note_id' => $noteId, ':name' => $name, ':value' => $value, ':internal' => $internal]);
            }
        }
        return $noteId;
    }
    
    // Helper to set a property as internal directly in DB for testing include_internal
    private function setPropertyInternalStatus(int $noteId, string $propName, int $status = 1)
    {
        $stmt = self::$pdo->prepare("UPDATE Properties SET internal = :internal WHERE note_id = :note_id AND name = :name");
        $stmt->execute([':internal' => $status, ':note_id' => $noteId, ':name' => $propName]);
    }

    // Helper to set a note as internal directly in DB (by setting its 'internal' property to 'true')
    private function setNoteInternalStatus(int $noteId, bool $isInternal = true)
    {
        // Check if internal property exists
        $stmt = self::$pdo->prepare("SELECT id FROM Properties WHERE note_id = :note_id AND name = 'internal' AND internal = 1");
        $stmt->execute([':note_id' => $noteId]);
        $exists = $stmt->fetch();

        if ($isInternal) {
            if ($exists) {
                $updateStmt = self::$pdo->prepare("UPDATE Properties SET value = 'true' WHERE id = :id");
                $updateStmt->execute([':id' => $exists['id']]);
            } else {
                $insertStmt = self::$pdo->prepare("INSERT INTO Properties (note_id, name, value, internal) VALUES (:note_id, 'internal', 'true', 1)");
                $insertStmt->execute([':note_id' => $noteId]);
            }
        } else { // Set to not internal
            if ($exists) {
                 $updateStmt = self::$pdo->prepare("UPDATE Properties SET value = 'false' WHERE id = :id"); // Or delete the property
                 $updateStmt->execute([':id' => $exists['id']]);
            }
        }
        // Also update the Notes.internal column if the trigger doesn't handle it for tests
        $stmt = self::$pdo->prepare("UPDATE Notes SET internal = :internal WHERE id = :id");
        $stmt->execute([':internal' => $isInternal ? 1 : 0, ':id' => $noteId]);

    }


    // --- Test POST /api/notes.php (Create Note) ---
    public function testPostCreateNoteSuccessBasicContent()
    {
        $data = [
            'page_id' => self::$testPageId,
            'content' => 'This is a new test note.'
        ];
        $response = $this->request('POST', 'api/notes.php', $data);

        $this->assertIsArray($response, "Response is not an array: " . print_r($response, true));
        $this->assertEquals('success', $response['status']); // Assuming response_utils wraps it
        $this->assertArrayHasKey('data', $response);
        $noteData = $response['data'];
        $this->assertArrayHasKey('id', $noteData);
        $this->assertEquals($data['content'], $noteData['content']);
        $this->assertEquals(self::$testPageId, $noteData['page_id']);
        $this->assertIsArray($noteData['properties']); // Should be empty or contain parsed from content

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteData['id']]);
        $dbNote = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($dbNote);
        $this->assertEquals($data['content'], $dbNote['content']);
    }

    public function testPostCreateNoteSuccessWithPropertiesInContent()
    {
        $content = "Note with props.\nstatus:: TODO\npriority:: High";
        $data = [
            'page_id' => self::$testPageId,
            'content' => $content
        ];
        $response = $this->request('POST', 'api/notes.php', $data);
        
        $this->assertEquals('success', $response['status']);
        $noteData = $response['data'];
        $this->assertArrayHasKey('id', $noteData);
        $newNoteId = $noteData['id'];

        $this->assertArrayHasKey('properties', $noteData);
        $this->assertEquals('TODO', $noteData['properties']['status']);
        $this->assertEquals('High', $noteData['properties']['priority']);

        // Verify properties in DB
        $stmt = self::$pdo->prepare("SELECT name, value FROM Properties WHERE note_id = :note_id");
        $stmt->execute([':note_id' => $newNoteId]);
        $dbProps = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertEquals('TODO', $dbProps['status']);
        $this->assertEquals('High', $dbProps['priority']);
    }

    public function testPostCreateNoteFailureMissingPageId()
    {
        $data = ['content' => 'Note without page_id'];
        $response = $this->request('POST', 'api/notes.php', $data);
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('A valid page_id is required.', $response['message']);
    }

    public function testPostCreateNoteFailureInvalidPageId()
    {
        $data = [
            'page_id' => 99999, // Non-existent page
            'content' => 'Note for invalid page'
        ];
        $response = $this->request('POST', 'api/notes.php', $data);
        
        // The API currently attempts an insert, which might fail at DB level if FK constraints are on.
        // If they are not (like in default SQLite), it might create the note with an invalid page_id.
        // The current notes.php doesn't check if page_id exists before insert.
        // Let's assume the API's response_utils or DB error handler catches this.
        // The actual error message might be "Failed to create note." due to PDOException.
        $this->assertEquals('error', $response['status']);
        // A more specific check could be $this->assertStringContainsString('Failed to create note', $response['message']);
        // Or if FKs are enforced: $this->assertStringContainsString('FOREIGN KEY constraint failed', $response['details']);
        // For now, a general "Failed to create note" is expected from the catch block in notes.php
        $this->assertTrue(
            strpos($response['message'], 'Failed to create note') !== false ||
            (isset($response['details']) && strpos($response['details'], 'FOREIGN KEY constraint failed') !== false),
            "Error message mismatch: " . $response['message'] . (isset($response['details']) ? " Details: " . $response['details'] : "")
        );
    }
    
    // Test for missing content (optional, as notes.php allows empty content for notes)
    public function testPostCreateNoteWithEmptyContent()
    {
        $data = ['page_id' => self::$testPageId, 'content' => ''];
        $response = $this->request('POST', 'api/notes.php', $data);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('', $response['data']['content']);
    }


    // --- Test GET /api/notes.php?id={id} (Get Single Note) ---
    public function testGetSingleNoteSuccess()
    {
        $noteId = $this->createNoteDirectly('Test note for GET', self::$testPageId, ['color' => 'blue']);
        $response = $this->request('GET', 'api/notes.php', ['id' => $noteId]);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals($noteId, $response['data']['id']);
        $this->assertEquals('Test note for GET', $response['data']['content']);
        $this->assertEquals('blue', $response['data']['properties']['color']);
    }
    
    public function testGetSingleNoteIncludeInternal()
    {
        $noteId = $this->createNoteDirectly("Note for internal tests. \ninternal_prop::secret_val", self::$testPageId);
        $this->setPropertyInternalStatus($noteId, 'internal_prop', 1); // Mark internal_prop as internal
        $this->setNoteInternalStatus($noteId, true); // Mark the note itself as internal

        // Case 1: include_internal=false (or not provided)
        $responseFalse = $this->request('GET', 'api/notes.php', ['id' => $noteId]);
        // notes.php behavior: if note is internal and include_internal=false, it returns "Note not found or is internal"
        $this->assertEquals('error', $responseFalse['status']);
        $this->assertEquals('Note not found or is internal', $responseFalse['message']);

        // Case 2: include_internal=true
        $responseTrue = $this->request('GET', 'api/notes.php', ['id' => $noteId, 'include_internal' => 'true']);
        $this->assertEquals('success', $responseTrue['status']);
        $this->assertEquals($noteId, $responseTrue['data']['id']);
        $this->assertArrayHasKey('internal_prop', $responseTrue['data']['properties']);
        $this->assertEquals('secret_val', $responseTrue['data']['properties']['internal_prop']['value']);
        $this->assertEquals(1, $responseTrue['data']['properties']['internal_prop']['internal']);
        $this->assertEquals(1, $responseTrue['data']['internal']); // Note's own internal flag
        $this->assertArrayHasKey('internal', $responseTrue['data']['properties']); // The 'internal::true' property itself
        $this->assertEquals('true', $responseTrue['data']['properties']['internal']['value']);
    }


    public function testGetSingleNoteFailureNotFound()
    {
        $response = $this->request('GET', 'api/notes.php', ['id' => 99999]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note not found or is internal', $response['message']); // notes.php combines these
    }

    // --- Test GET /api/notes.php?page_id={id} (Get Notes by Page) ---
    public function testGetNotesByPageSuccess()
    {
        $this->createNoteDirectly('Note 1 on page', self::$testPageId);
        $this->createNoteDirectly('Note 2 on page', self::$testPageId);

        $response = $this->request('GET', 'api/notes.php', ['page_id' => self::$testPageId]);
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('page', $response['data']);
        $this->assertEquals(self::$testPageId, $response['data']['page']['id']);
        $this->assertArrayHasKey('notes', $response['data']);
        $this->assertCount(2, $response['data']['notes']);
        $this->assertEquals('Note 1 on page', $response['data']['notes'][0]['content']);
    }
    
    public function testGetNotesByPageIncludeInternal()
    {
        $publicNoteId = $this->createNoteDirectly("Public note on page. \npublic_prop::visible \ninternal_prop_on_public_note::secret1", self::$testPageId);
        $this->setPropertyInternalStatus($publicNoteId, 'internal_prop_on_public_note', 1);

        $internalNoteId = $this->createNoteDirectly("Internal note on page. \nprop_on_internal_note::val \ninternal_prop_on_internal_note::secret2", self::$testPageId);
        $this->setPropertyInternalStatus($internalNoteId, 'internal_prop_on_internal_note', 1);
        $this->setNoteInternalStatus($internalNoteId, true);

        // Case 1: include_internal=false
        $responseFalse = $this->request('GET', 'api/notes.php', ['page_id' => self::$testPageId, 'include_internal' => 'false']);
        $this->assertEquals('success', $responseFalse['status']);
        $this->assertCount(1, $responseFalse['data']['notes'], "Should only return public notes.");
        $publicNoteResp = $responseFalse['data']['notes'][0];
        $this->assertEquals($publicNoteId, $publicNoteResp['id']);
        $this->assertEquals('visible', $publicNoteResp['properties']['public_prop']);
        $this->assertArrayNotHasKey('internal_prop_on_public_note', $publicNoteResp['properties']);
        $this->assertArrayNotHasKey('internal', $publicNoteResp['properties']); // No 'internal::true' property for public note

        // Case 2: include_internal=true
        $responseTrue = $this->request('GET', 'api/notes.php', ['page_id' => self::$testPageId, 'include_internal' => 'true']);
        $this->assertEquals('success', $responseTrue['status']);
        $this->assertCount(2, $responseTrue['data']['notes'], "Should return all notes.");
        // Find the public note
        $publicNoteRespTrue = null;
        $internalNoteRespTrue = null;
        foreach($responseTrue['data']['notes'] as $n) {
            if ($n['id'] == $publicNoteId) $publicNoteRespTrue = $n;
            if ($n['id'] == $internalNoteId) $internalNoteRespTrue = $n;
        }
        $this->assertNotNull($publicNoteRespTrue);
        $this->assertNotNull($internalNoteRespTrue);

        $this->assertEquals('visible', $publicNoteRespTrue['properties']['public_prop']['value']);
        $this->assertEquals(1, $publicNoteRespTrue['properties']['internal_prop_on_public_note']['internal']);
        $this->assertEquals('secret1', $publicNoteRespTrue['properties']['internal_prop_on_public_note']['value']);
        
        $this->assertEquals(1, $internalNoteRespTrue['internal']); // Note itself is internal
        $this->assertEquals('val', $internalNoteRespTrue['properties']['prop_on_internal_note']['value']);
        $this->assertEquals(1, $internalNoteRespTrue['properties']['internal_prop_on_internal_note']['internal']);
        $this->assertEquals('secret2', $internalNoteRespTrue['properties']['internal_prop_on_internal_note']['value']);
        $this->assertEquals('true', $internalNoteRespTrue['properties']['internal']['value']); // 'internal::true' property
    }


    public function testGetNotesByPageSuccessNoNotes()
    {
        $stmt = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmt->execute([':name' => 'Empty Page']);
        $emptyPageId = self::$pdo->lastInsertId();

        $response = $this->request('GET', 'api/notes.php', ['page_id' => $emptyPageId]);
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('notes', $response['data']);
        $this->assertEmpty($response['data']['notes']);
        
        self::$pdo->exec("DELETE FROM Pages WHERE id = " . (int)$emptyPageId);
    }

    public function testGetNotesByPageFailurePageNotFound()
    {
        $response = $this->request('GET', 'api/notes.php', ['page_id' => 88888]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }

    // --- Test PUT /api/notes.php?id={id} (Update Note) ---
    public function testPutUpdateNoteContent()
    {
        $noteId = $this->createNoteDirectly('Original content', self::$testPageId);
        $updateData = [
            'id' => $noteId, // For phpdesktop compatibility, notes.php also checks $input['id']
            'content' => 'Updated content',
            '_method' => 'PUT'
        ];
        // notes.php expects ID in GET param for PUT, or in body if _method is used.
        // Our request helper puts all data into POST for _method override.
        // Let's also test with ID in GET param.
        $response = $this->request('POST', "api/notes.php?id={$noteId}", $updateData);
        
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Updated content', $response['data']['content']);

        $stmt = self::$pdo->prepare("SELECT content, updated_at FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteId]);
        $dbNote = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Updated content', $dbNote['content']);
        $this->assertNotNull($dbNote['updated_at']); // Check if updated_at is set/changed
    }

    public function testPutUpdateNoteContentAndParseProperties()
    {
        $noteId = $this->createNoteDirectly("old_prop::old_val", self::$testPageId, ['old_prop' => 'old_val']);
        $updateData = [
            'content' => "new_prop::new_val\nstatus::Done",
            '_method' => 'PUT'
        ];
        $response = $this->request('POST', "api/notes.php?id={$noteId}", $updateData);

        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('new_prop', $response['data']['properties']);
        $this->assertEquals('new_val', $response['data']['properties']['new_prop'][0]['value']); // Properties are now array of objects
        $this->assertArrayHasKey('status', $response['data']['properties']);
        $this->assertEquals('Done', $response['data']['properties']['status'][0]['value']);
        $this->assertArrayNotHasKey('old_prop', $response['data']['properties']); // Old, non-internal prop should be gone

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT name, value FROM Properties WHERE note_id = :note_id");
        $stmt->execute([':note_id' => $noteId]);
        $dbProps = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertEquals('new_val', $dbProps['new_prop']);
        $this->assertEquals('Done', $dbProps['status']);
        $this->assertArrayNotHasKey('old_prop', $dbProps);
    }
    
    public function testPutUpdateNoteSpecialFields()
    {
        $noteId = $this->createNoteDirectly('Note for special field update', self::$testPageId);
        // Create another note to be parent
        $parentNoteId = $this->createNoteDirectly('Parent note', self::$testPageId);

        $updateData = [
            'parent_note_id' => $parentNoteId,
            'order_index' => 5,
            'collapsed' => 1,
            '_method' => 'PUT'
        ];
        $response = $this->request('POST', "api/notes.php?id={$noteId}", $updateData);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals($parentNoteId, $response['data']['parent_note_id']);
        $this->assertEquals(5, $response['data']['order_index']);
        $this->assertEquals(1, $response['data']['collapsed']);
    }
    
    public function testPutUpdateNotePropertiesExplicit()
    {
        $noteId = $this->createNoteDirectly("content_prop::val1", self::$testPageId, ['content_prop' => 'val1']);
        $updateData = [
            'properties_explicit' => [
                'explicit_prop1' => 'exp_val1',
                'explicit_prop2' => ['exp_val2a', 'exp_val2b'] // Multi-value
            ],
            '_method' => 'PUT'
        ];
        // When properties_explicit is used, content properties are NOT re-parsed if content isn't also sent.
        // If content IS sent, notes.php currently still parses it.
        // The prompt implies properties_explicit should take precedence.
        // The current notes.php clears non-internal properties if content or properties_explicit is sent.
        // Then it saves properties_explicit if present.
        // If only properties_explicit is sent (no content change), content_prop should be removed.

        $response = $this->request('POST', "api/notes.php?id={$noteId}", $updateData);
        $this->assertEquals('success', $response['status']);
        $responseDataProps = $response['data']['properties'];

        $this->assertArrayHasKey('explicit_prop1', $responseDataProps);
        $this->assertEquals('exp_val1', $responseDataProps['explicit_prop1'][0]['value']);
        $this->assertArrayHasKey('explicit_prop2', $responseDataProps);
        $this->assertCount(2, $responseDataProps['explicit_prop2']);
        $this->assertEquals('exp_val2a', $responseDataProps['explicit_prop2'][0]['value']);
        $this->assertEquals('exp_val2b', $responseDataProps['explicit_prop2'][1]['value']);
        
        $this->assertArrayNotHasKey('content_prop', $responseDataProps, "Content-derived property should be removed when properties_explicit is used.");

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT name, value FROM Properties WHERE note_id = :note_id ORDER BY name, value");
        $stmt->execute([':note_id' => $noteId]);
        $dbProps = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($dbProps[$row['name']])) $dbProps[$row['name']] = [];
            $dbProps[$row['name']][] = $row['value'];
        }
        
        $this->assertEquals(['exp_val1'], $dbProps['explicit_prop1']);
        $this->assertEquals(['exp_val2a', 'exp_val2b'], $dbProps['explicit_prop2']);
        $this->assertArrayNotHasKey('content_prop', $dbProps);
    }


    public function testPutUpdateNoteFailureInvalidId()
    {
        $response = $this->request('POST', "api/notes.php?id=77777", ['content' => 'update non-existent', '_method' => 'PUT']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note not found', $response['message']);
    }

    public function testPutUpdateNoteFailureNoUpdatableFields()
    {
        $noteId = $this->createNoteDirectly('No fields to update', self::$testPageId);
        $response = $this->request('POST', "api/notes.php?id={$noteId}", ['_method' => 'PUT']); // No actual fields
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('No updateable fields provided', $response['message']);
    }

    // --- Test DELETE /api/notes.php?id={id} (Delete Note) ---
    public function testDeleteNoteSuccess()
    {
        $noteId = $this->createNoteDirectly('Note to delete', self::$testPageId, ['temp_prop' => 'del_val']);
        $response = $this->request('POST', "api/notes.php?id={$noteId}", ['_method' => 'DELETE']);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals($noteId, $response['data']['deleted_note_id']);

        // Verify removed from DB
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteId]);
        $this->assertFalse($stmt->fetch());

        $stmt = self::$pdo->prepare("SELECT * FROM Properties WHERE note_id = :id");
        $stmt->execute([':id' => $noteId]);
        $this->assertFalse($stmt->fetch(), "Properties associated with the deleted note should also be deleted.");
    }

    public function testDeleteNoteFailureInvalidId()
    {
        $response = $this->request('POST', "api/notes.php?id=66666", ['_method' => 'DELETE']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note not found', $response['message']);
    }
    
    // --- Test GET /api/notes.php (Get All Notes) ---
    public function testGetAllNotes()
    {
        // notes.php currently returns all notes if no id or page_id is specified.
        $this->createNoteDirectly('Note A for Get All', self::$testPageId);
        $this->createNoteDirectly('Note B for Get All', self::$testPageId);
        
        $response = $this->request('GET', 'api/notes.php');
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertGreaterThanOrEqual(2, count($response['data']), "Should fetch at least the two notes created.");
        // Further checks could verify structure of each note object in the array.
    }
    
    public function testGetAllNotesIncludeInternal()
    {
        $publicNoteId = $this->createNoteDirectly('Public note for All', self::$testPageId);
        $internalNoteId = $this->createNoteDirectly('Internal note for All', self::$testPageId);
        $this->setNoteInternalStatus($internalNoteId, true);

        // include_internal=false (default)
        $responseFalse = $this->request('GET', 'api/notes.php');
        $this->assertEquals('success', $responseFalse['status']);
        $foundPublic = false;
        $foundInternal = false;
        foreach($responseFalse['data'] as $note) {
            if ($note['id'] == $publicNoteId) $foundPublic = true;
            if ($note['id'] == $internalNoteId) $foundInternal = true;
        }
        $this->assertTrue($foundPublic, "Public note should be present.");
        $this->assertFalse($foundInternal, "Internal note should NOT be present when include_internal=false.");

        // include_internal=true
        $responseTrue = $this->request('GET', 'api/notes.php', ['include_internal' => 'true']);
        $this->assertEquals('success', $responseTrue['status']);
        $foundPublic = false;
        $foundInternal = false;
        foreach($responseTrue['data'] as $note) {
            if ($note['id'] == $publicNoteId) $foundPublic = true;
            if ($note['id'] == $internalNoteId) $foundInternal = true;
        }
        $this->assertTrue($foundPublic, "Public note should be present when include_internal=true.");
        $this->assertTrue($foundInternal, "Internal note should be present when include_internal=true.");
    }
}
?>
