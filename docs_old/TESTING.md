# Testing Guide

This document explains how to run tests for the Notd Outliner API, both locally and in CI/CD.

## Test Structure

The test suite is organized into three main categories:

### 1. Unit Tests (`tests/Unit/`)
- **DataManagerTest.php** - Tests for the DataManager class
- **PageTest.php** - Tests for page operations
- **PropertyTest.php** - Tests for property system

### 2. Integration Tests (`tests/ApiIntegrationTest.php`)
- **API Endpoint Testing** - Tests actual HTTP endpoints
- **Request/Response Validation** - Ensures proper JSON responses
- **Error Handling** - Tests error scenarios and edge cases
- **Authentication** - Tests security features (if applicable)

### 3. Performance Tests (`tests/PerformanceTest.php`)
- **Load Testing** - Tests API performance under load
- **Database Lock Handling** - Tests retry logic
- **Memory Usage** - Monitors memory consumption
- **Response Time** - Ensures acceptable performance

## Running Tests Locally

### Prerequisites

1. **Install PHPUnit**:
```bash
composer require --dev phpunit/phpunit
```

2. **Install dependencies**:
```bash
composer install
```

### Quick Test Commands

```bash
# Run all tests
cd tests
php run_tests.php all

# Run specific test suites
php run_tests.php unit
php run_tests.php integration
php run_tests.php performance

# Run with coverage report
php run_tests.php unit coverage
```

### Using PHPUnit Directly

```bash
# Run all tests
../vendor/bin/phpunit

# Run specific test file
../vendor/bin/phpunit DataManagerTest.php

# Run with coverage
../vendor/bin/phpunit --coverage-html coverage --coverage-text

# Run specific test method
../vendor/bin/phpunit --filter testGetPageById DataManagerTest.php
```

## CI/CD Workflows

### 1. Quick Tests (`quick-tests.yml`)
**Triggers**: Push to main/feat/impr, Pull Requests
**Purpose**: Fast feedback during development
**Runs**: Unit + Integration tests only
**Duration**: ~5-10 minutes

```yaml
# Runs on every push and PR
- Unit tests (DataManager, Pages, Properties)
- Integration tests (API endpoints)
- No performance tests (for speed)
```

### 2. Full Test Suite (`php-tests.yml`)
**Triggers**: Push to main/feat/impr, Pull Requests
**Purpose**: Comprehensive testing
**Runs**: All test suites across multiple PHP versions
**Duration**: ~15-20 minutes

```yaml
# Matrix testing across:
- PHP versions: 8.1, 8.2
- Test suites: unit, integration, performance
- Coverage reporting
- Test result artifacts
```

### 3. Release Tests (`release-tests.yml`)
**Triggers**: Release creation, Manual dispatch
**Purpose**: Pre-release validation
**Runs**: Full suite + security scan
**Duration**: ~30-45 minutes

```yaml
# Comprehensive release testing:
- All test suites
- Multiple PHP versions
- Performance testing
- Security audit
- Coverage reports
- Release notes integration
```

## Test Configuration

### Environment Variables

```bash
# Required for tests
DB_PATH=./db/test_database.sqlite
UPLOADS_DIR=./uploads/test
APP_ENV=testing

# Optional
COVERAGE_DIR=./coverage
TEST_TIMEOUT=60
```

### PHPUnit Configuration (`tests/phpunit.xml`)

```xml
<!-- Test suites -->
<testsuites>
    <testsuite name="Unit Tests">
        <directory>.</directory>
        <exclude>ApiIntegrationTest.php</exclude>
        <exclude>PerformanceTest.php</exclude>
    </testsuite>
    
    <testsuite name="Integration Tests">
        <file>ApiIntegrationTest.php</file>
    </testsuite>
    
    <testsuite name="Performance Tests">
        <file>PerformanceTest.php</file>
    </testsuite>
</testsuites>

<!-- Coverage settings -->
<coverage>
    <include>
        <directory suffix=".php">../api</directory>
    </include>
    <report>
        <html outputDirectory="coverage"/>
        <text outputFile="coverage.txt"/>
    </report>
</coverage>
```

## Test Database Setup

The test suite uses a separate SQLite database that's created fresh for each test run:

```php
// tests/bootstrap.php
$GLOBALS['DB_PATH_OVERRIDE_FOR_TESTING'] = __DIR__ . '/../db/test_database.sqlite';

// Clean database before tests
if (file_exists(DB_PATH)) {
    unlink(DB_PATH);
}

// Create test schema
$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->exec("CREATE TABLE Pages (...)");
$pdo->exec("CREATE TABLE Notes (...)");
$pdo->exec("CREATE TABLE Properties (...)");

// Seed test data
$pdo->exec("INSERT INTO Pages (name, content) VALUES ('Home', 'Welcome')");
```

## Writing New Tests

### Unit Test Example

