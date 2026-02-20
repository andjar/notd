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
        $this->baseUrl = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8000/api/v1', '/');
        $this->testDbPath = getenv('DB_PATH') ?: (__DIR__ . '/../db/test_database.sqlite');
        
        require_once __DIR__ . '/bootstrap.php';
        
        if (!$this->isServerAvailable()) {
            $this->markTestSkipped('Web server not available for integration tests');
        }
    }

    protected function tearDown(): void
    {
    }

    private function isServerAvailable()
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);
        
        $result = @file_get_contents($this->baseUrl . '/ping.php', false, $context);
        return $result !== false;
    }

    private function normalizeEndpoint($endpoint)
    {
        $endpoint = '/' . ltrim($endpoint, '/');
        $queryPos = strpos($endpoint, '?');
        $path = $queryPos === false ? $endpoint : substr($endpoint, 0, $queryPos);
        $query = $queryPos === false ? '' : substr($endpoint, $queryPos);

        if (!str_ends_with($path, '.php')) {
            $path .= '.php';
        }

        return $path . $query;
    }

    private function makeRequest($method, $endpoint, $data = null, $headers = [])
    {
        $normalizedEndpoint = $this->normalizeEndpoint($endpoint);
        $url = $this->baseUrl . $normalizedEndpoint;
        $body = null;

        if ($data !== null) {
            $body = is_string($data) ? $data : json_encode($data);
        }
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => array_merge([
                    'Content-Type: application/json',
                    'Accept: application/json'
                ], $headers),
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);

        $response = file_get_contents($url, false, $context);
        $httpCode = $http_response_header[0] ?? 'HTTP/1.1 500 Internal Server Error';
        preg_match('/\s(\d{3})\s/', $httpCode, $statusMatch);
        $statusCode = isset($statusMatch[1]) ? (int)$statusMatch[1] : 500;
        $responseBody = $response === false ? '' : $response;
        
        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
            'data' => $responseBody !== '' ? json_decode($responseBody, true) : null
        ];
    }

    public function testHealthCheck()
    {
        $response = $this->makeRequest('GET', '/ping.php');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertIsArray($response['data']);
        $this->assertEquals('pong', $response['data']['status']);
        $this->assertArrayHasKey('timestamp', $response['data']);
    }

    public function testGetRecentPages()
    {
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
        $this->assertArrayHasKey('recent_pages', $response['data']);
        $this->assertIsArray($response['data']['recent_pages']);
        $this->assertGreaterThanOrEqual(2, count($response['data']['recent_pages']));
        
        foreach ($response['data']['recent_pages'] as $page) {
            $this->assertArrayHasKey('id', $page);
            $this->assertArrayHasKey('name', $page);
            $this->assertArrayHasKey('updated_at', $page);
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
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Batch Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

        $response = $this->makeRequest('POST', '/notes', [
            'batch' => true,
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
        // Batch results are returned directly in data as an array
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(2, $response['data']['data']);
        
        foreach ($response['data']['data'] as $result) {
            $this->assertEquals('success', $result['status']);
            $this->assertContains($result['type'], ['create', 'upsert']);
        }
    }

    public function testGetNotesByPage()
    {
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Notes Test Page',
            'notes' => [
                ['content' => 'Note 1', 'order_index' => 1],
                ['content' => 'Note 2', 'order_index' => 2]
            ]
        ]);

        $pageResponse = $this->makeRequest('GET', '/pages?name=Notes%20Test%20Page');
        $this->assertEquals(200, $pageResponse['status_code'], 'Failed to fetch page');
        $pageId = $pageResponse['data']['data']['id'];

        $response = $this->makeRequest('GET', "/notes?page_id=$pageId&include_internal=true");

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertCount(2, $response['data']['data']);
    }

    public function testSearchFunctionality()
    {
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Search Test Page',
            'notes' => [
                ['content' => 'Important note with {priority::high}', 'order_index' => 1],
                ['content' => 'TODO Task to complete', 'order_index' => 2],
                ['content' => 'DONE Completed task', 'order_index' => 3]
            ]
        ]);

        $response = $this->makeRequest('GET', '/search?q=important&page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('results', $response['data']['data']);

        $response = $this->makeRequest('GET', '/search?tasks=TODO&page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('results', $response['data']['data']);

        $response = $this->makeRequest('GET', '/search?backlinks_for_page_name=Search%20Test%20Page&page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
    }

    public function testPropertiesEndpoint()
    {
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Properties Test Page',
            'notes' => [
                ['content' => 'Note with {status::active} {priority::high}', 'order_index' => 1]
            ]
        ]);

        $pageResponse = $this->makeRequest('GET', '/pages?name=Properties%20Test%20Page');
        $this->assertEquals(200, $pageResponse['status_code'], 'Failed to fetch page');
        $pageId = $pageResponse['data']['data']['id'];
        
        $notesResponse = $this->makeRequest('GET', "/notes?page_id=$pageId");
        $this->assertEquals(200, $notesResponse['status_code'], 'Failed to fetch notes');
        $this->assertNotEmpty($notesResponse['data']['data'], 'No notes returned');
        $noteId = $notesResponse['data']['data'][0]['id'];

        $response = $this->makeRequest('GET', "/properties?entity_type=note&entity_id=$noteId&include_hidden=true");

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        $this->assertArrayHasKey('status', $response['data']['data']);
        $this->assertArrayHasKey('priority', $response['data']['data']);
    }

    public function testTemplatesEndpoint()
    {
        $response = $this->makeRequest('GET', '/templates?type=note');
        $this->assertEquals(200, $response['status_code']);

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
        // Invalid JSON
        $response = $this->makeRequest('POST', '/pages', 'invalid json');
        $this->assertGreaterThanOrEqual(400, $response['status_code']);

        // Missing required fields
        $response = $this->makeRequest('POST', '/pages', []);
        $this->assertGreaterThanOrEqual(400, $response['status_code']);

        // Non-existent resource
        $response = $this->makeRequest('GET', '/notes?id=00000000-0000-0000-0000-000000000000');
        $this->assertEquals(404, $response['status_code']);

        // Invalid method
        $response = $this->makeRequest('PATCH', '/pages');
        $this->assertEquals(405, $response['status_code']);

        // recent_pages only accepts GET
        $response = $this->makeRequest('POST', '/recent_pages');
        $this->assertEquals(405, $response['status_code']);
    }

    public function testPagination()
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeRequest('POST', '/pages', [
                'name' => "Pagination Page $i",
                'content' => "Content $i"
            ]);
        }

        $response = $this->makeRequest('GET', '/pages?page=1&per_page=10');
        $this->assertEquals(200, $response['status_code']);
        $this->assertCount(10, $response['data']['data']);
        $this->assertEquals(10, $response['data']['pagination']['per_page']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertGreaterThan(10, $response['data']['pagination']['total_items']);
    }

    public function testPropertyInheritance()
    {
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Inheritance Test Page',
            'notes' => [
                [
                    'content' => 'Parent note with {category::work}',
                    'order_index' => 1
                ]
            ]
        ]);

        $pageResponse = $this->makeRequest('GET', '/pages?name=Inheritance%20Test%20Page');
        $this->assertEquals(200, $pageResponse['status_code'], 'Failed to fetch page');
        $pageId = $pageResponse['data']['data']['id'];
        
        $notesResponse = $this->makeRequest('GET', "/notes?page_id=$pageId");
        $this->assertEquals(200, $notesResponse['status_code'], 'Failed to fetch notes');
        $this->assertNotEmpty($notesResponse['data']['data'], 'No notes returned');
        $parentNoteId = $notesResponse['data']['data'][0]['id'];

        $response = $this->makeRequest('POST', '/notes', [
            'batch' => true,
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

        $this->assertEquals(200, $response['status_code'], 'Batch create child failed');
        // Batch results are returned directly in data as an array
        $childNoteId = $response['data']['data'][0]['note']['id'];

        $response = $this->makeRequest('GET', "/notes?id=$childNoteId&include_parent_properties=true");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('parent_properties', $response['data']['data']);
        $this->assertArrayHasKey('category', $response['data']['data']['parent_properties']);
    }

    public function testMultiplePropertiesWithSameName()
    {
        $response = $this->makeRequest('POST', '/pages', [
            'name' => 'Multiple Properties Test Page',
            'content' => '{favorite::true} {type::person} {favorite::false} {type::journal}'
        ]);

        $this->assertEquals(201, $response['status_code']);
        $this->assertEquals('success', $response['data']['status']);
        
        $pageId = $response['data']['data']['id'];
        
        $pageResponse = $this->makeRequest('GET', "/pages?id=$pageId");
        
        $this->assertEquals(200, $pageResponse['status_code']);
        $this->assertEquals('success', $pageResponse['data']['status']);
        
        $page = $pageResponse['data']['data'];
        $this->assertArrayHasKey('properties', $page, 'Page should have properties');
        
        $properties = $page['properties'];
        
        $this->assertArrayHasKey('favorite', $properties, 'favorite property should exist');
        $this->assertArrayHasKey('type', $properties, 'type property should exist');
        
        $this->assertCount(2, $properties['favorite'], 'favorite property should have 2 values');
        $favoriteValues = array_column($properties['favorite'], 'value');
        $this->assertContains('true', $favoriteValues, 'favorite should contain true');
        $this->assertContains('false', $favoriteValues, 'favorite should contain false');
        
        $this->assertCount(2, $properties['type'], 'type property should have 2 values');
        $typeValues = array_column($properties['type'], 'value');
        $this->assertContains('person', $typeValues, 'type should contain person');
        $this->assertContains('journal', $typeValues, 'type should contain journal');
    }

    public function testPropertyUpdateWithMultipleValues()
    {
        $createResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Property Update Test Page',
            'content' => '{status::old} {priority::low}'
        ]);

        $this->assertEquals(201, $createResponse['status_code']);
        $pageId = $createResponse['data']['data']['id'];
        
        $updateResponse = $this->makeRequest('PUT', '/pages', [
            'id' => $pageId,
            'name' => 'Property Update Test Page',
            'content' => '{status::new} {priority::high} {status::active}'
        ]);

        $this->assertEquals(200, $updateResponse['status_code']);
        $this->assertEquals('success', $updateResponse['data']['status']);
        
        $pageResponse = $this->makeRequest('GET', "/pages?id=$pageId");
        
        $this->assertEquals(200, $pageResponse['status_code']);
        $page = $pageResponse['data']['data'];
        $properties = $page['properties'];
        
        $this->assertArrayHasKey('status', $properties, 'status property should exist');
        $this->assertArrayHasKey('priority', $properties, 'priority property should exist');
        
        $statusValues = array_column($properties['status'], 'value');
        $priorityValues = array_column($properties['priority'], 'value');
        
        $this->assertNotContains('old', $statusValues, 'Old status should be replaced');
        $this->assertNotContains('low', $priorityValues, 'Old priority should be replaced');
        
        $this->assertContains('new', $statusValues, 'New status should be present');
        $this->assertContains('active', $statusValues, 'Active status should be present');
        $this->assertContains('high', $priorityValues, 'High priority should be present');
    }
}
