<?php
use PHPUnit\Framework\TestCase;

// Adjust the path as necessary if BaseTestCase is in a different directory
require_once __DIR__ . '/../BaseTestCase.php'; 

class AppendToPageApiTest extends BaseTestCase
{
    public function setUp(): void
    {
        parent::setUp(); // This likely handles DB setup, etc.
        // Potentially clear relevant tables before each test or ensure clean state
        $this->clearPagesAndNotes();
    }

    protected function clearPagesAndNotes()
    {
        $this->pdo->exec("DELETE FROM Properties WHERE note_id IN (SELECT id FROM Notes)");
        $this->pdo->exec("DELETE FROM Properties WHERE page_id IN (SELECT id FROM Pages)");
        $this->pdo->exec("DELETE FROM Notes");
        $this->pdo->exec("DELETE FROM Pages");
    }

    // Helper to make API requests to the endpoint
    protected function makeAppendRequest(array $data)
    {
        // This assumes a method in BaseTestCase or a direct way to simulate POST requests
        // to your API script. You might need to adapt this based on your test setup.
        // For example, it might involve using Guzzle or a custom HTTP client simulator.
        // For this example, let's assume a helper `postRequest` exists.
        return $this->postRequest('/api/v1/append_to_page.php', $data);
    }

    // Test Cases:

