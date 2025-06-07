<?php
// tests/Api/PagesApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO;

class PagesApiTest extends BaseTestCase
{
    // No specific setUp needed beyond BaseTestCase's, as each test will create pages as required.

    protected function tearDown(): void
    {
        // Clean up: It's good practice, though in-memory DB is wiped.
        // Order: Properties, Notes, then Pages due to potential foreign keys.
        if (self::$pdo) {
            // Delete properties associated with notes of pages created by tests (if any specific pattern)
            // Delete properties directly associated with pages created by tests
            // Delete notes associated with pages created by tests
            // Delete pages created by tests
            // For simplicity, if test names are unique, we can try to delete by name,
            // otherwise, we rely on the in-memory DB reset.
            // Example: self::$pdo->exec("DELETE FROM Pages WHERE name LIKE 'Test Page%' OR name LIKE '2023-%'");
            // For now, relying on BaseTestCase::tearDown and in-memory nature.
        }
        parent::tearDown();
    }

    // Helper to create a page directly for setup
    private function createPageDirectly(string $name, ?string $alias = null): int
    {
        $sql = "INSERT INTO Pages (name, alias) VALUES (:name, :alias)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([':name' => $name, ':alias' => $alias]);
        return self::$pdo->lastInsertId();
    }

    // Helper to add a property to a page
    private function addPageProperty(int $pageId, string $name, string $value)
    {
        $stmt = self::$pdo->prepare("INSERT INTO Properties (page_id, name, value) VALUES (:page_id, :name, :value)");
        $stmt->execute([':page_id' => $pageId, ':name' => $name, ':value' => $value]);
    }
    
    // Helper to create a note on a page
    private function createNoteOnPage(int $pageId, string $content) : int
    {
        $stmt = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmt->execute(['page_id' => $pageId, 'content' => $content]);
        return self::$pdo->lastInsertId();
    }


    // --- Test POST /api/pages.php (Create Page) ---

    public function testPostCreatePageNameOnly()
    {
        $data = ['name' => 'Test Page Alpha'];
        $response = $this->request('POST', 'api/pages.php', $data);

        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('data', $response);
        $pageData = $response['data'];
        $this->assertEquals('Test Page Alpha', $pageData['name']);
        $this->assertArrayHasKey('id', $pageData);

        $stmt = self::$pdo->prepare("SELECT * FROM Pages WHERE id = :id");
        $stmt->execute([':id' => $pageData['id']]);
        $this->assertNotEmpty($stmt->fetch());
    }

    public function testPostCreatePageNameAndAlias()
    {
        $data = ['name' => 'Test Page Beta', 'alias' => 'tpb'];
        $response = $this->request('POST', 'api/pages.php', $data);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Test Page Beta', $response['data']['name']);
        $this->assertEquals('tpb', $response['data']['alias']);
        $newPageId = $response['data']['id'];

        $stmt = self::$pdo->prepare("SELECT alias FROM Pages WHERE id = :id");
        $stmt->execute([':id' => $newPageId]);
        $this->assertEquals('tpb', $stmt->fetchColumn());
    }

    public function testPostCreateJournalPageAutoProperty()
    {
        $journalName = date('Y-m-d'); // e.g., "2023-10-26"
        $data = ['name' => $journalName];
        $response = $this->request('POST', 'api/pages.php', $data);

        $this->assertEquals('success', $response['status']);
        $pageData = $response['data'];
        $this->assertEquals($journalName, $pageData['name']);
        $pageId = $pageData['id'];

        // Verify 'type::journal' property
        // The API currently returns the page details, not properties on POST.
        // So we need to query the DB or use GET page by ID with details.
        $stmt = self::$pdo->prepare("SELECT value FROM Properties WHERE page_id = :page_id AND name = 'type'");
        $stmt->execute([':page_id' => $pageId]);
        $this->assertEquals('journal', $stmt->fetchColumn());
    }

