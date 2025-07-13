<?php
// tests/PerformanceTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;

class PerformanceTest extends TestCase
{
    private $baseUrl;
    private $testDbPath;

    protected function setUp(): void
    {
        if (getenv('CI')) {
            $this->markTestSkipped('Performance tests are skipped in CI environment.');
        }
        $this->baseUrl = 'http://localhost/api/v1';
        $this->testDbPath = __DIR__ . '/../db/test_database.sqlite';
        
        // Ensure clean test database
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
        
        // Run bootstrap to set up test database
        require_once __DIR__ . '/bootstrap.php';
    }

    protected function tearDown(): void
    {
        // Clean up test database
        if (!empty($this->testDbPath) && file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
    }

    private function makeRequest($method, $endpoint, $data = null)
    {
        $url = $this->baseUrl . $endpoint;
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                'content' => $data ? json_encode($data) : null,
                'timeout' => 30
            ]
        ]);

        $startTime = microtime(true);
        $response = file_get_contents($url, false, $context);
        $endTime = microtime(true);
        
        $httpCode = $http_response_header[0] ?? 'HTTP/1.1 500 Internal Server Error';
        
        return [
            'status_code' => (int) explode(' ', $httpCode)[1],
            'body' => $response,
            'data' => json_decode($response, true),
            'response_time' => ($endTime - $startTime) * 1000 // Convert to milliseconds
        ];
    }

    public function testBatchOperationsPerformance()
    {
        // Create a test page first
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Performance Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

        // Test batch operations with different sizes
        $batchSizes = [1, 5, 10, 20];
        
        foreach ($batchSizes as $size) {
            $operations = [];
            for ($i = 0; $i < $size; $i++) {
                $operations[] = [
                    'type' => 'create',
                    'payload' => [
                        'page_id' => $pageId,
                        'content' => "Batch note $i {priority::medium}",
                        'order_index' => $i + 1
                    ]
                ];
            }

            $startTime = microtime(true);
            $response = $this->makeRequest('POST', '/notes', [
                'action' => 'batch',
                'operations' => $operations
            ]);
            $endTime = microtime(true);
            
            $responseTime = ($endTime - $startTime) * 1000;
            
            $this->assertEquals(200, $response['status_code']);
            $this->assertCount($size, $response['data']['data']['results']);
            
            // Performance assertions based on batch size
            if ($size <= 5) {
                $this->assertLessThan(500, $responseTime, "Small batch ($size) should complete in under 500ms, got {$responseTime}ms");
            } elseif ($size <= 10) {
                $this->assertLessThan(1000, $responseTime, "Medium batch ($size) should complete in under 1000ms, got {$responseTime}ms");
            } else {
                $this->assertLessThan(2000, $responseTime, "Large batch ($size) should complete in under 2000ms, got {$responseTime}ms");
            }
        }
    }

    public function testSearchPerformance()
    {
        // Create test data for search
        $this->makeRequest('POST', '/append_to_page', [
            'page_name' => 'Search Performance Page',
            'notes' => [
                ['content' => 'Important note with {priority::high}', 'order_index' => 1],
                ['content' => 'TODO Task to complete', 'order_index' => 2],
                ['content' => 'DONE Completed task', 'order_index' => 3],
                ['content' => 'Another important note', 'order_index' => 4],
                ['content' => 'TODO Another task', 'order_index' => 5]
            ]
        ]);

        $searchTests = [
            ['endpoint' => '/search?q=important', 'description' => 'Full-text search'],
            ['endpoint' => '/search?tasks=TODO', 'description' => 'Task search'],
            ['endpoint' => '/search?backlinks_for_page_name=Search%20Performance%20Page', 'description' => 'Backlinks search']
        ];

        foreach ($searchTests as $test) {
            $times = [];
            
            // Run each search 5 times
            for ($i = 0; $i < 5; $i++) {
                $response = $this->makeRequest('GET', $test['endpoint']);
                $times[] = $response['response_time'];
                
                $this->assertEquals(200, $response['status_code']);
            }
            
            $avgTime = array_sum($times) / count($times);
            $maxTime = max($times);
            
            $this->assertLessThan(300, $avgTime, "{$test['description']} average time should be under 300ms, got {$avgTime}ms");
            $this->assertLessThan(500, $maxTime, "{$test['description']} max time should be under 500ms, got {$maxTime}ms");
        }
    }

    public function testConcurrentRequests()
    {
        // Create a test page
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Concurrent Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

        // Test concurrent read requests
        $processes = [];
        $results = [];
        
        // Simulate 5 concurrent requests
        for ($i = 0; $i < 5; $i++) {
            $processes[] = function() use ($pageId, $i, &$results) {
                $response = $this->makeRequest('GET', "/notes?page_id=$pageId");
                $results[$i] = [
                    'status_code' => $response['status_code'],
                    'response_time' => $response['response_time']
                ];
            };
        }

        // Execute all requests
        $startTime = microtime(true);
        foreach ($processes as $process) {
            $process();
        }
        $endTime = microtime(true);
        
        $totalTime = ($endTime - $startTime) * 1000;
        
        // All requests should succeed
        foreach ($results as $result) {
            $this->assertEquals(200, $result['status_code']);
        }
        
        // Concurrent requests should complete reasonably quickly
        $this->assertLessThan(2000, $totalTime, "Concurrent requests should complete in under 2000ms, got {$totalTime}ms");
    }

    public function testDatabaseLockHandling()
    {
        // Create a test page
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Lock Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

        // Simulate rapid batch operations that might cause database locks
        $operations = [];
        for ($i = 0; $i < 3; $i++) {
            $operations[] = [
                'type' => 'create',
                'payload' => [
                    'page_id' => $pageId,
                    'content' => "Rapid note $i",
                    'order_index' => $i + 1
                ]
            ];
        }

        $successCount = 0;
        $totalTime = 0;
        
        // Run multiple batch operations rapidly
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $startTime = microtime(true);
            $response = $this->makeRequest('POST', '/notes', [
                'action' => 'batch',
                'operations' => $operations
            ]);
            $endTime = microtime(true);
            
            $responseTime = ($endTime - $startTime) * 1000;
            $totalTime += $responseTime;
            
            if ($response['status_code'] === 200) {
                $successCount++;
            } elseif ($response['status_code'] === 503) {
                // Service unavailable due to database lock - this is expected occasionally
                $this->assertStringContainsString('retry', $response['data']['message'] ?? '');
            }
        }
        
        // At least 80% of requests should succeed
        $successRate = ($successCount / 5) * 100;
        $this->assertGreaterThanOrEqual(80, $successRate, "Success rate should be at least 80%, got {$successRate}%");
        
        // Average response time should be reasonable
        $avgTime = $totalTime / 5;
        $this->assertLessThan(3000, $avgTime, "Average response time should be under 3000ms, got {$avgTime}ms");
    }

    public function testLargeDataHandling()
    {
        // Test with large content
        $largeContent = str_repeat('This is a very long content string. ', 1000);
        $largeContent .= '{priority::high} {tags::large,content,test}';
        
        $startTime = microtime(true);
        $response = $this->makeRequest('POST', '/pages', [
            'name' => 'Large Content Page',
            'content' => $largeContent
        ]);
        $endTime = microtime(true);
        
        $responseTime = ($endTime - $startTime) * 1000;
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertLessThan(1000, $responseTime, "Large content should be processed in under 1000ms, got {$responseTime}ms");
        
        // Test properties extraction from large content
        $pageId = $response['data']['data']['id'];
        $propertiesResponse = $this->makeRequest('GET', "/properties?entity_type=page&entity_id=$pageId&include_hidden=true");
        
        $this->assertEquals(200, $propertiesResponse['status_code']);
        $this->assertArrayHasKey('priority', $propertiesResponse['data']['data']);
        $this->assertArrayHasKey('tags', $propertiesResponse['data']['data']);
    }

    public function testMemoryUsage()
    {
        // Test memory usage during batch operations
        $initialMemory = memory_get_usage(true);
        
        // Create a page
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Memory Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

        // Perform batch operations
        $operations = [];
        for ($i = 0; $i < 50; $i++) {
            $operations[] = [
                'type' => 'create',
                'payload' => [
                    'page_id' => $pageId,
                    'content' => "Memory test note $i with {priority::medium} and {tags::test,memory}",
                    'order_index' => $i + 1
                ]
            ];
        }

        $response = $this->makeRequest('POST', '/notes', [
            'action' => 'batch',
            'operations' => $operations
        ]);

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        $this->assertEquals(200, $response['status_code']);
        
        // Memory increase should be reasonable (less than 10MB)
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 
            "Memory increase should be less than 10MB, got " . round($memoryIncrease / 1024 / 1024, 2) . "MB");
    }
} 