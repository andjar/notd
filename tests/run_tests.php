<?php
/**
 * Test Runner Script for Notd Outliner API
 * 
 * Usage:
 *   php run_tests.php                    # Run all tests
 *   php run_tests.php unit               # Run only unit tests
 *   php run_tests.php integration        # Run only integration tests
 *   php run_tests.php performance        # Run only performance tests
 *   php run_tests.php coverage           # Run tests with coverage report
 */

// Ensure we're in the tests directory
chdir(__DIR__);

// Check if PHPUnit is available
if (!file_exists('../vendor/bin/phpunit')) {
    echo "PHPUnit not found. Please install dependencies:\n";
    echo "composer install --dev\n";
    exit(1);
}

$suite = $argv[1] ?? 'all';
$phpunitPath = '../vendor/bin/phpunit';

// Define test suites
$suites = [
    'unit' => '--testsuite "Unit Tests"',
    'integration' => '--testsuite "Integration Tests"',
    'performance' => '--testsuite "Performance Tests"',
    'all' => ''
];

if (!isset($suites[$suite])) {
    echo "Invalid test suite: $suite\n";
    echo "Available suites: " . implode(', ', array_keys($suites)) . "\n";
    exit(1);
}

// Build command
$command = $phpunitPath . ' ' . $suites[$suite];

// Add coverage if requested
if ($suite === 'coverage' || isset($argv[2]) && $argv[2] === 'coverage') {
    $command .= ' --coverage-html coverage --coverage-text';
}

// Add verbose output
$command .= ' --verbose';

echo "Running tests: $suite\n";
echo "Command: $command\n\n";

// Execute tests
$output = [];
$returnCode = 0;
exec($command . ' 2>&1', $output, $returnCode);

// Display output
foreach ($output as $line) {
    echo $line . "\n";
}

// Display summary
echo "\n" . str_repeat('=', 50) . "\n";
if ($returnCode === 0) {
    echo "✅ All tests passed!\n";
} else {
    echo "❌ Some tests failed!\n";
}
echo str_repeat('=', 50) . "\n";

exit($returnCode); 