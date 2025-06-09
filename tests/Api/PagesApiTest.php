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
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $pageData = $response['data'];
        $this->assertEquals('Test Page Alpha', $pageData['name']);
        $this->assertArrayHasKey('id', $pageData);

        $this->assertArrayHasKey('headers', $response, "Response should have headers array");
        $this->assertArrayHasKey('Location', $response['headers'], "Response should have Location header");
        $this->assertStringContainsString('/v1/api/pages.php?id=' . $pageData['id'], $response['headers']['Location']);

        $stmt = self::$pdo->prepare("SELECT * FROM Pages WHERE id = :id");
        $stmt->execute([':id' => $pageData['id']]);
        $this->assertNotEmpty($stmt->fetch());
    }

    public function testPostCreatePageNameAndAlias()
    {
        $data = ['name' => 'Test Page Beta', 'alias' => 'tpb'];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));

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
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $pageData = $response['data'];
        $this->assertEquals($journalName, $pageData['name']);
        $pageId = $pageData['id'];

        // If POST response includes properties (it should with new spec):
        // $this->assertArrayHasKey('type', $pageData['properties']);
        // $this->assertEquals('journal', $pageData['properties']['type'][0]['value']);
        // $this->assertEquals(0, $pageData['properties']['type'][0]['internal']);

        // For now, verify in DB as before, assuming POST might not return full props
        $stmt = self::$pdo->prepare("SELECT value FROM Properties WHERE page_id = :page_id AND name = 'type'");
        $stmt->execute([':page_id' => $pageId]);
        $this->assertEquals('journal', $stmt->fetchColumn());
    }

    public function testPostCreatePageDuplicateNameReturnsExisting()
    {
        $existingPage = $this->createPageDirectly('Existing Page');
        $data = ['name' => 'Existing Page'];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']); 
        $this->assertEquals('Existing Page', $response['data']['name']);
        $this->assertEquals($existingPage, $response['data']['id'], "Should return the ID of the existing page.");
    }

    public function testPostCreatePageFailureMissingName()
    {
        $data = ['alias' => 'some-alias'];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid input for creating page', $response['message']);
        $this->assertArrayHasKey('name', $response['details']); // Validator specific detail
    }

    // --- Test GET /api/pages.php?id={id} ---

    public function testGetPageByIdSuccess()
    {
        $pageId = $this->createPageDirectly('Page For ID Test');
        $response = $this->request('GET', '/v1/api/pages.php', ['id' => $pageId]);

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertEquals($pageId, $response['data']['id']);
        $this->assertEquals('Page For ID Test', $response['data']['name']);
    }

    public function testGetPageByIdWithDetails()
    {
        $pageId = $this->createPageDirectly('Page With Details');
        $this->addPageProperty($pageId, 'category', 'testing');
        $this->createNoteOnPage($pageId, 'Note 1 on page');
        $this->createNoteOnPage($pageId, 'Note 2 on page');

        $response = $this->request('GET', '/v1/api/pages.php', ['id' => $pageId, 'include_details' => '1']);

        $this->assertEquals('success', $response['status']);
        // Assuming 'include_details' might change the top-level structure or add fields.
        // If 'page' is still the main data container:
        $pageDetails = isset($response['data']['page']) ? $response['data']['page'] : $response['data'];
        
        $this->assertEquals($pageId, $pageDetails['id']);
        $this->assertArrayHasKey('properties', $pageDetails);
        $this->assertArrayHasKey('category', $pageDetails['properties']);
        $this->assertEquals('testing', $pageDetails['properties']['category'][0]['value']);
        $this->assertEquals(0, $pageDetails['properties']['category'][0]['internal']);

        // If notes are still under 'data.notes' alongside 'data.page'
        if (isset($response['data']['notes'])) {
            $this->assertArrayHasKey('notes', $response['data']);
            $this->assertCount(2, $response['data']['notes']);
            $this->assertEquals('Note 1 on page', $response['data']['notes'][0]['content']);
        } else if (isset($pageDetails['notes'])) { // Or if notes are nested under the page details
            $this->assertArrayHasKey('notes', $pageDetails);
            $this->assertCount(2, $pageDetails['notes']);
            $this->assertEquals('Note 1 on page', $pageDetails['notes'][0]['content']);
        } else {
            $this->fail("Notes structure not found in response with include_details=1");
        }
    }
    
    public function testGetPageByIdWithAliasHandling()
    {
        $pageB_Id = $this->createPageDirectly('Page B Target');
        $pageA_Id = $this->createPageDirectly('Page A Source', 'Page B Target'); // Page A aliases Page B

        // follow_aliases=true (default)
        $responseFollow = $this->request('GET', '/v1/api/pages.php', ['id' => $pageA_Id, 'follow_aliases' => '1']);
        $this->assertEquals('success', $responseFollow['status']);
        $this->assertEquals($pageB_Id, $responseFollow['data']['id'], "Should return Page B (aliased target) when following aliases.");
        $this->assertEquals('Page B Target', $responseFollow['data']['name']);
        
        // follow_aliases=0
        $responseNoFollow = $this->request('GET', '/v1/api/pages.php', ['id' => $pageA_Id, 'follow_aliases' => '0']);
        $this->assertEquals('success', $responseNoFollow['status']);
        $this->assertEquals($pageA_Id, $responseNoFollow['data']['id'], "Should return Page A (source) when not following aliases.");
        $this->assertEquals('Page A Source', $responseNoFollow['data']['name']);
    }


    public function testGetPageByIdFailureNotFound()
    {
        $response = $this->request('GET', '/v1/api/pages.php', ['id' => 99999]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }

    // --- Test GET /api/pages.php?name={name} ---

    public function testGetPageByNameSuccess()
    {
        $this->createPageDirectly('Page For Name Test');
        $response = $this->request('GET', '/v1/api/pages.php', ['name' => 'Page For Name Test']);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Page For Name Test', $response['data']['name']);
    }

    public function testGetPageByNameAutoCreateJournal()
    {
        $journalDate = date('Y-m-d', strtotime('+1 day')); // Future date to ensure it doesn't exist
        $response = $this->request('GET', '/v1/api/pages.php', ['name' => $journalDate]);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals($journalDate, $response['data']['name']);
        $pageId = $response['data']['id'];
        
        // If GET response includes properties:
        // $this->assertArrayHasKey('type', $response['data']['properties']);
        // $this->assertEquals('journal', $response['data']['properties']['type'][0]['value']);

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT value FROM Properties WHERE page_id = :page_id AND name = 'type'");
        $stmt->execute([':page_id' => $pageId]);
        $this->assertEquals('journal', $stmt->fetchColumn());
    }
    
    public function testGetPageByNameWithAliasHandling()
    {
        $this->createPageDirectly('PageY-TargetName');
        $this->createPageDirectly('PageX-SourceName', 'PageY-TargetName');

        // follow_aliases=true (default, but explicit here)
        $responseFollow = $this->request('GET', '/v1/api/pages.php', ['name' => 'PageX-SourceName', 'follow_aliases' => '1']);
        $this->assertEquals('success', $responseFollow['status']);
        $this->assertEquals('PageY-TargetName', $responseFollow['data']['name']);
        
        // follow_aliases=0
        $responseNoFollow = $this->request('GET', '/v1/api/pages.php', ['name' => 'PageX-SourceName', 'follow_aliases' => '0']);
        $this->assertEquals('success', $responseNoFollow['status']);
        $this->assertEquals('PageX-SourceName', $responseNoFollow['data']['name']);
    }

    public function testGetPageByNameFailureNotFoundNonJournal()
    {
        $response = $this->request('GET', '/v1/api/pages.php', ['name' => 'NonExistent Page XYZ']);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }


    // --- Test GET /api/pages.php (Get All Pages) ---
    public function testGetAllPagesSuccess()
    {
        self::$pdo->exec("DELETE FROM Pages"); // Clean slate for this test
        $this->createPageDirectly('Page All Z');
        $this->createPageDirectly('Page All Y');
        
        $response = $this->request('GET', '/v1/api/pages.php', ['page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('data', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertCount(2, $response['data']['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertEquals(2, $response['data']['pagination']['total_items']);
    }

    public function testGetAllPagesExcludeJournal()
    {
        self::$pdo->exec("DELETE FROM Pages");
        self::$pdo->exec("DELETE FROM Properties WHERE name='type' AND value='journal'");

        $this->createPageDirectly('Regular Page 1');
        $journalPageId = $this->createPageDirectly(date('Y-m-d', strtotime('-1 day'))); // Journal page
        $this->addPageProperty($journalPageId, 'type', 'journal');


        $response = $this->request('GET', '/v1/api/pages.php', ['exclude_journal' => '1', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertEquals('Regular Page 1', $response['data']['data'][0]['name']);
        $this->assertEquals(1, $response['data']['pagination']['total_items']);
    }
    
    public function testGetAllPagesWithDetails()
    {
        self::$pdo->exec("DELETE FROM Pages"); // Clean slate
        $pageId1 = $this->createPageDirectly('Detail Page 1');
        $this->createNoteOnPage($pageId1, 'Note on Detail Page 1');
        $this->addPageProperty($pageId1, 'status', 'draft');

        $response = $this->request('GET', '/v1/api/pages.php', ['include_details' => '1', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertCount(1, $response['data']['data']); // List of pages in data.data
        $pageContainer = $response['data']['data'][0]; // Each item in list is a container

        // The structure for "details" (like notes) might be nested.
        // Assuming the 'page' object is the primary part of $pageContainer, or $pageContainer itself is the page.
        $pageItself = isset($pageContainer['page']) ? $pageContainer['page'] : $pageContainer;

        $this->assertEquals('Detail Page 1', $pageItself['name']);
        $this->assertArrayHasKey('properties', $pageItself);
        $this->assertArrayHasKey('status', $pageItself['properties']);
        $this->assertEquals('draft', $pageItself['properties']['status'][0]['value']);
        $this->assertEquals(0, $pageItself['properties']['status'][0]['internal']);

        // Notes might be alongside 'page' or inside it, depending on API design for 'details'
        $notesList = null;
        if (isset($pageContainer['notes'])) {
            $notesList = $pageContainer['notes'];
        } elseif (isset($pageItself['notes'])) {
            $notesList = $pageItself['notes'];
        }
        $this->assertNotNull($notesList, "Notes list not found in detailed response");
        $this->assertCount(1, $notesList);
        $this->assertEquals('Note on Detail Page 1', $notesList[0]['content']);
    }
    
    public function testGetAllPagesWithAliasesFollowed()
    {
        self::$pdo->exec("DELETE FROM Pages");
        $pageA_id = $this->createPageDirectly("PageA_Unique");
        $this->createPageDirectly("PageB_Unique");
        $this->createPageDirectly("PageC_AliasingA", "PageA_Unique");

        $response = $this->request('GET', '/v1/api/pages.php', ['follow_aliases' => '1', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        
        $pageNames = array_column($response['data']['data'], 'name');
        // Expected: PageA_Unique, PageB_Unique. PageC_AliasingA resolved to PageA_Unique.
        // The API should return unique pages after alias resolution.
        $this->assertCount(2, $pageNames, "Should return 2 unique pages after alias resolution.");
        $this->assertContains("PageA_Unique", $pageNames);
        $this->assertContains("PageB_Unique", $pageNames);
        $this->assertNotContains("PageC_AliasingA", $pageNames, "Aliased page name should not appear directly.");
        $this->assertEquals(2, $response['data']['pagination']['total_items']);
    }


    // --- Test PUT /api/pages.php?id={id} ---
    public function testPutUpdatePageName()
    {
        $pageId = $this->createPageDirectly('Original Name');
        $updateData = ['name' => 'Updated Name'];
        $payload = ['action' => 'update', 'id' => $pageId, 'data' => $updateData];
        // Assuming ID in payload is primary, URL might not need it, or can have it for RESTfulness.
        // Let's use ID in payload only, as per prompt "id is in the JSON payload".
        $response = $this->request('POST', "/v1/api/pages.php", [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertEquals('Updated Name', $response['data']['name']);
        
        $stmt = self::$pdo->prepare("SELECT name FROM Pages WHERE id = :id");
        $stmt->execute([':id' => $pageId]);
        $this->assertEquals('Updated Name', $stmt->fetchColumn());
    }
    
    public function testPutUpdatePageAlias()
    {
        $pageId = $this->createPageDirectly('Page For Alias Update', 'old-alias');
        $updateData = ['alias' => 'new-alias'];
        $payload = ['action' => 'update', 'id' => $pageId, 'data' => $updateData];
        $response = $this->request('POST', "/v1/api/pages.php", [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertEquals('new-alias', $response['data']['alias']);
    }
    
    public function testPutUpdateNameToJournalPage()
    {
        $pageId = $this->createPageDirectly('MyOldPageName');
        $journalName = date('Y-m-d', strtotime('+2 days'));
        $updateData = ['name' => $journalName];
        $payload = ['action' => 'update', 'id' => $pageId, 'data' => $updateData];
        $response = $this->request('POST', "/v1/api/pages.php", [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
        $this->assertEquals($journalName, $response['data']['name']);

        // If PUT response includes properties:
        // $this->assertArrayHasKey('type', $response['data']['properties']);
        // $this->assertEquals('journal', $response['data']['properties']['type'][0]['value']);
        
        $stmt = self::$pdo->prepare("SELECT value FROM Properties WHERE page_id = :page_id AND name = 'type'");
        $stmt->execute([':page_id' => $pageId]);
        $this->assertEquals('journal', $stmt->fetchColumn(), "type::journal property should be added.");
    }


    public function testPutUpdatePageFailureInvalidId()
    {
        $payload = ['action' => 'update', 'id' => 88888, 'data' => ['name' => 'No Such Page']];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }

    public function testPutUpdatePageFailureDuplicateName()
    {
        $this->createPageDirectly('Existing Name For Put');
        $pageIdToUpdate = $this->createPageDirectly('Page To Update Name');
        $payload = ['action' => 'update', 'id' => $pageIdToUpdate, 'data' => ['name' => 'Existing Name For Put']];
        
        $response = $this->request('POST', "/v1/api/pages.php", [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page name already exists', $response['message']);
    }
    
    public function testPutUpdatePageFailureNoNameOrAlias()
    {
        $pageId = $this->createPageDirectly('Page For No Field Update');
        $payload = ['action' => 'update', 'id' => $pageId, 'data' => []]; // No fields in data
        $response = $this->request('POST', "/v1/api/pages.php", [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Either name or alias must be provided for update.', $response['message']);
    }


    // --- Test DELETE /api/pages.php?id={id} ---
    public function testDeletePageSuccess()
    {
        $pageId = $this->createPageDirectly('Page To Be Deleted');
        $noteId = $this->createNoteOnPage($pageId, 'Note on deleted page');
        $this->addPageProperty($pageId, 'status', 'to_delete');

        $payload = ['action' => 'delete', 'id' => $pageId];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']);
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
        $payload = ['action' => 'delete', 'id' => 77777];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Page not found', $response['message']);
    }

    // Test for include_internal functionality (basic example)
    public function testGetPageWithInternalProperty()
    {
        $pageId = $this->createPageDirectly('Page With Internal Prop');
        // Manually insert an internal property using a convention (e.g., prefix or dedicated column if schema supports)
        // For this test, let's assume 'internal_status' is an internal property.
        // The Properties table needs an 'internal' column for this to be meaningful.
        // Let's add a helper to set internal status for a property if not already done.
        // For now, assume 'internal_status' is treated as internal by the API if 'internal' column is 1.
        self::$pdo->exec("INSERT INTO Properties (page_id, name, value, internal) VALUES ($pageId, 'public_prop', 'visible', 0)");
        self::$pdo->exec("INSERT INTO Properties (page_id, name, value, internal) VALUES ($pageId, 'internal_prop', 'hidden_val', 1)");

        // Case 1: include_internal=0 (or not provided)
        $responseNoInternal = $this->request('GET', '/v1/api/pages.php', ['id' => $pageId, 'include_internal' => '0']);
        $this->assertEquals('success', $responseNoInternal['status']);
        $this->assertArrayHasKey('public_prop', $responseNoInternal['data']['properties']);
        $this->assertEquals('visible', $responseNoInternal['data']['properties']['public_prop'][0]['value']);
        $this->assertArrayNotHasKey('internal_prop', $responseNoInternal['data']['properties']);

        // Case 2: include_internal=1
        $responseWithInternal = $this->request('GET', '/v1/api/pages.php', ['id' => $pageId, 'include_internal' => '1']);
        $this->assertEquals('success', $responseWithInternal['status']);
        $this->assertArrayHasKey('public_prop', $responseWithInternal['data']['properties']);
        $this->assertEquals('visible', $responseWithInternal['data']['properties']['public_prop'][0]['value']);
        $this->assertArrayHasKey('internal_prop', $responseWithInternal['data']['properties']);
        $this->assertEquals('hidden_val', $responseWithInternal['data']['properties']['internal_prop'][0]['value']);
        $this->assertEquals(1, $responseWithInternal['data']['properties']['internal_prop'][0]['internal']);
    }

    public function testPostCreatePageWithPropertiesExplicit()
    {
        $data = [
            'name' => 'Page With Explicit Props',
            'properties_explicit' => [
                'custom_prop' => [['value' => 'custom_val', 'internal' => 0]],
                'internal_custom_prop' => [['value' => 'hidden_custom', 'internal' => 1]]
            ]
        ];
        $payload = ['action' => 'create', 'data' => $data];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $pageData = $response['data'];
        $this->assertEquals('Page With Explicit Props', $pageData['name']);
        $this->assertArrayHasKey('id', $pageData);
        $pageId = $pageData['id'];

        // Verify properties in response (if API returns them on create)
        if (isset($pageData['properties'])) {
            $this->assertArrayHasKey('custom_prop', $pageData['properties']);
            $this->assertEquals('custom_val', $pageData['properties']['custom_prop'][0]['value']);
            $this->assertEquals(0, $pageData['properties']['custom_prop'][0]['internal']);
            // Internal prop might not be returned unless include_internal=true was somehow implied or is default for creator
            // For now, we'll check DB for internal prop
        }

        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT name, value, internal FROM Properties WHERE page_id = :page_id");
        $stmt->execute([':page_id' => $pageId]);
        $dbPropsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dbProps = [];
        foreach ($dbPropsRaw as $p) {
            $dbProps[$p['name']] = ['value' => $p['value'], 'internal' => $p['internal']];
        }
        $this->assertEquals('custom_val', $dbProps['custom_prop']['value']);
        $this->assertEquals(0, $dbProps['custom_prop']['internal']);
        $this->assertEquals('hidden_custom', $dbProps['internal_custom_prop']['value']);
        $this->assertEquals(1, $dbProps['internal_custom_prop']['internal']);
    }
    
    // Helper to set a page as internal (e.g., by adding an 'internal::true' property)
    private function setPageInternalStatus(int $pageId, bool $isInternal = true)
    {
        // Remove existing 'internal' property first to avoid duplicates
        self::$pdo->prepare("DELETE FROM Properties WHERE page_id = :page_id AND name = 'internal' AND internal = 1")
                  ->execute([':page_id' => $pageId]);
        if ($isInternal) {
            self::$pdo->prepare("INSERT INTO Properties (page_id, name, value, internal) VALUES (:page_id, 'internal', 'true', 1)")
                      ->execute([':page_id' => $pageId,]);
        }
        // This also assumes the API recognizes 'internal::true' to mark a page as internal for filtering purposes.
        // Or, if Pages table had an 'internal' column, this helper would update that.
        // For now, using a property.
    }

    public function testGetInternalPageByIdVisibility()
    {
        $pageId = $this->createPageDirectly('Test Internal Page Visibility');
        $this->setPageInternalStatus($pageId, true);

        // Case 1: include_internal=0 (or not provided) - Page should not be found or error
        $responseNoInternal = $this->request('GET', '/v1/api/pages.php', ['id' => $pageId, 'include_internal' => '0']);
        $this->assertEquals('error', $responseNoInternal['status'], "Internal page should not be returned with include_internal=0");
        $this->assertEquals('Page not found or is internal', $responseNoInternal['message']);

        // Case 2: include_internal=1 - Page should be found
        $responseWithInternal = $this->request('GET', '/v1/api/pages.php', ['id' => $pageId, 'include_internal' => '1']);
        $this->assertEquals('success', $responseWithInternal['status']);
        $this->assertEquals($pageId, $responseWithInternal['data']['id']);
        $this->assertEquals('Test Internal Page Visibility', $responseWithInternal['data']['name']);
        // Check if the 'internal::true' property is present
        $this->assertArrayHasKey('internal', $responseWithInternal['data']['properties']);
        $this->assertEquals('true', $responseWithInternal['data']['properties']['internal'][0]['value']);
        $this->assertEquals(1, $responseWithInternal['data']['properties']['internal'][0]['internal']);
    }

    public function testGetAllPagesWithInternalPageVisibility()
    {
        self::$pdo->exec("DELETE FROM Pages"); // Clean slate
        self::$pdo->exec("DELETE FROM Properties");


        $publicPageId = $this->createPageDirectly('Public Page For List');
        $internalPageId = $this->createPageDirectly('Internal Page For List');
        $this->setPageInternalStatus($internalPageId, true);

        // Case 1: include_internal=0 (or not provided)
        $responseNoInternal = $this->request('GET', '/v1/api/pages.php', ['include_internal' => '0', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseNoInternal['status']);
        $this->assertCount(1, $responseNoInternal['data']['data'], "Should only list public pages.");
        $this->assertEquals($publicPageId, $responseNoInternal['data']['data'][0]['id']);

        // Case 2: include_internal=1
        $responseWithInternal = $this->request('GET', '/v1/api/pages.php', ['include_internal' => '1', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseWithInternal['status']);
        $this->assertCount(2, $responseWithInternal['data']['data'], "Should list both public and internal pages.");
        
        $foundPublic = false;
        $foundInternal = false;
        foreach($responseWithInternal['data']['data'] as $page) {
            if ($page['id'] == $publicPageId) $foundPublic = true;
            if ($page['id'] == $internalPageId) $foundInternal = true;
        }
        $this->assertTrue($foundPublic, "Public page not found in list with include_internal=1");
        $this->assertTrue($foundInternal, "Internal page not found in list with include_internal=1");
    }

    public function testPutUpdatePagePropertiesExplicit()
    {
        $pageId = $this->createPageDirectly('Page For Explicit Prop Update');
        // Add an initial property that should be cleared if not internal
        $this->addPageProperty($pageId, 'old_prop', 'old_value');
        // Add an initial internal property that should be preserved
        self::$pdo->exec("INSERT INTO Properties (page_id, name, value, internal) VALUES ($pageId, 'internal_old_prop', 'kept_value', 1)");


        $updateData = [
            'properties_explicit' => [
                'new_prop' => [['value' => 'new_value', 'internal' => 0]],
                'another_internal_prop' => [['value' => 'another_hidden', 'internal' => 1]]
            ]
        ];
        $payload = ['action' => 'update', 'id' => $pageId, 'data' => $updateData];
        $response = $this->request('POST', '/v1/api/pages.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $pageData = $response['data'];

        // Verify properties in response
        $this->assertArrayHasKey('new_prop', $pageData['properties']);
        $this->assertEquals('new_value', $pageData['properties']['new_prop'][0]['value']);
        $this->assertEquals(0, $pageData['properties']['new_prop'][0]['internal']);
        
        // Non-internal 'old_prop' should be gone
        $this->assertArrayNotHasKey('old_prop', $pageData['properties']); 
        
        // Check for internal properties (assuming include_internal=true is default for owner or update response includes all)
        // Or, if API doesn't return internal ones by default on PUT, we must check DB.
        // For now, let's assume they are returned if the update operation is aware of them.
        if (isset($pageData['properties']['internal_old_prop'])) { // It might not be returned if include_internal behavior applies
            $this->assertEquals('kept_value', $pageData['properties']['internal_old_prop'][0]['value']);
            $this->assertEquals(1, $pageData['properties']['internal_old_prop'][0]['internal']);
        }
        if (isset($pageData['properties']['another_internal_prop'])) {
             $this->assertEquals('another_hidden', $pageData['properties']['another_internal_prop'][0]['value']);
             $this->assertEquals(1, $pageData['properties']['another_internal_prop'][0]['internal']);
        }


        // Verify in DB
        $stmt = self::$pdo->prepare("SELECT name, value, internal FROM Properties WHERE page_id = :page_id");
        $stmt->execute([':page_id' => $pageId]);
        $dbPropsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dbProps = [];
        foreach ($dbPropsRaw as $p) {
            $dbProps[$p['name']] = ['value' => $p['value'], 'internal' => $p['internal']];
        }

        $this->assertEquals('new_value', $dbProps['new_prop']['value']);
        $this->assertEquals(0, $dbProps['new_prop']['internal']);
        $this->assertEquals('another_hidden', $dbProps['another_internal_prop']['value']);
        $this->assertEquals(1, $dbProps['another_internal_prop']['internal']);
        $this->assertEquals('kept_value', $dbProps['internal_old_prop']['value']); // Internal old prop should be preserved
        $this->assertEquals(1, $dbProps['internal_old_prop']['internal']);
        $this->assertArrayNotHasKey('old_prop', $dbProps, "Non-internal old_prop should have been deleted from DB.");
    }
}
?>
