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
    private function createNoteDirectly(string $content, int $pageId, array $properties = [], ?int $parentNoteId = null): int
    {
        $stmt = self::$pdo->prepare("INSERT INTO Notes (page_id, content, parent_note_id) VALUES (:page_id, :content, :parent_note_id)");
        $stmt->execute([':page_id' => $pageId, ':content' => $content, ':parent_note_id' => $parentNoteId]);
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

    private function createAttachmentDirectly(int $noteId, string $name = 'test_attachment.txt', string $path = 'test/path/test_attachment.txt', string $type = 'text/plain', int $size = 123): int
    {
        $stmt = self::$pdo->prepare("INSERT INTO Attachments (note_id, name, path, type, size) VALUES (:note_id, :name, :path, :type, :size)");
        $stmt->execute([
            ':note_id' => $noteId,
            ':name' => $name,
            ':path' => $path,
            ':type' => $type,
            ':size' => $size
        ]);
        return self::$pdo->lastInsertId();
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
        $this->assertTrue($response['success']); // Assuming response_utils wraps it
        $this->assertArrayHasKey('data', $response);
        $noteData = $response['data'];
        $this->assertArrayHasKey('id', $noteData);
        $this->assertEquals($data['content'], $noteData['content']);
        $this->assertEquals(self::$testPageId, $noteData['page_id']);
        $this->assertIsArray($noteData['properties']); // Should be empty or contain parsed from content
        $this->assertArrayHasKey('has_attachments', $noteData, "Response should have 'has_attachments' key.");
        $this->assertEquals(0, $noteData['has_attachments'], "'has_attachments' should be 0 for a new note without attachments.");

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
        
        $this->assertTrue($response['success']);
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
        
        $this->assertFalse($response['success']);
        $this->assertEquals('A valid page_id is required.', $response['error']['message']);
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
        $this->assertFalse($response['success']);
        // A more specific check could be $this->assertStringContainsString('Failed to create note', $response['message']);
        // Or if FKs are enforced: $this->assertStringContainsString('FOREIGN KEY constraint failed', $response['details']);
        // For now, a general "Failed to create note" is expected from the catch block in notes.php
        $this->assertTrue(
            strpos($response['error']['message'], 'Failed to create note') !== false ||
            (isset($response['error']['details']) && strpos($response['error']['details'], 'FOREIGN KEY constraint failed') !== false),
            "Error message mismatch: " . $response['error']['message'] . (isset($response['error']['details']) ? " Details: " . $response['error']['details'] : "")
        );
    }
    
    // Test for missing content (optional, as notes.php allows empty content for notes)
    public function testPostCreateNoteWithEmptyContent()
    {
        $data = ['page_id' => self::$testPageId, 'content' => ''];
        $response = $this->request('POST', 'api/notes.php', $data);
        $this->assertTrue($response['success']);
        $this->assertEquals('', $response['data']['content']);
    }

    // Test creating a note with parent_note_id (child note)
    public function testPostCreateChildNoteSuccess()
    {
        // First create a parent note
        $parentData = [
            'page_id' => self::$testPageId,
            'content' => 'Parent note'
        ];
        $parentResponse = $this->request('POST', 'api/notes.php', $parentData);
        $this->assertTrue($parentResponse['success']);
        $parentId = $parentResponse['data']['id'];

        // Now create a child note
        $childData = [
            'page_id' => self::$testPageId,
            'content' => 'Child note',
            'parent_note_id' => $parentId
        ];
        $childResponse = $this->request('POST', 'api/notes.php', $childData);
        
        $this->assertTrue($childResponse['success']);
        $childNote = $childResponse['data'];
        $this->assertEquals($childData['content'], $childNote['content']);
        $this->assertEquals($parentId, $childNote['parent_note_id']);
        $this->assertEquals(self::$testPageId, $childNote['page_id']);

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $childNote['id']]);
        $dbNote = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($dbNote);
        $this->assertEquals($parentId, $dbNote['parent_note_id']);
    }

    // Test creating a note with null parent_note_id (should be top-level)
    public function testPostCreateNoteWithNullParentId()
    {
        $data = [
            'page_id' => self::$testPageId,
            'content' => 'Top-level note',
            'parent_note_id' => null
        ];
        $response = $this->request('POST', 'api/notes.php', $data);
        
        $this->assertTrue($response['success']);
        $noteData = $response['data'];
        $this->assertNull($noteData['parent_note_id']);
        $this->assertEquals($data['content'], $noteData['content']);

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteData['id']]);
        $dbNote = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($dbNote);
        $this->assertNull($dbNote['parent_note_id']);
    }

    // Test creating a note with empty string parent_note_id (should be treated as null)
    public function testPostCreateNoteWithEmptyStringParentId()
    {
        $data = [
            'page_id' => self::$testPageId,
            'content' => 'Another top-level note',
            'parent_note_id' => ''
        ];
        $response = $this->request('POST', 'api/notes.php', $data);
        
        $this->assertTrue($response['success']);
        $noteData = $response['data'];
        $this->assertNull($noteData['parent_note_id']);

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteData['id']]);
        $dbNote = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($dbNote);
        $this->assertNull($dbNote['parent_note_id']);
    }

    // Test creating a child note with invalid parent_note_id
    public function testPostCreateChildNoteWithInvalidParentId()
    {
        $data = [
            'page_id' => self::$testPageId,
            'content' => 'Child with invalid parent',
            'parent_note_id' => 99999 // Non-existent note
        ];
        $response = $this->request('POST', 'api/notes.php', $data);
        
        // This should fail if foreign key constraints are enabled
        // If constraints are not enabled, it might succeed but with an invalid parent reference
        // The behavior depends on the database configuration
        if (!$response['success']) {
            // Foreign key constraint is enforced
            $this->assertTrue(
                strpos($response['error']['message'], 'Failed to create note') !== false ||
                (isset($response['error']['details']) && strpos($response['error']['details'], 'FOREIGN KEY constraint failed') !== false),
                "Expected foreign key constraint error, got: " . $response['error']['message']
            );
        } else {
            // Foreign key constraint is not enforced, but the parent_note_id should still be set
            $this->assertEquals(99999, $response['data']['parent_note_id']);
        }
    }


    // --- Test GET /api/notes.php?id={id} (Get Single Note) ---
    public function testGetSingleNoteSuccess()
    {
        $noteId = $this->createNoteDirectly('Test note for GET', self::$testPageId, ['color' => 'blue']);
        $response = $this->request('GET', 'api/notes.php', ['id' => $noteId]);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response, 'Response should contain data key');
        $noteData = $response['data'];

        $this->assertEquals($noteId, $noteData['id']);
        $this->assertEquals('Test note for GET', $noteData['content']);
        $this->assertEquals('blue', $noteData['properties']['color']);
        $this->assertArrayHasKey('has_attachments', $noteData, "Response should have 'has_attachments' key.");
        $this->assertEquals(0, $noteData['has_attachments'], "'has_attachments' should be 0 for note without attachments.");

        // Scenario 2: With attachments
        $this->createAttachmentDirectly($noteId, 'attachment1.pdf');
        
        $responseWithAttachment = $this->request('GET', 'api/notes.php', ['id' => $noteId]);
        $this->assertTrue($responseWithAttachment['success']);
        $this->assertArrayHasKey('data', $responseWithAttachment, 'Response with attachment should contain data key');
        $noteDataWithAttachment = $responseWithAttachment['data'];

        $this->assertEquals($noteId, $noteDataWithAttachment['id']);
        $this->assertArrayHasKey('has_attachments', $noteDataWithAttachment, "Response should have 'has_attachments' key.");
        $this->assertEquals(1, $noteDataWithAttachment['has_attachments'], "'has_attachments' should be 1 for note with an attachment.");
    }
    
    public function testGetSingleNoteIncludeInternal()
    {
        $noteId = $this->createNoteDirectly("Note for internal tests. \ninternal_prop::secret_val", self::$testPageId);
        $this->setPropertyInternalStatus($noteId, 'internal_prop', 1); // Mark internal_prop as internal
        $this->setNoteInternalStatus($noteId, true); // Mark the note itself as internal

        // Case 1: include_internal=false (or not provided)
        $responseFalse = $this->request('GET', 'api/notes.php', ['id' => $noteId]);
        // notes.php behavior: if note is internal and include_internal=false, it returns "Note not found or is internal"
        $this->assertFalse($responseFalse['success']);
        $this->assertEquals('Note not found or is internal', $responseFalse['error']['message']);

        // Case 2: include_internal=true
        $responseTrue = $this->request('GET', 'api/notes.php', ['id' => $noteId, 'include_internal' => 'true']);
        $this->assertTrue($responseTrue['success']);
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
        $this->assertFalse($response['success']);
        $this->assertEquals('Note not found or is internal', $response['error']['message']); // notes.php combines these
    }

    // --- Test GET /api/notes.php?page_id={id} (Get Notes by Page) ---
    public function testGetNotesByPageSuccess()
    {
        $noteId1 = $this->createNoteDirectly('Note 1 on page (no attachment)', self::$testPageId);
        $noteId2 = $this->createNoteDirectly('Note 2 on page (with attachment)', self::$testPageId);
        $this->createAttachmentDirectly($noteId2, 'page_attachment.doc');

        $response = $this->request('GET', 'api/notes.php', ['page_id' => self::$testPageId]);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('page', $response['data']);
        $this->assertEquals(self::$testPageId, $response['data']['page']['id']);
        $this->assertArrayHasKey('notes', $response['data']);
        $this->assertCount(2, $response['data']['notes']);

        $foundNote1 = false;
        $foundNote2 = false;

        foreach ($response['data']['notes'] as $note) {
            $this->assertArrayHasKey('has_attachments', $note, "Each note should have 'has_attachments' key.");
            if ($note['id'] == $noteId1) {
                $this->assertEquals(0, $note['has_attachments'], "Note 1 should have has_attachments = 0.");
                $this->assertEquals('Note 1 on page (no attachment)', $note['content']);
                $foundNote1 = true;
            } elseif ($note['id'] == $noteId2) {
                $this->assertEquals(1, $note['has_attachments'], "Note 2 should have has_attachments = 1.");
                $this->assertEquals('Note 2 on page (with attachment)', $note['content']);
                $foundNote2 = true;
            }
        }
        $this->assertTrue($foundNote1, "Note 1 was not found in the response.");
        $this->assertTrue($foundNote2, "Note 2 was not found in the response.");
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
        $this->assertTrue($responseFalse['success']);
        $this->assertCount(1, $responseFalse['data']['notes'], "Should only return public notes.");
        $publicNoteResp = $responseFalse['data']['notes'][0];
        $this->assertEquals($publicNoteId, $publicNoteResp['id']);
        $this->assertEquals('visible', $publicNoteResp['properties']['public_prop']);
        $this->assertArrayNotHasKey('internal_prop_on_public_note', $publicNoteResp['properties']);
        $this->assertArrayNotHasKey('internal', $publicNoteResp['properties']); // No 'internal::true' property for public note

        // Case 2: include_internal=true
        $responseTrue = $this->request('GET', 'api/notes.php', ['page_id' => self::$testPageId, 'include_internal' => 'true']);
        $this->assertTrue($responseTrue['success']);
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
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('notes', $response['data']);
        $this->assertEmpty($response['data']['notes']);
        
        self::$pdo->exec("DELETE FROM Pages WHERE id = " . (int)$emptyPageId);
    }

    public function testGetNotesByPageFailurePageNotFound()
    {
        $response = $this->request('GET', 'api/notes.php', ['page_id' => 88888]);
        $this->assertFalse($response['success']);
        $this->assertEquals('Page not found', $response['error']['message']);
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
        
        $this->assertTrue($response['success']);
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

        $this->assertTrue($response['success']);
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
        $this->assertTrue($response['success']);
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
        $this->assertTrue($response['success']);
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
        $this->assertFalse($response['success']);
        $this->assertEquals('Note not found', $response['error']['message']);
    }

    public function testPutUpdateNoteFailureNoUpdatableFields()
    {
        $noteId = $this->createNoteDirectly('No fields to update', self::$testPageId);
        $response = $this->request('POST', "api/notes.php?id={$noteId}", ['_method' => 'PUT']); // No actual fields
        $this->assertFalse($response['success']);
        $this->assertEquals('No updateable fields provided', $response['error']['message']);
    }

    // --- Test DELETE /api/notes.php?id={id} (Delete Note) ---
    public function testDeleteNoteSuccess()
    {
        $noteId = $this->createNoteDirectly('Note to delete', self::$testPageId, ['temp_prop' => 'del_val']);
        $response = $this->request('POST', "api/notes.php?id={$noteId}", ['_method' => 'DELETE']);

        $this->assertTrue($response['success']);
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
        $this->assertFalse($response['success']);
        $this->assertEquals('Note not found', $response['error']['message']);
    }
    
    // --- Test GET /api/notes.php (Get All Notes) ---
    public function testGetAllNotes()
    {
        // Ensure a clean slate for this specific test regarding note counts if possible,
        // or be mindful of notes created in other tests if DB is not fully reset per test method.
        // For this test, we'll create specific notes and look for them.
        
        // Delete all notes from self::$testPageId to ensure clean state for this test
        self::$pdo->exec("DELETE FROM Properties WHERE note_id IN (SELECT id FROM Notes WHERE page_id = " . (int)self::$testPageId . ")");
        self::$pdo->exec("DELETE FROM Notes WHERE page_id = " . (int)self::$testPageId . ")");
        self::$pdo->exec("DELETE FROM Attachments WHERE note_id NOT IN (SELECT id FROM Notes)"); // Clean up orphaned attachments if any

        $noteIdWithAttachment = $this->createNoteDirectly('Note A for Get All (with attachment)', self::$testPageId);
        $this->createAttachmentDirectly($noteIdWithAttachment, 'all_notes_attachment.zip');
        
        $noteIdWithoutAttachment = $this->createNoteDirectly('Note B for Get All (no attachment)', self::$testPageId);

        // Potentially create another note on another page to ensure "all" means all, not just for one page
        $otherPageStmt = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $otherPageStmt->execute([':name' => 'Other Page for All Notes Test']);
        $otherPageId = self::$pdo->lastInsertId();
        $noteIdOnOtherPage = $this->createNoteDirectly('Note C on Other Page', $otherPageId);


        $response = $this->request('GET', 'api/notes.php'); // Get all notes (default is include_internal=false)
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        
        // We expect at least 3 notes (2 on testPageId, 1 on otherPageId, assuming they are not internal)
        $this->assertGreaterThanOrEqual(3, count($response['data']), "Should fetch at least the three notes created for this test.");

        $foundNoteA = false;
        $foundNoteB = false;
        $foundNoteC = false;

        foreach ($response['data'] as $note) {
            $this->assertArrayHasKey('has_attachments', $note, "Each note in getAllNotes should have 'has_attachments'.");
            if ($note['id'] == $noteIdWithAttachment) {
                $this->assertEquals(1, $note['has_attachments'], "Note A should have has_attachments = 1.");
                $foundNoteA = true;
            } elseif ($note['id'] == $noteIdWithoutAttachment) {
                $this->assertEquals(0, $note['has_attachments'], "Note B should have has_attachments = 0.");
                $foundNoteB = true;
            } elseif ($note['id'] == $noteIdOnOtherPage) {
                 $this->assertEquals(0, $note['has_attachments'], "Note C on other page should have has_attachments = 0.");
                 $foundNoteC = true;
            }
        }

        $this->assertTrue($foundNoteA, "Note A (with attachment) was not found or correctly identified.");
        $this->assertTrue($foundNoteB, "Note B (without attachment) was not found or correctly identified.");
        $this->assertTrue($foundNoteC, "Note C (on other page) was not found or correctly identified.");
        
        // Cleanup the other page
        self::$pdo->exec("DELETE FROM Notes WHERE page_id = " . (int)$otherPageId);
        self::$pdo->exec("DELETE FROM Pages WHERE id = " . (int)$otherPageId);
    }
    
    public function testGetAllNotesIncludeInternal()
    {
        // Clean up notes on the test page for focused testing
        self::$pdo->exec("DELETE FROM Properties WHERE note_id IN (SELECT id FROM Notes WHERE page_id = " . (int)self::$testPageId . ")");
        self::$pdo->exec("DELETE FROM Notes WHERE page_id = " . (int)self::$testPageId . ")");
        self::$pdo->exec("DELETE FROM Attachments WHERE note_id NOT IN (SELECT id FROM Notes)");


        $publicNoteId = $this->createNoteDirectly('Public note for All (with attachment)', self::$testPageId);
        $this->createAttachmentDirectly($publicNoteId, 'public_attach.txt');

        $internalNoteId = $this->createNoteDirectly('Internal note for All (no attachment)', self::$testPageId);
        $this->setNoteInternalStatus($internalNoteId, true);
        
        // Another internal note, this one with an attachment
        $internalNoteWithAttachmentId = $this->createNoteDirectly('Internal note for All (with attachment)', self::$testPageId);
        $this->setNoteInternalStatus($internalNoteWithAttachmentId, true);
        $this->createAttachmentDirectly($internalNoteWithAttachmentId, 'internal_attach.doc');


        // Case 1: include_internal=false (default)
        $responseFalse = $this->request('GET', 'api/notes.php');
        $this->assertTrue($responseFalse['success']);
        $foundPublic = null;
        $foundInternal = null;
        $foundInternalWithAttachment = null;

        foreach($responseFalse['data'] as $note) {
            $this->assertArrayHasKey('has_attachments', $note, "Each note in getAllNotes (include_internal=false) should have 'has_attachments'.");
            if ($note['id'] == $publicNoteId) {
                $foundPublic = $note;
            }
            if ($note['id'] == $internalNoteId) {
                $foundInternal = $note; // Should not be found
            }
            if ($note['id'] == $internalNoteWithAttachmentId) {
                $foundInternalWithAttachment = $note; // Should not be found
            }
        }
        $this->assertNotNull($foundPublic, "Public note should be present.");
        $this->assertEquals(1, $foundPublic['has_attachments'], "Public note with attachment should have has_attachments = 1.");
        $this->assertNull($foundInternal, "Internal note (no attachment) should NOT be present when include_internal=false.");
        $this->assertNull($foundInternalWithAttachment, "Internal note (with attachment) should NOT be present when include_internal=false.");

        // Case 2: include_internal=true
        $responseTrue = $this->request('GET', 'api/notes.php', ['include_internal' => 'true']);
        $this->assertTrue($responseTrue['success']);
        $foundPublic = null;
        $foundInternal = null;
        $foundInternalWithAttachment = null;

        foreach($responseTrue['data'] as $note) {
            $this->assertArrayHasKey('has_attachments', $note, "Each note in getAllNotes (include_internal=true) should have 'has_attachments'.");
            if ($note['id'] == $publicNoteId) {
                $foundPublic = $note;
            }
            if ($note['id'] == $internalNoteId) {
                $foundInternal = $note;
            }
            if ($note['id'] == $internalNoteWithAttachmentId) {
                $foundInternalWithAttachment = $note;
            }
        }
        $this->assertNotNull($foundPublic, "Public note should be present when include_internal=true.");
        $this->assertEquals(1, $foundPublic['has_attachments'], "Public note with attachment should have has_attachments = 1 (include_internal=true).");
        
        $this->assertNotNull($foundInternal, "Internal note (no attachment) should be present when include_internal=true.");
        $this->assertEquals(0, $foundInternal['has_attachments'], "Internal note without attachment should have has_attachments = 0 (include_internal=true).");
        
        $this->assertNotNull($foundInternalWithAttachment, "Internal note (with attachment) should be present when include_internal=true.");
        $this->assertEquals(1, $foundInternalWithAttachment['has_attachments'], "Internal note with attachment should have has_attachments = 1 (include_internal=true).");
    }
}
?>
