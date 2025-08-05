<?php
// tests/ApiIntegrationTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;

class ApiIntegrationTest extends TestCase
{
    private $baseUrl;
    private $testDbPath;

    protected function setUp(): void
    {
        $this->baseUrl = 'http://localhost/api/v1';
        $this->testDbPath = __DIR__ . '/../db/test_database.sqlite';
        
        // Ensure clean test database
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
        
        // Run bootstrap to set up test database
        require_once __DIR__ . '/bootstrap.php';
        
        // Skip all tests if server is not available
        if (!$this->isServerAvailable()) {
            $this->markTestSkipped('Web server not available for integration tests');
        }
    }

    protected function tearDown(): void
    {
        // Clean up test database
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
    }

    private function isServerAvailable()
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5
            ]
        ]);
        
        $result = @file_get_contents($this->baseUrl . '/ping', false, $context);
        return $result !== false;
    }

    private function makeRequest($method, $endpoint, $data = null, $headers = [])
    {
        $url = $this->baseUrl . $endpoint;
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => array_merge([
                    'Content-Type: application/json',
                    'Accept: application/json'
                ], $headers),
                'content' => $data ? json_encode($data) : null,
                'timeout' => 30
            ]
        ]);

        $response = file_get_contents($url, false, $context);
        $httpCode = $http_response_header[0] ?? 'HTTP/1.1 500 Internal Server Error';
        
        return [
            'status_code' => (int) explode(' ', $httpCode)[1],
            'body' => $response,
            'data' => json_decode($response, true)
        ];
    }

    public function testHealthCheck()
    {
        $response = $this->makeRequest('GET', '/ping');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertEquals('pong', $response['data']['data']['status']);
        $this->assertArrayHasKey('timestamp', $response['data']['data']);
    }

    public function testGetRecentPages()
    {
        // Create some test pages with different timestamps
        $this->makeRequest('POST', '/pages', [
            'name' => 'Recent Page 1',
            'content' => 'Test content 1'
        ]);
        
        $this->makeRequest('POST', '/pages', [
            'name' => 'Recent Page 2',
            'content' => 'Test content 2'
        ]);

        $response = $this->makeRequest('GET', '/recent_pages');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertArrayHasKey('recent_pages', $response['data']['data']);
        $this->assertIsArray($response['data']['data']['recent_pages']);
        
        // Should return at least the pages we created
        $this->assertGreaterThanOrEqual(2, count($response['data']['data']['recent_pages']));
        
        // Check structure of returned pages
        foreach ($response['data']['data']['recent_pages'] as $page) {
            $this->assertArrayHasKey('id', $page);
            $this->assertArrayHasKey('name', $page);
            $this->assertArrayHasKey('updated_at', $page);
            $this->assertIsInt($page['id']);
            $this->assertIsString($page['name']);
            $this->assertIsString($page['updated_at']);
        }
    }

    public function testCreatePage()
    {
        $response = $this->makeRequest('POST', '/pages', [
            'name' => 'Test Page',
            'content' => '{type::test} {priority::high}'
        ]);

        $this->assertEquals(201, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertArrayHasKey('id', $response['data']['data']);
        $this->assertEquals('Test Page', $response['data']['data']['name']);
        $this->assertArrayHasKey('properties', $response['data']['data']);
    }

    public function testGetPageByName()
    {
        // First create a page
        $this->makeRequest('POST', '/pages', [
            'name' => 'Get Test Page',
            'content' => 'Test content'
        ]);

        $response = $this->makeRequest('GET', '/pages?name=Get%20Test%20Page');

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertEquals('Get Test Page', $response['data']['data']['name']);
    }

    public function testAppendToPage()
    {
        $response = $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Append Test Page',
            'notes' => [
                [
                    'content' => 'First note {priority::high}',
                    'order_index' => 1
                ],
                [
                    'content' => 'TODO Second note',
                    'order_index' => 2
                ]
            ]
        ]);

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertArrayHasKey('page', $response['data']['data']);
        $this->assertArrayHasKey('appended_notes', $response['data']['data']);
        $this->assertCount(2, $response['data']['data']['appended_notes']);
    }

    public function testBatchOperations()
    {
        // First create a page
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Batch Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

        $response = $this->makeRequest('POST', '/notes', [
            'action' => 'batch',
            'operations' => [
                [
                    'type' => 'create',
                    'payload' => [
                        'page_id' => $pageId,
                        'content' => 'Batch note 1 {tags::important}',
                        'order_index' => 1
                    ]
                ],
                [
                    'type' => 'create',
                    'payload' => [
                        'page_id' => $pageId,
                        'content' => 'Batch note 2',
                        'order_index' => 2
                    ]
                ]
            ]
        ]);

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertArrayHasKey('results', $response['data']['data']);
        $this->assertCount(2, $response['data']['data']['results']);
        
        // Check that both operations were successful
        foreach ($response['data']['data']['results'] as $result) {
            $this->assertEquals('success', $result['status']);
            $this->assertEquals('create', $result['type']);
        }
    }

    public function testGetNotesByPage()
    {
        // First create a page with notes
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Notes Test Page',
            'notes' => [
                ['content' => 'Note 1', 'order_index' => 1],
                ['content' => 'Note 2', 'order_index' => 2]
            ]
        ]);

        // Get the page to find its ID
        $pageResponse = $this->makeRequest('GET', '/pages?name=Notes%20Test%20Page');
        $pageId = $pageResponse['data']['data']['id'];

        $response = $this->makeRequest('GET', "/notes?page_id=$pageId&include_internal=true");

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertCount(2, $response['data']['data']);
    }

    public function testSearchFunctionality()
    {
        // Create test data
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Search Test Page',
            'notes' => [
                ['content' => 'Important note with {priority::high}', 'order_index' => 1],
                ['content' => 'TODO Task to complete', 'order_index' => 2],
                ['content' => 'DONE Completed task', 'order_index' => 3]
            ]
        ]);

        // Test full-text search
        $response = $this->makeRequest('GET', '/search?q=important&page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('results', $response['data']['data']);

        // Test task search
        $response = $this->makeRequest('GET', '/search?tasks=TODO&page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('results', $response['data']['data']);

        // Test backlinks search
        $response = $this->makeRequest('GET', '/search?backlinks_for_page_name=Search%20Test%20Page&page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
    }

    public function testPropertiesEndpoint()
    {
        // Create a note with properties
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Properties Test Page',
            'notes' => [
                ['content' => 'Note with {status::active} {priority::high}', 'order_index' => 1]
            ]
        ]);

        // Get the note ID
        $pageResponse = $this->makeRequest('GET', '/pages?name=Properties%20Test%20Page');
        $pageId = $pageResponse['data']['data']['id'];
        
        $notesResponse = $this->makeRequest('GET', "/notes?page_id=$pageId");
        $noteId = $notesResponse['data']['data'][0]['id'];

        // Test properties endpoint
        $response = $this->makeRequest('GET', "/properties?entity_type=note&entity_id=$noteId&include_hidden=true");

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertArrayHasKey('status', $response['data']['data']);
        $this->assertArrayHasKey('priority', $response['data']['data']);
    }

    public function testTemplatesEndpoint()
    {
        // Test get templates
        $response = $this->makeRequest('GET', '/templates?type=note');
        $this->assertEquals(200, $response['status_code']);

        // Test create template
        $response = $this->makeRequest('POST', '/templates', [
            'type' => 'note',
            'name' => 'Test Template',
            'content' => 'Template content with {{placeholder}}'
        ]);

        $this->assertEquals(201, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
    }

    public function testExtensionsEndpoint()
    {
        $response = $this->makeRequest('GET', '/extensions');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertArrayHasKey('extensions', $response['data']['data']);
    }

    public function testErrorHandling()
    {
        // Test invalid JSON
        $response = $this->makeRequest('POST', '/pages', 'invalid json');
        $this->assertEquals(400, $response['status_code']);

        // Test missing required fields
        $response = $this->makeRequest('POST', '/pages', []);
        $this->assertEquals(400, $response['status_code']);

        // Test non-existent resource
        $response = $this->makeRequest('GET', '/notes?id=00000000-0000-0000-0000-000000000000');
        $this->assertEquals(404, $response['status_code']);

        // Test invalid method
        $response = $this->makeRequest('PATCH', '/pages');
        $this->assertEquals(405, $response['status_code']);

        // Test recent_pages with invalid method
        $response = $this->makeRequest('POST', '/recent_pages');
        $this->assertEquals(405, $response['status_code']);
    }

    public function testPagination()
    {
        // Create multiple pages
        for ($i = 1; $i <= 25; $i++) {
            $this->makeRequest('POST', '/pages', [
                'name' => "Page $i",
                'content' => "Content $i"
            ]);
        }

        // Test pagination
        $response = $this->makeRequest('GET', '/pages?page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
        $this->assertCount(10, $response['data']['data']);
        $this->assertEquals(10, $response['data']['pagination']['per_page']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertGreaterThan(10, $response['data']['pagination']['total_items']);
    }

    public function testPropertyInheritance()
    {
        // Create a page with a property
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Inheritance Test Page',
            'notes' => [
                [
                    'content' => 'Parent note with {category::work}',
                    'order_index' => 1
                ]
            ]
        ]);

        // Get the page and note IDs
        $pageResponse = $this->makeRequest('GET', '/pages?name=Inheritance%20Test%20Page');
        $pageId = $pageResponse['data']['data']['id'];
        
        $notesResponse = $this->makeRequest('GET', "/notes?page_id=$pageId");
        $parentNoteId = $notesResponse['data']['data'][0]['id'];

        // Create a child note
        $response = $this->makeRequest('POST', '/notes', [
            'action' => 'batch',
            'operations' => [
                [
                    'type' => 'create',
                    'payload' => [
                        'page_id' => $pageId,
                        'parent_note_id' => $parentNoteId,
                        'content' => 'Child note',
                        'order_index' => 2
                    ]
                ]
            ]
        ]);

        $childNoteId = $response['data']['data']['results'][0]['note']['id'];

        // Test parent properties inheritance
        $response = $this->makeRequest('GET', "/notes?id=$childNoteId&include_parent_properties=true");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('parent_properties', $response['data']['data']);
        $this->assertArrayHasKey('category', $response['data']['data']['parent_properties']);
    }

    public function testMultiplePropertiesWithSameName()
    {
        // Test that the API correctly handles multiple properties with the same name
        // This tests the bug fix where only the last property was retained
        
        // Create a page with multiple properties of the same name
        $response = $this->makeRequest('POST', '/pages', [
            'name' => 'Multiple Properties Test Page',
            'content' => '{favorite::true} {type::person} {favorite::false} {type::journal}'
        ]);

        $this->assertEquals(201, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        
        $pageId = $response['data']['data']['id'];
        
        // Get the page and verify all properties are present
        $pageResponse = $this->makeRequest('GET', "/pages?id=$pageId");
        
        $this->assertEquals(200, $pageResponse['status_code']);
        $this->assertEquals('success', $pageResponse['data']['status']);
        
        $page = $pageResponse['data']['data'];
        $this->assertArrayHasKey('properties', $page, 'Page should have properties');
        
        $properties = $page['properties'];
        
        // Verify both property names exist
        $this->assertArrayHasKey('favorite', $properties, 'favorite property should exist');
        $this->assertArrayHasKey('type', $properties, 'type property should exist');
        
        // Verify multiple values for favorite property
        $this->assertCount(2, $properties['favorite'], 'favorite property should have 2 values');
        $favoriteValues = array_column($properties['favorite'], 'value');
        $this->assertContains('true', $favoriteValues, 'favorite should contain true');
        $this->assertContains('false', $favoriteValues, 'favorite should contain false');
        
        // Verify multiple values for type property
        $this->assertCount(2, $properties['type'], 'type property should have 2 values');
        $typeValues = array_column($properties['type'], 'value');
        $this->assertContains('person', $typeValues, 'type should contain person');
        $this->assertContains('journal', $typeValues, 'type should contain journal');
    }

    public function testPropertyUpdateWithMultipleValues()
    {
        // Test updating a page with multiple properties of the same name
        // First create a page with initial properties
        $createResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Property Update Test Page',
            'content' => '{status::old} {priority::low}'
        ]);

        $this->assertEquals(201, $createResponse['status_code']);
        $pageId = $createResponse['data']['data']['id'];
        
        // Update the page with new content containing multiple properties of the same name
        $updateResponse = $this->makeRequest('PUT', '/pages', [
            'id' => $pageId,
            'name' => 'Property Update Test Page',
            'content' => '{status::new} {priority::high} {status::active}'
        ]);

        $this->assertEquals(200, $updateResponse['status_code']);
        $this->assertEquals('success', $updateResponse['data']['status']);
        
        // Get the updated page and verify properties
        $pageResponse = $this->makeRequest('GET', "/pages?id=$pageId");
        
        $this->assertEquals(200, $pageResponse['status_code']);
        $page = $pageResponse['data']['data'];
        $properties = $page['properties'];
        
        // Verify old values are gone and new values are present
        $this->assertArrayHasKey('status', $properties, 'status property should exist');
        $this->assertArrayHasKey('priority', $properties, 'priority property should exist');
        
        $statusValues = array_column($properties['status'], 'value');
        $priorityValues = array_column($properties['priority'], 'value');
        
        // Old values should be gone
        $this->assertNotContains('old', $statusValues, 'Old status should be replaced');
        $this->assertNotContains('low', $priorityValues, 'Old priority should be replaced');
        
        // New values should be present
        $this->assertContains('new', $statusValues, 'New status should be present');
        $this->assertContains('active', $statusValues, 'Active status should be present');
        $this->assertContains('high', $priorityValues, 'High priority should be present');
    }
} 