    public function testAppendSingleNoteToNewPage()
    {
        $pageName = 'TestNewPageForSingleNote';
        $noteContent = 'This is a single note. Property::Value';
        
        $response = $this->makeAppendRequest([
            'page_name' => $pageName,
            'notes' => $noteContent
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('page', $data);
        $this->assertEquals($pageName, $data['page']['name']);
        $this->assertArrayHasKey('appended_notes', $data);
        $this->assertCount(1, $data['appended_notes']);
        $this->assertEquals($noteContent, $data['appended_notes'][0]['content']);
        $this->assertArrayHasKey('Property', $data['appended_notes'][0]['properties']);
        $this->assertEquals('Value', $data['appended_notes'][0]['properties']['Property']['value'] ?? $data['appended_notes'][0]['properties']['Property']);


        // Verify DB state
        $pageStmt = $this->pdo->prepare("SELECT * FROM Pages WHERE name = ?");
        $pageStmt->execute([$pageName]);
        $pageDb = $pageStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($pageDb);

        $noteStmt = $this->pdo->prepare("SELECT * FROM Notes WHERE page_id = ?");
        $noteStmt->execute([$pageDb['id']]);
        $noteDb = $noteStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($noteDb);
        $this->assertEquals($noteContent, $noteDb['content']);
        
        $propStmt = $this->pdo->prepare("SELECT * FROM Properties WHERE note_id = ? AND name = 'Property'");
        $propStmt->execute([$noteDb['id']]);
        $propDb = $propStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($propDb);
        $this->assertEquals('Value', $propDb['value']);
    }

    public function testAppendSingleNoteToExistingPage()
    {
        // 1. Create an existing page
        $existingPageName = 'ExistingPageForSingleNote';
        $pageInsertStmt = $this->pdo->prepare("INSERT INTO Pages (name) VALUES (?)");
        $pageInsertStmt->execute([$existingPageName]);
        $pageId = $this->pdo->lastInsertId();

        $noteContent = 'Another single note for an existing page. Color::Blue';
        
        $response = $this->makeAppendRequest([
            'page_name' => $existingPageName,
            'notes' => $noteContent
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertEquals($pageId, $data['page']['id']);
        $this->assertCount(1, $data['appended_notes']);
        $this->assertEquals($noteContent, $data['appended_notes'][0]['content']);
        $this->assertEquals('Blue', $data['appended_notes'][0]['properties']['Color']['value'] ?? $data['appended_notes'][0]['properties']['Color']);
    }
    
    public function testAppendMultipleNotesToNewPageWithHierarchy()
    {
        $pageName = 'NewPageWithHierarchy';
        $notesPayload = [
            ['client_temp_id' => 'temp-parent', 'content' => 'Parent Note Content. ParentProp::ParentValue'],
            ['parent_note_id' => 'temp-parent', 'client_temp_id' => 'temp-child', 'content' => 'Child Note Content. ChildProp::ChildValue', 'order_index' => 1]
        ];

        $response = $this->makeAppendRequest([
            'page_name' => $pageName,
            'notes' => $notesPayload
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertEquals($pageName, $data['page']['name']);
        $this->assertCount(2, $data['appended_notes']);

        $parentNoteResp = null;
        $childNoteResp = null;

        foreach($data['appended_notes'] as $note) {
            if(strpos($note['content'], 'Parent Note Content') !== false) $parentNoteResp = $note;
            if(strpos($note['content'], 'Child Note Content') !== false) $childNoteResp = $note;
        }

        $this->assertNotNull($parentNoteResp);
        $this->assertNotNull($childNoteResp);
        
        $this->assertNull($parentNoteResp['parent_note_id']);
        $this->assertEquals($parentNoteResp['id'], $childNoteResp['parent_note_id']);
        $this->assertEquals('ParentValue', $parentNoteResp['properties']['ParentProp']['value'] ?? $parentNoteResp['properties']['ParentProp']);
        $this->assertEquals('ChildValue', $childNoteResp['properties']['ChildProp']['value'] ?? $childNoteResp['properties']['ChildProp']);
        $this->assertEquals(1, $childNoteResp['order_index']);

        // Verify DB
        $pageId = $data['page']['id'];
        $parentDbStmt = $this->pdo->prepare("SELECT * FROM Notes WHERE page_id = ? AND content LIKE '%Parent Note Content%'");
        $parentDbStmt->execute([$pageId]);
        $parentDb = $parentDbStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($parentDb);

        $childDbStmt = $this->pdo->prepare("SELECT * FROM Notes WHERE page_id = ? AND parent_note_id = ? AND content LIKE '%Child Note Content%'");
        $childDbStmt->execute([$pageId, $parentDb['id']]);
        $childDb = $childDbStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($childDb);
        $this->assertEquals(1, $childDb['order_index']);
    }

    public function testCreateJournalPageAndAppendNote()
    {
        $journalPageName = date('Y-m-d'); // Today's date as journal page name
        $noteContent = 'Journal entry for today.';

        $response = $this->makeAppendRequest([
            'page_name' => $journalPageName,
            'notes' => $noteContent
        ]);
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertEquals($journalPageName, $data['page']['name']);
        $this->assertCount(1, $data['appended_notes']);
        
        // Verify journal property on page
        $pageId = $data['page']['id'];
        $propStmt = $this->pdo->prepare("SELECT * FROM Properties WHERE page_id = ? AND name = 'type' AND value = 'journal'");
        $propStmt->execute([$pageId]);
        $journalPropDb = $propStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($journalPropDb, "Journal property 'type: journal' should exist for the page.");
    }

    public function testValidationErrorMissingPageName()
    {
        $response = $this->makeAppendRequest(['notes' => 'some content']);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString('page_name is required', $data['error']['message']);
    }

    public function testValidationErrorMissingNotes()
    {
        $response = $this->makeAppendRequest(['page_name' => 'TestPage']);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString('notes field is required', $data['error']['message']);
    }
    
    public function testValidationErrorInvalidNoteItemContent()
    {
        $pageName = 'PageForInvalidNote';
        $notesPayload = [
            ['client_temp_id' => 'valid-note', 'content' => 'Valid content'],
            ['client_temp_id' => 'invalid-note'] // Missing content
        ];

        $response = $this->makeAppendRequest([
            'page_name' => $pageName,
            'notes' => $notesPayload
        ]);
        
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString("must have a 'content' field", $data['error']['message']);
        $this->assertStringContainsString("'invalid-note'", $data['error']['message']);
    }
    
    public function testValidationErrorInvalidOrderIndex()
    {
        $pageName = 'PageForInvalidOrderIndex';
        $notesPayload = [
            ['content' => 'Note with bad order index', 'order_index' => 'not-an-integer']
        ];
        $response = $this->makeAppendRequest([
            'page_name' => $pageName,
            'notes' => $notesPayload
        ]);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString("invalid 'order_index'", $data['error']['message']);
    }
    
    public function testInternalNoteFlag()
    {
        $pageName = 'PageForInternalNote';
        $noteContent = "This note should be internal.
internal::true";
        
        $response = $this->makeAppendRequest([
            'page_name' => $pageName,
            'notes' => $noteContent
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertCount(1, $data['appended_notes']);
        $noteId = $data['appended_notes'][0]['id'];

        // Check Notes.internal flag in DB
        $noteDbStmt = $this->pdo->prepare("SELECT internal FROM Notes WHERE id = ?");
        $noteDbStmt->execute([$noteId]);
        $noteDb = $noteDbStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(1, $noteDb['internal']);
    }

     public function testNotePropertyWithTripleColonInternalSyntax() {
        $pageName = 'TestTripleColonInternal';
        $noteContent = "{internal_prop:::secret_value}
visible_prop::normal_value";
        
        $response = $this->makeAppendRequest([
            'page_name' => $pageName,
            'notes' => $noteContent
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertCount(1, $data['appended_notes']);
        $noteProperties = $data['appended_notes'][0]['properties'];

        $this->assertArrayHasKey('internal_prop', $noteProperties);
        $this->assertEquals('secret_value', $noteProperties['internal_prop'][0]['value']);
        $this->assertEquals(1, $noteProperties['internal_prop'][0]['internal']);
        
        $this->assertArrayHasKey('visible_prop', $noteProperties);
        // Adjust assertion based on how _formatProperties handles single non-internal values
        $visiblePropValue = $noteProperties['visible_prop'];
        if(is_array($visiblePropValue) && isset($visiblePropValue[0]['value'])) {
             $this->assertEquals('normal_value', $visiblePropValue[0]['value']);
             $this->assertEquals(0, $visiblePropValue[0]['internal']);
        } else {
             $this->assertEquals('normal_value', $visiblePropValue); // If simplified
        }


        // Verify in DB
        $noteId = $data['appended_notes'][0]['id'];
        $internalPropDB = $this->pdo->prepare("SELECT value, internal FROM Properties WHERE note_id = ? AND name = 'internal_prop'");
        $internalPropDB->execute([$noteId]);
        $internalPropData = $internalPropDB->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('secret_value', $internalPropData['value']);
        $this->assertEquals(1, $internalPropData['internal']);

        $visiblePropDB = $this->pdo->prepare("SELECT value, internal FROM Properties WHERE note_id = ? AND name = 'visible_prop'");
        $visiblePropDB->execute([$noteId]);
        $visiblePropData = $visiblePropDB->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('normal_value', $visiblePropData['value']);
        $this->assertEquals(0, $visiblePropData['internal']);
    }
}