    public function testPostCreatePageDuplicateNameReturnsExisting()
    {
        $this->createPageDirectly('Existing Page');
        $data = ['name' => 'Existing Page'];
        $response = $this->request('POST', 'api/pages.php', $data);

        $this->assertEquals('success', $response['status']); // Current API returns existing page
        $this->assertEquals('Existing Page', $response['data']['name']);
        // Could assert ID matches the originally created page if we fetch it.
    }

    public function testPostCreatePageFailureMissingName()
    {
        $response = $this->request('POST', 'api/pages.php', ['alias' => 'some-alias']);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input for creating page', $response['message']);
        $this->assertArrayHasKey('name', $response['details']); // Validator specific detail
    }

    // --- Test GET /api/pages.php?id={id} ---

    public function testGetPageByIdSuccess()
    {
        $pageId = $this->createPageDirectly('Page For ID Test');
        $response = $this->request('GET', 'api/pages.php', ['id' => $pageId]);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals($pageId, $response['data']['id']);
        $this->assertEquals('Page For ID Test', $response['data']['name']);
    }

    public function testGetPageByIdWithDetails()
    {
        $pageId = $this->createPageDirectly('Page With Details');
        $this->addPageProperty($pageId, 'category', 'testing');
        $this->createNoteOnPage($pageId, 'Note 1 on page');
        $this->createNoteOnPage($pageId, 'Note 2 on page');

        $response = $this->request('GET', 'api/pages.php', ['id' => $pageId, 'include_details' => '1']);

        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('page', $response['data']);
        $this->assertEquals($pageId, $response['data']['page']['id']);
        $this->assertArrayHasKey('properties', $response['data']['page']);
        $this->assertEquals('testing', $response['data']['page']['properties']['category']);
        $this->assertArrayHasKey('notes', $response['data']);
        $this->assertCount(2, $response['data']['notes']);
        $this->assertEquals('Note 1 on page', $response['data']['notes'][0]['content']);
    }
    
    public function testGetPageByIdWithAliasHandling()
    {
        $pageB_Id = $this->createPageDirectly('Page B Target');
        $pageA_Id = $this->createPageDirectly('Page A Source', 'Page B Target'); // Page A aliases Page B

        // follow_aliases=true (default)
        $responseFollow = $this->request('GET', 'api/pages.php', ['id' => $pageA_Id]);
        $this->assertEquals('success', $responseFollow['status']);
        $this->assertEquals($pageB_Id, $responseFollow['data']['id'], "Should return Page B (aliased target) when following aliases.");
        $this->assertEquals('Page B Target', $responseFollow['data']['name']);
        
        // follow_aliases=0
        $responseNoFollow = $this->request('GET', 'api/pages.php', ['id' => $pageA_Id, 'follow_aliases' => '0']);
        $this->assertEquals('success', $responseNoFollow['status']);
        $this->assertEquals($pageA_Id, $responseNoFollow['data']['id'], "Should return Page A (source) when not following aliases.");
        $this->assertEquals('Page A Source', $responseNoFollow['data']['name']);
    }


