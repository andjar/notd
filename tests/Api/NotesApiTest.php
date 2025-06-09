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

    // --- Helper methods for batch tests ---
    private function _getNoteById(int $id): ?array
    {
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($note) {
            // Fetch properties for the note
            $propStmt = self::$pdo->prepare("SELECT name, value, internal FROM Properties WHERE note_id = :note_id");
            $propStmt->execute([':note_id' => $id]);
            $properties = [];
            while ($row = $propStmt->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($properties[$row['name']])) {
                    $properties[$row['name']] = [];
                }
                $properties[$row['name']][] = ['value' => $row['value'], 'internal' => (int)$row['internal']];
            }
            $note['properties'] = $properties;
        }
        return $note ?: null;
    }

    private function _countChildren(int $parentId): int
    {
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM Notes WHERE parent_note_id = :parent_id");
        $stmt->execute([':parent_id' => $parentId]);
        return (int)$stmt->fetchColumn();
    }

    // --- Test POST /api/notes.php (Create Note) ---
    public function testPostCreateNoteSuccessBasicContent()
    {
        $data = [
            'page_id' => self::$testPageId,
            'content' => 'This is a new test note.'
        ];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));

        $this->assertIsArray($response, "Response is not an array: " . print_r($response, true));
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('data', $response);
        $noteData = $response['data'];
        $this->assertArrayHasKey('id', $noteData);
        // Location header check
        $this->assertArrayHasKey('headers', $response, "Response should have headers array");
        $this->assertArrayHasKey('Location', $response['headers'], "Response should have Location header");
        $this->assertStringContainsString('/v1/api/notes.php?id=' . $noteData['id'], $response['headers']['Location']);
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
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
        $noteData = $response['data'];
        $this->assertArrayHasKey('id', $noteData);
        $newNoteId = $noteData['id'];

        $this->assertArrayHasKey('properties', $noteData);
        // Updated property structure check
        $this->assertArrayHasKey('status', $noteData['properties']);
        $this->assertIsArray($noteData['properties']['status']);
        $this->assertCount(1, $noteData['properties']['status']);
        $this->assertEquals('TODO', $noteData['properties']['status'][0]['value']);
        $this->assertEquals(0, $noteData['properties']['status'][0]['internal']);

        $this->assertArrayHasKey('priority', $noteData['properties']);
        $this->assertIsArray($noteData['properties']['priority']);
        $this->assertCount(1, $noteData['properties']['priority']);
        $this->assertEquals('High', $noteData['properties']['priority'][0]['value']);
        $this->assertEquals(0, $noteData['properties']['priority'][0]['internal']);


        // Verify properties in DB (DB structure remains name, value, internal per row)
        $stmt = self::$pdo->prepare("SELECT name, value, internal FROM Properties WHERE note_id = :note_id");
        $stmt->execute([':note_id' => $newNoteId]);
        $dbPropsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dbProps = [];
        foreach ($dbPropsRaw as $p) {
            $dbProps[$p['name']] = ['value' => $p['value'], 'internal' => $p['internal']];
        }
        $this->assertEquals('TODO', $dbProps['status']['value']);
        $this->assertEquals('TODO', $dbProps['status']['value']); // This was correct
        $this->assertEquals(0, $dbProps['status']['internal']);
        $this->assertEquals('High', $dbProps['priority']['value']); // This was correct
        $this->assertEquals(0, $dbProps['priority']['internal']);
    }

    public function testPostCreateNoteFailureMissingPageId()
    {
        $data = ['content' => 'Note without page_id'];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('A valid page_id is required.', $response['message']);
    }

    public function testPostCreateNoteFailureInvalidPageId()
    {
        $data = [
            'page_id' => 99999, // Non-existent page
            'content' => 'Note for invalid page'
        ];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));
        
        $this->assertEquals('error', $response['status']);
        // The error message might vary depending on DB constraints and error handling in API.
        // It could be "Page not found" if validated before insert, or "Failed to create note" if DB fails.
        // Let's assume a generic "Failed to create note" or a more specific one if the API implements it.
        // For now, I'll check for "Failed to create note" as it was the previous expectation.
        // A better API might return "Page not found with ID 99999".
        $this->assertStringContainsString('Failed to create note', $response['message']);
    }
    
    // Test for missing content (optional, as notes.php allows empty content for notes)
    public function testPostCreateNoteWithEmptyContent()
    {
        $data = ['page_id' => self::$testPageId, 'content' => ''];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
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
        $parentPayload = ['action' => 'create', 'data' => $parentData];
        $parentResponse = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($parentPayload));
        $this->assertEquals('success', $parentResponse['status']);
        $parentId = $parentResponse['data']['id'];

        // Now create a child note
        $childData = [
            'page_id' => self::$testPageId,
            'content' => 'Child note',
            'parent_note_id' => $parentId
        ];
        $childPayload = ['action' => 'create', 'data' => $childData];
        $childResponse = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($childPayload));
        
        $this->assertEquals('success', $childResponse['status']);
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
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
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
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
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
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));
        
        // This should ideally be validated by the API before attempting to insert.
        // If validated, expect an error like "Parent note not found".
        // If not validated and DB has FK constraints, expect "Failed to create note".
        $this->assertEquals('error', $response['status']);
        // Assuming the API now validates this:
        // $this->assertEquals('Parent note not found.', $response['message']);
        // Or, if it relies on DB constraint:
        $this->assertStringContainsString('Failed to create note', $response['message']);
    }


    // --- Test GET /api/notes.php?id={id} (Get Single Note) ---
    public function testGetSingleNoteSuccess()
    {
        $noteId = $this->createNoteDirectly('Test note for GET', self::$testPageId, ['color' => 'blue']);
        $response = $this->request('GET', '/v1/api/notes.php', ['id' => $noteId]);

        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('data', $response, 'Response should contain data key');
        $noteData = $response['data'];

        $this->assertEquals($noteId, $noteData['id']);
        $this->assertEquals('Test note for GET', $noteData['content']);
        
        // Updated property structure check
        $this->assertArrayHasKey('color', $noteData['properties']);
        $this->assertIsArray($noteData['properties']['color']);
        $this->assertCount(1, $noteData['properties']['color']);
        $this->assertEquals('blue', $noteData['properties']['color'][0]['value']);
        $this->assertEquals(0, $noteData['properties']['color'][0]['internal']);

        $this->assertArrayHasKey('has_attachments', $noteData, "Response should have 'has_attachments' key.");
        $this->assertEquals(0, $noteData['has_attachments'], "'has_attachments' should be 0 for note without attachments."); // This was correct

        // Scenario 2: With attachments
        $this->createAttachmentDirectly($noteId, 'attachment1.pdf');
        
        $responseWithAttachment = $this->request('GET', '/v1/api/notes.php', ['id' => $noteId]);
        $this->assertEquals('success', $responseWithAttachment['status']);
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
        $responseFalse = $this->request('GET', '/v1/api/notes.php', ['id' => $noteId]);
        $this->assertEquals('error', $responseFalse['status']);
        $this->assertEquals('Note not found or is internal', $responseFalse['message']);

        // Case 2: include_internal=true
        $responseTrue = $this->request('GET', '/v1/api/notes.php', ['id' => $noteId, 'include_internal' => '1']); // Use '1' for true
        $this->assertEquals('success', $responseTrue['status']);
        $this->assertEquals($noteId, $responseTrue['data']['id']);
        
        $this->assertArrayHasKey('internal_prop', $responseTrue['data']['properties']);
        $this->assertEquals('secret_val', $responseTrue['data']['properties']['internal_prop'][0]['value']); // This was correct
        $this->assertEquals(1, $responseTrue['data']['properties']['internal_prop'][0]['internal']); // This was correct
        
        $this->assertEquals(1, $responseTrue['data']['internal']); // Note's own internal flag - This was correct
        
        $this->assertArrayHasKey('internal', $responseTrue['data']['properties']); // The 'internal::true' property itself
        $this->assertEquals('true', $responseTrue['data']['properties']['internal'][0]['value']); // This was correct
        $this->assertEquals(1, $responseTrue['data']['properties']['internal'][0]['internal']); // This was correct
    }


    public function testGetSingleNoteFailureNotFound()
    {
        $response = $this->request('GET', '/v1/api/notes.php', ['id' => 99999]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note not found or is internal', $response['message']); // notes.php combines these
    }

    // --- Test GET /api/notes.php?page_id={id} (Get Notes by Page) ---
    public function testGetNotesByPageSuccess()
    {
        $noteId1 = $this->createNoteDirectly('Note 1 on page (no attachment)', self::$testPageId);
        $noteId2 = $this->createNoteDirectly('Note 2 on page (with attachment)', self::$testPageId);
        $this->createAttachmentDirectly($noteId2, 'page_attachment.doc');

        // Add pagination parameters
        $response = $this->request('GET', '/v1/api/notes.php', ['page_id' => self::$testPageId, 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        
        // Response structure for notes by page might be:
        // { status: "success", data: { page_info: {...}, data: [notes...], pagination: {...} } }
        // Or if page_info is not primary: { status: "success", data: [notes...], pagination: {...}, page_id: ... }
        // Based on previous structure, it had $response['data']['page'] and $response['data']['notes']
        // Let's assume it becomes: $response['data']['page_info'] and $response['data']['data'] for notes list,
        // and $response['data']['pagination']
        
        $this->assertArrayHasKey('page_info', $response['data'], "Response data should have 'page_info'");
        $this->assertEquals(self::$testPageId, $response['data']['page_info']['id']);
        
        $this->assertArrayHasKey('data', $response['data'], "Response data should have 'data' for notes list");
        $this->assertArrayHasKey('pagination', $response['data'], "Response data should have 'pagination'");
        $this->assertCount(2, $response['data']['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertEquals(2, $response['data']['pagination']['total_items']);


        $foundNote1 = false;
        $foundNote2 = false;

        foreach ($response['data']['data'] as $note) { // Notes are in 'data' array
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

        // Case 1: include_internal=false (or '0')
        $responseFalse = $this->request('GET', '/v1/api/notes.php', ['page_id' => self::$testPageId, 'include_internal' => '0', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseFalse['status']); // Corrected from 'error'
        $this->assertCount(1, $responseFalse['data']['data'], "Should only return public notes.");
        $publicNoteResp = $responseFalse['data']['data'][0];
        $this->assertEquals($publicNoteId, $publicNoteResp['id']);
        $this->assertArrayHasKey('public_prop', $publicNoteResp['properties']);
        $this->assertEquals('visible', $publicNoteResp['properties']['public_prop'][0]['value']);
        $this->assertEquals(0, $publicNoteResp['properties']['public_prop'][0]['internal']);
        $this->assertArrayNotHasKey('internal_prop_on_public_note', $publicNoteResp['properties']);
        $this->assertArrayNotHasKey('internal', $publicNoteResp['properties']);

        // Case 2: include_internal=true (or '1')
        $responseTrue = $this->request('GET', '/v1/api/notes.php', ['page_id' => self::$testPageId, 'include_internal' => '1', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseTrue['status']);
        $this->assertCount(2, $responseTrue['data']['data'], "Should return all notes.");
        
        $publicNoteRespTrue = null;
        $internalNoteRespTrue = null;
        foreach($responseTrue['data']['data'] as $n) {
            if ($n['id'] == $publicNoteId) $publicNoteRespTrue = $n;
            if ($n['id'] == $internalNoteId) $internalNoteRespTrue = $n;
        }
        $this->assertNotNull($publicNoteRespTrue);
        $this->assertNotNull($internalNoteRespTrue);

        $this->assertArrayHasKey('public_prop', $publicNoteRespTrue['properties']);
        $this->assertEquals('visible', $publicNoteRespTrue['properties']['public_prop'][0]['value']);
        $this->assertEquals(0, $publicNoteRespTrue['properties']['public_prop'][0]['internal']);
        $this->assertArrayHasKey('internal_prop_on_public_note', $publicNoteRespTrue['properties']);
        $this->assertEquals(1, $publicNoteRespTrue['properties']['internal_prop_on_public_note'][0]['internal']);
        $this->assertEquals('secret1', $publicNoteRespTrue['properties']['internal_prop_on_public_note'][0]['value']);
        
        $this->assertEquals(1, $internalNoteRespTrue['internal']); 
        $this->assertArrayHasKey('prop_on_internal_note', $internalNoteRespTrue['properties']);
        $this->assertEquals('val', $internalNoteRespTrue['properties']['prop_on_internal_note'][0]['value']);
        $this->assertEquals(0, $internalNoteRespTrue['properties']['prop_on_internal_note'][0]['internal']);
        $this->assertArrayHasKey('internal_prop_on_internal_note', $internalNoteRespTrue['properties']);
        $this->assertEquals(1, $internalNoteRespTrue['properties']['internal_prop_on_internal_note'][0]['internal']);
        $this->assertEquals('secret2', $internalNoteRespTrue['properties']['internal_prop_on_internal_note'][0]['value']);
        $this->assertArrayHasKey('internal', $internalNoteRespTrue['properties']);
        $this->assertEquals('true', $internalNoteRespTrue['properties']['internal'][0]['value']); 
        $this->assertEquals(1, $internalNoteRespTrue['properties']['internal'][0]['internal']);
    }


    public function testGetNotesByPageSuccessNoNotes()
    {
        $stmt = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmt->execute([':name' => 'Empty Page']);
        $emptyPageId = self::$pdo->lastInsertId();

        $response = $this->request('GET', '/v1/api/notes.php', ['page_id' => $emptyPageId, 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('data', $response['data'], "Notes list should be in data.data");
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
        
        self::$pdo->exec("DELETE FROM Pages WHERE id = " . (int)$emptyPageId);
    }

    public function testGetNotesByPageFailurePageNotFound()
    {
        $response = $this->request('GET', '/v1/api/notes.php', ['page_id' => 88888, 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }

    // --- Test PUT /api/notes.php?id={id} (Update Note) ---
    public function testPutUpdateNoteContent()
    {
        $noteId = $this->createNoteDirectly('Original content', self::$testPageId);
        $updateData = ['content' => 'Updated content'];
        $payload = ['action' => 'update', 'id' => $noteId, 'data' => $updateData];
        
        // ID might be in URL or payload. Spec says action in payload. Assume ID also in payload.
        // $response = $this->request('POST', "/v1/api/notes.php?id={$noteId}", [], [], json_encode($payload));
        // OR if ID is only in payload:
        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));
        
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
        $updateData = ['content' => "new_prop::new_val\nstatus::Done"];
        $payload = ['action' => 'update', 'id' => $noteId, 'data' => $updateData];
        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $props = $response['data']['properties'];
        $this->assertArrayHasKey('new_prop', $props);
        $this->assertEquals('new_val', $props['new_prop'][0]['value']);
        $this->assertEquals(0, $props['new_prop'][0]['internal']); // This was correct
        $this->assertArrayHasKey('status', $props); // This was correct
        $this->assertEquals('Done', $props['status'][0]['value']); // This was correct
        $this->assertEquals(0, $props['status'][0]['internal']); // This was correct
        $this->assertArrayNotHasKey('old_prop', $props); 

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT name, value, internal FROM Properties WHERE note_id = :note_id"); // This was correct
        $stmt->execute([':note_id' => $noteId]);
        $dbPropsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dbProps = [];
        foreach ($dbPropsRaw as $p) {
            $dbProps[$p['name']] = ['value' => $p['value'], 'internal' => $p['internal']];
        }
        $this->assertEquals('new_val', $dbProps['new_prop']['value']); // This was correct
        $this->assertEquals(0, $dbProps['new_prop']['internal']); // Added internal check for DB
        $this->assertEquals('Done', $dbProps['status']['value']); // This was correct
        $this->assertEquals(0, $dbProps['status']['internal']); // Added internal check for DB
        $this->assertArrayNotHasKey('old_prop', $dbProps); // This was correct
    }
    
    public function testPutUpdateNoteSpecialFields()
    {
        $noteId = $this->createNoteDirectly('Note for special field update', self::$testPageId);
        // Create another note to be parent
        $parentNoteId = $this->createNoteDirectly('Parent note', self::$testPageId);

        $updateData = [
            'parent_note_id' => $parentNoteId,
            'order_index' => 5,
            'collapsed' => 1
        ];
        $payload = ['action' => 'update', 'id' => $noteId, 'data' => $updateData];
        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        $this->assertEquals($parentNoteId, $response['data']['parent_note_id']);
        $this->assertEquals(5, $response['data']['order_index']);
        $this->assertEquals(1, $response['data']['collapsed']);
    }
    
    public function testPutUpdateNotePropertiesExplicit()
    {
        $noteId = $this->createNoteDirectly("content_prop::val1", self::$testPageId, ['content_prop' => 'val1']);
        $updateData = [
            'properties' => [ // Changed from properties_explicit to just 'properties' as per typical API design
                'explicit_prop1' => [['value' => 'exp_val1', 'internal' => 0]],
                'explicit_prop2' => [['value' => 'exp_val2a', 'internal' => 0], ['value' => 'exp_val2b', 'internal' => 0]] // Multi-value
            ]
        ];
        $payload = ['action' => 'update', 'id' => $noteId, 'data' => $updateData];

        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        $responseDataProps = $response['data']['properties'];

        $this->assertArrayHasKey('explicit_prop1', $responseDataProps);
        $this->assertCount(1, $responseDataProps['explicit_prop1']);
        $this->assertEquals('exp_val1', $responseDataProps['explicit_prop1'][0]['value']);
        $this->assertEquals(0, $responseDataProps['explicit_prop1'][0]['internal']);

        $this->assertArrayHasKey('explicit_prop2', $responseDataProps);
        $this->assertCount(2, $responseDataProps['explicit_prop2']);
        $this->assertEquals('exp_val2a', $responseDataProps['explicit_prop2'][0]['value']);
        $this->assertEquals(0, $responseDataProps['explicit_prop2'][0]['internal']);
        $this->assertEquals('exp_val2b', $responseDataProps['explicit_prop2'][1]['value']);
        $this->assertEquals(0, $responseDataProps['explicit_prop2'][1]['internal']);
        
        $this->assertArrayNotHasKey('content_prop', $responseDataProps, "Content-derived property should be removed when explicit properties are used.");

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT name, value, internal FROM Properties WHERE note_id = :note_id ORDER BY name, value");
        $stmt->execute([':note_id' => $noteId]);
        $dbPropsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dbProps = [];
        foreach ($dbPropsRaw as $p) {
            if (!isset($dbProps[$p['name']])) $dbProps[$p['name']] = [];
            $dbProps[$p['name']][] = ['value' => $p['value'], 'internal' => $p['internal']];
        }
        
        $this->assertCount(1, $dbProps['explicit_prop1']);
        $this->assertEquals('exp_val1', $dbProps['explicit_prop1'][0]['value']);
        $this->assertEquals(0, $dbProps['explicit_prop1'][0]['internal']); // This was correct
        $this->assertCount(2, $dbProps['explicit_prop2']); // This was correct
        $this->assertEquals('exp_val2a', $dbProps['explicit_prop2'][0]['value']); // This was correct
        $this->assertEquals(0, $dbProps['explicit_prop2'][0]['internal']); // Added internal check
        $this->assertEquals('exp_val2b', $dbProps['explicit_prop2'][1]['value']); // This was correct
        $this->assertEquals(0, $dbProps['explicit_prop2'][1]['internal']); // Added internal check
        $this->assertArrayNotHasKey('content_prop', $dbProps); // This was correct
    }


    public function testPutUpdateNoteFailureInvalidId()
    {
        $payload = ['action' => 'update', 'id' => 77777, 'data' => ['content' => 'update non-existent']];
        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note not found', $response['message']);
    }

    public function testPutUpdateNoteFailureNoUpdatableFields()
    {
        $noteId = $this->createNoteDirectly('No fields to update', self::$testPageId);
        $payload = ['action' => 'update', 'id' => $noteId, 'data' => []]; // No actual fields in data
        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('No updateable fields provided', $response['message']);
    }

    // --- Test DELETE /api/notes.php?id={id} (Delete Note) ---
    public function testDeleteNoteSuccess()
    {
        $noteId = $this->createNoteDirectly('Note to delete', self::$testPageId, ['temp_prop' => 'del_val']);
        $payload = ['action' => 'delete', 'id' => $noteId];
        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));

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
        $payload = ['action' => 'delete', 'id' => 66666];
        $response = $this->request('POST', "/v1/api/notes.php", [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note not found', $response['message']);
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


        $response = $this->request('GET', '/v1/api/notes.php', ['page' => 1, 'per_page' => 10]); // Added pagination
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('data', $response['data'], "Notes list should be in data.data");
        $this->assertArrayHasKey('pagination', $response['data']);
        
        // We expect at least 3 notes (2 on testPageId, 1 on otherPageId, assuming they are not internal)
        $this->assertGreaterThanOrEqual(3, $response['data']['pagination']['total_items'], "Should fetch at least the three notes created for this test.");
        $this->assertGreaterThanOrEqual(3, count($response['data']['data']));


        $foundNoteA = false;
        $foundNoteB = false;
        $foundNoteC = false;

        foreach ($response['data']['data'] as $note) { // Notes are in data.data
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


        // Case 1: include_internal=false (default or '0')
        $responseFalse = $this->request('GET', '/v1/api/notes.php', ['include_internal' => '0', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseFalse['status']);
        $this->assertArrayHasKey('data', $responseFalse['data']['data']); // notes are in data.data
        
        $foundPublic = null;
        $foundInternal = false; // Initialize to false to check they are NOT found
        $foundInternalWithAttachment = false;

        foreach($responseFalse['data']['data'] as $note) {
            $this->assertArrayHasKey('has_attachments', $note, "Each note in getAllNotes (include_internal=false) should have 'has_attachments'.");
            if ($note['id'] == $publicNoteId) {
                $foundPublic = $note;
            }
            if ($note['id'] == $internalNoteId) {
                $foundInternal = true; 
            }
            if ($note['id'] == $internalNoteWithAttachmentId) {
                $foundInternalWithAttachment = true;
            }
        }
        $this->assertNotNull($foundPublic, "Public note should be present.");
        $this->assertEquals(1, $foundPublic['has_attachments'], "Public note with attachment should have has_attachments = 1.");
        $this->assertFalse($foundInternal, "Internal note (no attachment) should NOT be present when include_internal=false.");
        $this->assertFalse($foundInternalWithAttachment, "Internal note (with attachment) should NOT be present when include_internal=false.");
        $this->assertEquals(1, $responseFalse['data']['pagination']['total_items']); // Only one public note


        // Case 2: include_internal=true ('1')
        $responseTrue = $this->request('GET', '/v1/api/notes.php', ['include_internal' => '1', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseTrue['status']);
        $this->assertArrayHasKey('data', $responseTrue['data']['data']); // notes are in data.data
        $this->assertEquals(3, $responseTrue['data']['pagination']['total_items']); // All three notes

        $foundPublic = null;
        $foundInternal = null;
        $foundInternalWithAttachment = null;

        foreach($responseTrue['data']['data'] as $note) {
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

    // --- Batch Operation Tests ---

    public function testBatchEmptyOperationsArray()
    {
        $payload = [
            'action' => 'batch',
            'operations' => []
        ];
        // Assuming makeApiRequest is available from BaseTestCase and handles JSON encoding
        // And that it directly calls the script, so the path might be relative or absolute
        // For now, using the path from existing tests.
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('results', $response['data']);
        $this->assertEmpty($response['data']['results']);
    }

    public function testBatchSuccessfulCreateOperations()
    {
        $pageId = self::$testPageId;
        $operations = [
            [
                'type' => 'create',
                'payload' => ['page_id' => $pageId, 'content' => 'Batch Create Note 1', 'client_temp_id' => 'temp:001']
            ],
            [
                'type' => 'create',
                'payload' => ['page_id' => $pageId, 'content' => 'Batch Create Note 2', 'client_temp_id' => 'temp:002']
            ]
        ];
        $batchPayload = ['action' => 'batch', 'operations' => $operations];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));

        $this->assertEquals('success', $response['status']);
        $this->assertCount(2, $response['data']['results']);

        $result1 = $response['data']['results'][0];
        $this->assertEquals('create', $result1['type']);
        $this->assertEquals('success', $result1['status']);
        $this->assertEquals('temp:001', $result1['client_temp_id']);
        $this->assertArrayHasKey('note', $result1);
        $this->assertEquals('Batch Create Note 1', $result1['note']['content']);
        $note1Id = $result1['note']['id'];
        $this->assertNotNull($note1Id);

        $dbNote1 = $this->_getNoteById($note1Id);
        $this->assertNotNull($dbNote1);
        $this->assertEquals('Batch Create Note 1', $dbNote1['content']);
        $this->assertEquals($pageId, $dbNote1['page_id']);

        $result2 = $response['data']['results'][1];
        $this->assertEquals('create', $result2['type']);
        $this->assertEquals('success', $result2['status']);
        $this->assertEquals('temp:002', $result2['client_temp_id']);
        $note2Id = $result2['note']['id'];
        $dbNote2 = $this->_getNoteById($note2Id);
        $this->assertNotNull($dbNote2);
        $this->assertEquals('Batch Create Note 2', $dbNote2['content']);
    }

    public function testBatchSuccessfulUpdateOperations()
    {
        $pageId = self::$testPageId;
        $note1Id = $this->createNoteDirectly('Original Content 1', $pageId);
        $note2Id = $this->createNoteDirectly('Original Content 2', $pageId);

        $operations = [
            ['type' => 'update', 'payload' => ['id' => $note1Id, 'content' => 'Updated Content 1']],
            ['type' => 'update', 'payload' => ['id' => $note2Id, 'content' => 'Updated Content 2', 'collapsed' => 1]]
        ];
        $batchPayload = ['action' => 'batch', 'operations' => $operations];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));

        $this->assertEquals('success', $response['status']);
        $this->assertCount(2, $response['data']['results']);

        $result1 = $response['data']['results'][0];
        $this->assertEquals('update', $result1['type']);
        $this->assertEquals('success', $result1['status']);
        $this->assertEquals('Updated Content 1', $result1['note']['content']);
        $this->assertEquals($note1Id, $result1['note']['id']);

        $dbNote1 = $this->_getNoteById($note1Id);
        $this->assertEquals('Updated Content 1', $dbNote1['content']);

        $result2 = $response['data']['results'][1];
        $this->assertEquals('update', $result2['type']);
        $this->assertEquals('success', $result2['status']);
        $this->assertEquals('Updated Content 2', $result2['note']['content']);
        $this->assertEquals(1, $result2['note']['collapsed']);
        $dbNote2 = $this->_getNoteById($note2Id);
        $this->assertEquals('Updated Content 2', $dbNote2['content']);
        $this->assertEquals(1, $dbNote2['collapsed']);
    }

    public function testBatchSuccessfulDeleteOperations()
    {
        $pageId = self::$testPageId;
        $note1Id = $this->createNoteDirectly('To Delete 1', $pageId);
        $note2Id = $this->createNoteDirectly('To Delete 2', $pageId);

        $operations = [
            ['type' => 'delete', 'payload' => ['id' => $note1Id]],
            ['type' => 'delete', 'payload' => ['id' => $note2Id]]
        ];
        $batchPayload = ['action' => 'batch', 'operations' => $operations];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));

        $this->assertEquals('success', $response['status']);
        $this->assertCount(2, $response['data']['results']);

        $result1 = $response['data']['results'][0];
        $this->assertEquals('delete', $result1['type']);
        $this->assertEquals('success', $result1['status']);
        $this->assertEquals($note1Id, $result1['deleted_note_id']);
        $this->assertNull($this->_getNoteById($note1Id));

        $result2 = $response['data']['results'][1];
        $this->assertEquals('delete', $result2['type']);
        $this->assertEquals('success', $result2['status']);
        $this->assertEquals($note2Id, $result2['deleted_note_id']);
        $this->assertNull($this->_getNoteById($note2Id));
    }

    public function testBatchMixedSuccessOperationsAndResultOrder()
    {
        $pageId = self::$testPageId;
        // Note: Server IDs for created notes are only known after the batch call for this test design.
        // To delete Note B by its server ID in the same batch, we'd need to predict it or have the API allow temp ID for delete.
        // The current _deleteNoteInBatch resolves temp IDs, so this is possible.

        $operations = [
            ['type' => 'create', 'payload' => ['page_id' => $pageId, 'content' => 'Note A Original', 'client_temp_id' => 'tempA']], // Index 0
            ['type' => 'create', 'payload' => ['page_id' => $pageId, 'content' => 'Note B To Delete', 'client_temp_id' => 'tempB']], // Index 1
            ['type' => 'update', 'payload' => ['id' => 'tempA', 'content' => 'Note A Updated']], // Index 2
            ['type' => 'delete', 'payload' => ['id' => 'tempB']]  // Index 3
        ];
        $batchPayload = ['action' => 'batch', 'operations' => $operations];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));

        $this->assertEquals('success', $response['status'], "Batch failed: " . print_r($response, true));
        $this->assertCount(4, $response['data']['results']);

        // Result for Op 0 (Create A)
        $resCreateA = $response['data']['results'][0];
        $this->assertEquals('create', $resCreateA['type']);
        $this->assertEquals('success', $resCreateA['status']);
        $this->assertEquals('tempA', $resCreateA['client_temp_id']);
        $noteAId = $resCreateA['note']['id'];
        $this->assertNotNull($noteAId);

        // Result for Op 1 (Create B)
        $resCreateB = $response['data']['results'][1];
        $this->assertEquals('create', $resCreateB['type']);
        $this->assertEquals('success', $resCreateB['status']);
        $this->assertEquals('tempB', $resCreateB['client_temp_id']);
        $noteBId = $resCreateB['note']['id'];
        $this->assertNotNull($noteBId);
        
        // Result for Op 2 (Update A)
        $resUpdateA = $response['data']['results'][2];
        $this->assertEquals('update', $resUpdateA['type']);
        $this->assertEquals('success', $resUpdateA['status']);
        $this->assertEquals($noteAId, $resUpdateA['note']['id']);
        $this->assertEquals('Note A Updated', $resUpdateA['note']['content']);

        // Result for Op 3 (Delete B)
        $resDeleteB = $response['data']['results'][3];
        $this->assertEquals('delete', $resDeleteB['type']);
        $this->assertEquals('success', $resDeleteB['status']);
        $this->assertEquals($noteBId, $resDeleteB['deleted_note_id']);

        // Verify DB state
        $dbNoteA = $this->_getNoteById($noteAId);
        $this->assertNotNull($dbNoteA);
        $this->assertEquals('Note A Updated', $dbNoteA['content']);
        $this->assertNull($this->_getNoteById($noteBId), "Note B should be deleted.");
    }

    public function testBatchAtomicityRollbackOnFailure()
    {
        $pageId = self::$testPageId;
        $operations = [
            ['type' => 'create', 'payload' => ['page_id' => $pageId, 'content' => 'Note X (should rollback)', 'client_temp_id' => 'tempX']], // Index 0
            ['type' => 'update', 'payload' => ['id' => 99999, 'content' => 'Update Non-Existent Note Y']], // Index 1 (This will fail)
            ['type' => 'create', 'payload' => ['page_id' => $pageId, 'content' => 'Note Z (should rollback)', 'client_temp_id' => 'tempZ']]  // Index 2
        ];
        $batchPayload = ['action' => 'batch', 'operations' => $operations];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));
        
        $this->assertEquals('error', $response['status']); // HTTP 400
        $this->assertArrayHasKey('details', $response);
        $this->assertArrayHasKey('failed_operations', $response['details']);
        $this->assertCount(1, $response['details']['failed_operations']);
        
        $failedOp = $response['details']['failed_operations'][0];
        $this->assertEquals(1, $failedOp['index']); // Failure was at original index 1
        $this->assertEquals('update', $failedOp['type']);
        $this->assertEquals(['id' => 99999], $failedOp['payload_identifier']);
        $this->assertStringContainsString('Note not found for update', $failedOp['error_message']); // Or similar from _updateNoteInBatch

        // Verify atomicity: Note X and Z should not exist
        // Since client_temp_ids are used, we can't easily query by ID if they were never created.
        // We can query by content if content is unique for the test, or ensure count of notes on page hasn't changed.
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM Notes WHERE page_id = :page_id AND (content LIKE '%(should rollback)%')");
        $stmt->execute([':page_id' => $pageId]);
        $this->assertEquals(0, $stmt->fetchColumn(), "Notes X and Z should have been rolled back.");
    }
    
    public function testBatchValidationFailureMissingType()
    {
        $batchPayload = ['action' => 'batch', 'operations' => [['payload' => ['page_id' => self::$testPageId]]]];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('details', $response);
        $this->assertArrayHasKey('validation_errors', $response['details']);
        $this->assertCount(1, $response['details']['validation_errors']);
        $this->assertEquals(0, $response['details']['validation_errors'][0]['index']);
        $this->assertStringContainsString('Missing or invalid type field', $response['details']['validation_errors'][0]['error']);
    }

    public function testBatchValidationFailureInvalidType()
    {
        $batchPayload = ['action' => 'batch', 'operations' => [['type' => 'unknown', 'payload' => ['page_id' => self::$testPageId]]]];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('validation_errors', $response['details']);
        $this->assertEquals(0, $response['details']['validation_errors'][0]['index']);
        $this->assertStringContainsString('Invalid operation type', $response['details']['validation_errors'][0]['error']);
    }

    public function testBatchValidationFailureMissingPayload()
    {
        $batchPayload = ['action' => 'batch', 'operations' => [['type' => 'create']]];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('validation_errors', $response['details']);
        $this->assertEquals(0, $response['details']['validation_errors'][0]['index']);
        $this->assertStringContainsString('Missing or invalid payload field', $response['details']['validation_errors'][0]['error']);
    }

    public function testBatchValidationFailureCreateMissingPageId()
    {
        $batchPayload = ['action' => 'batch', 'operations' => [['type' => 'create', 'payload' => ['content' => 'test']]]];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('validation_errors', $response['details']);
        $this->assertEquals(0, $response['details']['validation_errors'][0]['index']);
        $this->assertEquals('create', $response['details']['validation_errors'][0]['type']);
        $this->assertStringContainsString('Missing page_id in payload', $response['details']['validation_errors'][0]['error']);
    }
    
    public function testBatchOperationFailureDeleteNoteWithChildren()
    {
        $pageId = self::$testPageId;
        $parentId = $this->createNoteDirectly('Parent Note for Delete Test', $pageId);
        $childId = $this->createNoteDirectly('Child Note for Delete Test', $pageId, [], $parentId);

        $operations = [
            ['type' => 'delete', 'payload' => ['id' => $parentId]]
        ];
        $batchPayload = ['action' => 'batch', 'operations' => $operations];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));

        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('details', $response);
        $this->assertArrayHasKey('failed_operations', $response['details']);
        $this->assertCount(1, $response['details']['failed_operations']);
        $failedOp = $response['details']['failed_operations'][0];
        $this->assertEquals(0, $failedOp['index']);
        $this->assertEquals('delete', $failedOp['type']);
        $this->assertStringContainsString('Note has child notes', $failedOp['error_message']);

        // Verify notes still exist due to rollback
        $this->assertNotNull($this->_getNoteById($parentId));
        $this->assertNotNull($this->_getNoteById($childId));
    }

    public function testBatchCreateAndUpdateWithTempId()
    {
        $pageId = self::$testPageId;
        $operations = [
            ['type' => 'create', 'payload' => ['page_id' => $pageId, 'content' => 'Initial Content', 'client_temp_id' => 'temp:xyz123']],
            ['type' => 'update', 'payload' => ['id' => 'temp:xyz123', 'content' => 'Updated Content via Temp ID']]
        ];
        $batchPayload = ['action' => 'batch', 'operations' => $operations];
        $response = $this->request('POST', '/v1/api/notes.php', [], [], json_encode($batchPayload));

        $this->assertEquals('success', $response['status'], "Batch response indicates failure: " . print_r($response, true));
        $this->assertCount(2, $response['data']['results']);

        $createResult = $response['data']['results'][0];
        $this->assertEquals('create', $createResult['type']);
        $this->assertEquals('success', $createResult['status']);
        $this->assertEquals('temp:xyz123', $createResult['client_temp_id']);
        $this->assertNotNull($createResult['note']['id']);
        $createdNoteId = $createResult['note']['id'];

        $updateResult = $response['data']['results'][1];
        $this->assertEquals('update', $updateResult['type']);
        $this->assertEquals('success', $updateResult['status']);
        $this->assertEquals($createdNoteId, $updateResult['note']['id']);
        $this->assertEquals('Updated Content via Temp ID', $updateResult['note']['content']);

        // Verify DB state
        $dbNote = $this->_getNoteById($createdNoteId);
        $this->assertNotNull($dbNote);
        $this->assertEquals('Updated Content via Temp ID', $dbNote['content']);
    }
}
?>
