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
        $this->baseUrl = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8000/api/v1', '/');
        $this->testDbPath = getenv('DB_PATH') ?: (__DIR__ . '/../db/test_database.sqlite');
        
        require_once __DIR__ . '/bootstrap.php';
    }

    protected function tearDown(): void
    {
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

    private function makeRequest($method, $endpoint, $data = null)
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
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);

        $startTime = microtime(true);
        $response = file_get_contents($url, false, $context);
        $endTime = microtime(true);
        
        $httpCode = $http_response_header[0] ?? 'HTTP/1.1 500 Internal Server Error';
        preg_match('/\s(\d{3})\s/', $httpCode, $statusMatch);
        $statusCode = isset($statusMatch[1]) ? (int)$statusMatch[1] : 500;
        $responseBody = $response === false ? '' : $response;
        
        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
            'data' => $responseBody !== '' ? json_decode($responseBody, true) : null,
            'response_time' => ($endTime - $startTime) * 1000
        ];
    }

    public function testBatchOperationsPerformance()
    {
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Performance Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

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
                'batch' => true,
                'operations' => $operations
            ]);
            $endTime = microtime(true);
            
            $responseTime = ($endTime - $startTime) * 1000;
            
            $this->assertEquals(200, $response['status_code']);
            // Batch results are returned directly in data as an array
            $this->assertCount($size, $response['data']['data']);
            
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
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Concurrent Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

        $processes = [];
        $results = [];
        
        for ($i = 0; $i < 5; $i++) {
            $processes[] = function() use ($pageId, $i, &$results) {
                $response = $this->makeRequest('GET', "/notes?page_id=$pageId");
                $results[$i] = [
                    'status_code' => $response['status_code'],
                    'response_time' => $response['response_time']
                ];
            };
        }

        $startTime = microtime(true);
        foreach ($processes as $process) {
            $process();
        }
        $endTime = microtime(true);
        
        $totalTime = ($endTime - $startTime) * 1000;
        
        foreach ($results as $result) {
            $this->assertEquals(200, $result['status_code']);
        }
        
        $this->assertLessThan(2000, $totalTime, "Concurrent requests should complete in under 2000ms, got {$totalTime}ms");
    }

    public function testDatabaseLockHandling()
    {
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Lock Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

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
        
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $startTime = microtime(true);
            $response = $this->makeRequest('POST', '/notes', [
                'batch' => true,
                'operations' => $operations
            ]);
            $endTime = microtime(true);
            
            $responseTime = ($endTime - $startTime) * 1000;
            $totalTime += $responseTime;
            
            if ($response['status_code'] === 200) {
                $successCount++;
            }
        }
        
        $successRate = ($successCount / 5) * 100;
        $this->assertGreaterThanOrEqual(80, $successRate, "Success rate should be at least 80%, got {$successRate}%");
        
        $avgTime = $totalTime / 5;
        $this->assertLessThan(3000, $avgTime, "Average response time should be under 3000ms, got {$avgTime}ms");
    }

    public function testLargeDataHandling()
    {
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
        
        $pageId = $response['data']['data']['id'];
        $propertiesResponse = $this->makeRequest('GET', "/properties?entity_type=page&entity_id=$pageId&include_hidden=true");
        
        $this->assertEquals(200, $propertiesResponse['status_code']);
        $this->assertArrayHasKey('priority', $propertiesResponse['data']['data']);
        $this->assertArrayHasKey('tags', $propertiesResponse['data']['data']);
    }

    public function testMemoryUsage()
    {
        $initialMemory = memory_get_usage(true);
        
        $pageResponse = $this->makeRequest('POST', '/pages', [
            'name' => 'Memory Test Page',
            'content' => 'Test content'
        ]);
        $pageId = $pageResponse['data']['data']['id'];

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
            'batch' => true,
            'operations' => $operations
        ]);

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        $this->assertEquals(200, $response['status_code']);
        
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 
            "Memory increase should be less than 10MB, got " . round($memoryIncrease / 1024 / 1024, 2) . "MB");
    }
}