    public function testGetPageByIdFailureNotFound()
    {
        $response = $this->request('GET', 'api/pages.php', ['id' => 99999]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }

    // --- Test GET /api/pages.php?name={name} ---

    public function testGetPageByNameSuccess()
    {
        $this->createPageDirectly('Page For Name Test');
        $response = $this->request('GET', 'api/pages.php', ['name' => 'Page For Name Test']);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Page For Name Test', $response['data']['name']);
    }

    public function testGetPageByNameAutoCreateJournal()
    {
        $journalDate = date('Y-m-d', strtotime('+1 day')); // Future date to ensure it doesn't exist
        $response = $this->request('GET', 'api/pages.php', ['name' => $journalDate]);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals($journalDate, $response['data']['name']);
        $pageId = $response['data']['id'];

        $stmt = self::$pdo->prepare("SELECT value FROM Properties WHERE page_id = :page_id AND name = 'type'");
        $stmt->execute([':page_id' => $pageId]);
        $this->assertEquals('journal', $stmt->fetchColumn());
    }
    
    public function testGetPageByNameWithAliasHandling()
    {
        $this->createPageDirectly('PageY-TargetName');
        $this->createPageDirectly('PageX-SourceName', 'PageY-TargetName');

        // follow_aliases=true (default)
        $responseFollow = $this->request('GET', 'api/pages.php', ['name' => 'PageX-SourceName']);
        $this->assertEquals('success', $responseFollow['status']);
        $this->assertEquals('PageY-TargetName', $responseFollow['data']['name']);
        
        // follow_aliases=0
        $responseNoFollow = $this->request('GET', 'api/pages.php', ['name' => 'PageX-SourceName', 'follow_aliases' => '0']);
        $this->assertEquals('success', $responseNoFollow['status']);
        $this->assertEquals('PageX-SourceName', $responseNoFollow['data']['name']);
    }

    public function testGetPageByNameFailureNotFoundNonJournal()
    {
        $response = $this->request('GET', 'api/pages.php', ['name' => 'NonExistent Page XYZ']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }


    // --- Test GET /api/pages.php (Get All Pages) ---
    public function testGetAllPagesSuccess()
    {
        self::$pdo->exec("DELETE FROM Pages"); // Clean slate for this test
        $this->createPageDirectly('Page All Z');
        $this->createPageDirectly('Page All Y');
        
        $response = $this->request('GET', 'api/pages.php');
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertCount(2, $response['data']);
        // Order is by updated_at DESC, name ASC. So Y should be before Z if created closely.
        // Or if same timestamp, Y before Z.
    }

    public function testGetAllPagesExcludeJournal()
    {
        self::$pdo->exec("DELETE FROM Pages");
        self::$pdo->exec("DELETE FROM Properties WHERE name='type' AND value='journal'");

        $this->createPageDirectly('Regular Page 1');
        $journalPageId = $this->createPageDirectly(date('Y-m-d', strtotime('-1 day'))); // Journal page
        $this->addPageProperty($journalPageId, 'type', 'journal');


        $response = $this->request('GET', 'api/pages.php', ['exclude_journal' => '1']);
        $this->assertEquals('success', $response['status']);
        $this->assertCount(1, $response['data']);
        $this->assertEquals('Regular Page 1', $response['data'][0]['name']);
    }
    
    public function testGetAllPagesWithDetails()
    {
        self::$pdo->exec("DELETE FROM Pages"); // Clean slate
        $pageId1 = $this->createPageDirectly('Detail Page 1');
        $this->createNoteOnPage($pageId1, 'Note on Detail Page 1');
        $this->addPageProperty($pageId1, 'status', 'draft');

        $response = $this->request('GET', 'api/pages.php', ['include_details' => '1']);
        $this->assertEquals('success', $response['status']);
        $this->assertCount(1, $response['data']);
        $pageData = $response['data'][0];
        $this->assertArrayHasKey('page', $pageData);
        $this->assertEquals('Detail Page 1', $pageData['page']['name']);
        $this->assertArrayHasKey('notes', $pageData);
        $this->assertCount(1, $pageData['notes']);
        $this->assertEquals('Note on Detail Page 1', $pageData['notes'][0]['content']);
        $this->assertArrayHasKey('properties', $pageData['page']);
        $this->assertEquals('draft', $pageData['page']['properties']['status']);
    }
    
    public function testGetAllPagesWithAliasesFollowed()
    {
        self::$pdo->exec("DELETE FROM Pages");
        $pageA_id = $this->createPageDirectly("PageA_Unique");
        $this->createPageDirectly("PageB_Unique");
        $this->createPageDirectly("PageC_AliasingA", "PageA_Unique");

        $response = $this->request('GET', 'api/pages.php', ['follow_aliases' => '1']);
        $this->assertEquals('success', $response['status']);
        
        $pageNames = array_column($response['data'], 'name');
        // Expected: PageA_Unique, PageB_Unique. PageC_AliasingA resolved to PageA_Unique.
        // The API should return unique pages after alias resolution.
        $this->assertCount(2, $pageNames, "Should return 2 unique pages after alias resolution.");
        $this->assertContains("PageA_Unique", $pageNames);
        $this->assertContains("PageB_Unique", $pageNames);
        $this->assertNotContains("PageC_AliasingA", $pageNames, "Aliased page name should not appear directly.");
    }


    // --- Test PUT /api/pages.php?id={id} ---
    public function testPutUpdatePageName()
    {
        $pageId = $this->createPageDirectly('Original Name');
        $response = $this->request('POST', "api/pages.php?id={$pageId}", ['name' => 'Updated Name', '_method' => 'PUT']);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Updated Name', $response['data']['name']);
        
        $stmt = self::$pdo->prepare("SELECT name FROM Pages WHERE id = :id");
        $stmt->execute([':id' => $pageId]);
        $this->assertEquals('Updated Name', $stmt->fetchColumn());
    }
    
    public function testPutUpdatePageAlias()
    {
        $pageId = $this->createPageDirectly('Page For Alias Update', 'old-alias');
        $response = $this->request('POST', "api/pages.php?id={$pageId}", ['alias' => 'new-alias', '_method' => 'PUT']);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('new-alias', $response['data']['alias']);
    }
    
    public function testPutUpdateNameToJournalPage()
    {
        $pageId = $this->createPageDirectly('MyOldPageName');
        $journalName = date('Y-m-d', strtotime('+2 days'));
        $response = $this->request('POST', "api/pages.php?id={$pageId}", ['name' => $journalName, '_method' => 'PUT']);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals($journalName, $response['data']['name']);

        $stmt = self::$pdo->prepare("SELECT value FROM Properties WHERE page_id = :page_id AND name = 'type'");
        $stmt->execute([':page_id' => $pageId]);
        $this->assertEquals('journal', $stmt->fetchColumn(), "type::journal property should be added.");
    }


    public function testPutUpdatePageFailureInvalidId()
    {
        $response = $this->request('POST', 'api/pages.php?id=88888', ['name' => 'No Such Page', '_method' => 'PUT']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }

    public function testPutUpdatePageFailureDuplicateName()
    {
        $this->createPageDirectly('Existing Name For Put');
        $pageIdToUpdate = $this->createPageDirectly('Page To Update Name');
        
        $response = $this->request('POST', "api/pages.php?id={$pageIdToUpdate}", ['name' => 'Existing Name For Put', '_method' => 'PUT']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page name already exists', $response['message']); // 409 Conflict
    }
    
    public function testPutUpdatePageFailureNoNameOrAlias()
    {
        $pageId = $this->createPageDirectly('Page For No Field Update');
        $response = $this->request('POST', "api/pages.php?id={$pageId}", ['_method' => 'PUT']); // No fields
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Either name or alias must be provided for update.', $response['message']);
    }


    // --- Test DELETE /api/pages.php?id={id} ---
    public function testDeletePageSuccess()
    {
        $pageId = $this->createPageDirectly('Page To Be Deleted');
        $noteId = $this->createNoteOnPage($pageId, 'Note on deleted page');
        $this->addPageProperty($pageId, 'status', 'to_delete');

        $response = $this->request('POST', 'api/pages.php?id=' . $pageId, ['_method' => 'DELETE']);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals($pageId, $response['data']['deleted_page_id']);

        // Verify page is removed
        $stmt = self::$pdo->prepare("SELECT * FROM Pages WHERE id = :id");
        $stmt->execute([':id' => $pageId]);
        $this->assertFalse($stmt->fetch());

        // Verify associated notes are removed (CASCADE delete in schema)
        $stmt = self::$pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteId]);
        $this->assertFalse($stmt->fetch(), "Notes on deleted page should be removed by CASCADE.");

        // Verify associated page properties are removed (CASCADE delete in schema)
        $stmt = self::$pdo->prepare("SELECT * FROM Properties WHERE page_id = :id");
        $stmt->execute([':id' => $pageId]);
        $this->assertFalse($stmt->fetch(), "Properties of deleted page should be removed by CASCADE.");
    }

    public function testDeletePageFailureInvalidId()
    {
        $response = $this->request('POST', 'api/pages.php?id=77777', ['_method' => 'DELETE']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }
}
?>