```php
<?php
namespace Tests;

use PHPUnit\Framework\TestCase;
use App\DataManager;

class MyTest extends TestCase
{
    private $pdo;
    private $dm;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite:' . DB_PATH);
        $this->dm = new DataManager($this->pdo);
    }

    public function testMyFeature()
    {
        // Arrange
        $expected = 'expected result';
        
        // Act
        $actual = $this->dm->myMethod();
        
        // Assert
        $this->assertEquals($expected, $actual);
    }
}
```

### Integration Test Example

```php
public function testCreatePageEndpoint()
{
    $response = $this->makeRequest('POST', '/pages', [
        'name' => 'Test Page',
        'content' => '{type::test}'
    ]);
    
    $this->assertEquals(201, $response['status_code']);
    $this->assertArrayHasKey('id', $response['data']['data']);
    $this->assertEquals('Test Page', $response['data']['data']['name']);
}
```

### Performance Test Example

```php
public function testBatchOperationsPerformance()
{
    $operations = [];
    for ($i = 0; $i < 20; $i++) {
        $operations[] = [
            'type' => 'create',
            'payload' => [
                'page_id' => 1,
                'content' => "Note $i",
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
    $this->assertLessThan(2000, $responseTime, "Batch operations should complete in under 2000ms");
}
```

## Test Best Practices

### 1. Test Isolation
- Each test should be independent
- Use `setUp()` and `tearDown()` for cleanup
- Don't rely on test execution order

### 2. Descriptive Names
```php
// Good
public function testCreatePageWithValidDataReturns201()
public function testCreatePageWithDuplicateNameReturns409()

// Bad
public function testCreatePage()
public function testError()
```

### 3. Arrange-Act-Assert Pattern
```php
public function testGetPageById()
{
    // Arrange
    $pageId = 1;
    $expectedName = 'Test Page';
    
    // Act
    $page = $this->dm->getPageById($pageId);
    
    // Assert
    $this->assertEquals($expectedName, $page['name']);
}
```

### 4. Test Edge Cases
```php
public function testGetPageByIdWithInvalidId()
{
    $page = $this->dm->getPageById(999999);
    $this->assertNull($page);
}

public function testCreatePageWithEmptyName()
{
    $response = $this->makeRequest('POST', '/pages', ['name' => '']);
    $this->assertEquals(400, $response['status_code']);
}
```

## Debugging Tests

### Enable Verbose Output
```bash
php run_tests.php unit --verbose
```

### Debug Specific Test
```bash
# Run single test method
../vendor/bin/phpunit --filter testMyMethod MyTest.php

# Run with debug output
../vendor/bin/phpunit --debug MyTest.php
```

### Check Test Database
```bash
# Inspect test database
sqlite3 db/test_database.sqlite
.tables
SELECT * FROM Pages;
```

### View Coverage Report
```bash
# Generate HTML coverage report
php run_tests.php unit coverage
open tests/coverage/index.html
```

## Continuous Integration

### GitHub Actions Workflows

1. **Quick Tests** - Fast feedback for development
2. **Full Tests** - Comprehensive testing for PRs
3. **Release Tests** - Pre-release validation

### Workflow Triggers

```yaml
on:
  push:
    branches: [main, feat/impr]
  pull_request:
    branches: [main]
  release:
    types: [published, created]
  workflow_dispatch:  # Manual trigger
```

### Test Results

- **Artifacts**: Test results, coverage reports
- **Badges**: Test status badges for README
- **Comments**: PR comments with test summary
- **Release Notes**: Test results added to releases

## Performance Benchmarks

### Expected Response Times

| Operation | Expected Time | Threshold |
|-----------|---------------|-----------|
| Health Check | < 100ms | 200ms |
| Single Note Create | < 500ms | 1000ms |
| Batch Operations (10 items) | < 1000ms | 2000ms |
| Search (simple) | < 300ms | 500ms |
| Search (complex) | < 1000ms | 2000ms |

### Memory Usage

| Operation | Expected Memory | Threshold |
|-----------|----------------|-----------|
| Single Request | < 10MB | 20MB |
| Batch Operations (50 items) | < 50MB | 100MB |
| Large Content Processing | < 20MB | 50MB |

## Troubleshooting

### Common Issues

1. **Database Lock Errors**
   - Ensure test database is cleaned up
   - Check for concurrent test execution
   - Verify SQLite WAL mode is enabled

2. **Test Timeouts**
   - Increase timeout in phpunit.xml
   - Check for infinite loops in tests
   - Verify external dependencies

3. **Coverage Issues**
   - Ensure Xdebug is installed
   - Check coverage configuration
   - Verify file paths are correct

### Getting Help

1. Check the test logs in GitHub Actions
2. Run tests locally with verbose output
3. Review the test database state
4. Check PHP error logs

## Contributing

When adding new features:

1. **Write tests first** (TDD approach)
2. **Cover edge cases** and error scenarios
3. **Update this documentation** if needed
4. **Ensure all tests pass** before submitting PR

Remember: **Good tests are documentation that never gets out of date!** 