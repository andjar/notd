<?php
require_once __DIR__ . '/tests/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php'; // PHPUnit

$testSuites = [
    new DataManagerTest(),
    new PageTest(),
    new PropertyTest()
];

$results = [];

foreach ($testSuites as $suite) {
    $class = new ReflectionClass($suite);
    foreach ($class->getMethods() as $method) {
        if (str_starts_with($method->name, 'test')) {
            $start = microtime(true);
            try {
                $suite->setUp();
                $method->invoke($suite);
                $status = 'PASS';
            } catch (Throwable $e) {
                $status = 'FAIL: ' . $e->getMessage();
            } finally {
                if (method_exists($suite, 'tearDown')) {
                    $suite->tearDown();
                }
            }
            $results[] = [
                'name' => $class->getName() . '::' . $method->name,
                'status' => $status,
                'time' => round((microtime(true) - $start) * 1000, 2)
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode($results);
