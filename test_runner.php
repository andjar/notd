<?php
require_once __DIR__ . '/tests/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php'; // PHPUnit

// Simple test classes without PHPUnit dependency
class SimpleDataManagerTest {
    private $pdo;
    private $dm;

    public function setUp() {
        $this->pdo = new PDO('sqlite:' . DB_PATH);
        $this->dm = new \App\DataManager($this->pdo);
    }

    public function testGetPageById() {
        $page = $this->dm->getPageById(1);
        if ($page['name'] !== 'Home') {
            throw new Exception("Expected 'Home', got '{$page['name']}'");
        }
        if (!isset($page['properties'])) {
            throw new Exception("Page should have properties");
        }
    }

    public function testGetNoteProperties() {
        $props = $this->dm->getNoteProperties(1);
        if (!isset($props['status'])) {
            throw new Exception("Note should have status property");
        }
        if ($props['status'][0]['value'] !== 'TODO') {
            throw new Exception("Expected 'TODO', got '{$props['status'][0]['value']}'");
        }
    }
}

class SimplePageTest {
    private $pdo;
    private $dm;

    public function setUp() {
        $this->pdo = new PDO('sqlite:' . DB_PATH);
        $this->dm = new \App\DataManager($this->pdo);
    }

    public function testGetPages() {
        $result = $this->dm->getPages();
        if (count($result['data']) !== 1) {
            throw new Exception("Expected 1 page, got " . count($result['data']));
        }
        if ($result['data'][0]['name'] !== 'Home') {
            throw new Exception("Expected 'Home', got '{$result['data'][0]['name']}'");
        }
    }
}

class SimplePropertyTest {
    private $pdo;
    private $dm;

    public function setUp() {
        $this->pdo = new PDO('sqlite:' . DB_PATH);
        $this->dm = new \App\DataManager($this->pdo);
    }

    public function testPropertyWeightConfiguration() {
        $internalProps = $this->dm->getNoteProperties(1, false);
        if (isset($internalProps['internal'])) {
            throw new Exception("Internal properties should be hidden");
        }

        $allProps = $this->dm->getNoteProperties(1, true);
        if (!isset($allProps['internal'])) {
            throw new Exception("Internal properties should be visible when requested");
        }
    }
}

$testSuites = [
    new SimpleDataManagerTest(),
    new SimplePageTest(),
    new SimplePropertyTest()
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
