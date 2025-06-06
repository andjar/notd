<?php
// tests/BaseTestCase.php

use PHPUnit\Framework\TestCase;

class BaseTestCase extends TestCase
{
    protected static $pdo;
    protected static $tempUploadsDir;

    public static function setUpBeforeClass(): void
    {
        // Define APP_ROOT_PATH if not defined by bootstrap.php, for robustness
        if (!defined('APP_ROOT_PATH')) {
            define('APP_ROOT_PATH', dirname(__DIR__));
        }
    }

    protected function setUp(): void
    {
        // Define DB_PATH for in-memory SQLite database for each test
        if (!defined('DB_PATH')) {
            define('DB_PATH', ':memory:');
        }

        // Setup temporary directory for uploads
        self::$tempUploadsDir = sys_get_temp_dir() . '/api_tests_uploads_' . uniqid();
        if (!is_dir(self::$tempUploadsDir)) {
            mkdir(self::$tempUploadsDir, 0777, true);
        }
        if (!defined('UPLOADS_DIR')) {
            define('UPLOADS_DIR', self::$tempUploadsDir);
        } else {
            // If UPLOADS_DIR is already defined, we might be in trouble or need to adapt.
            // For now, assume we can define it or it matches.
            // This could happen if config.php is included before BaseTestCase::setUp runs.
            // To be safe, let's try to ensure our test-specific UPLOADS_DIR is used.
            // This is tricky because constants can't be redefined.
            // A better approach would be for config.php to use getenv() or a variable that can be overridden.
            // For now, we'll proceed hoping this definition takes precedence or is handled.
        }
        
        // Define other potentially missing constants from config.php
        // These are typically defined in config.php but are needed by included files.
        if (!defined('APP_BASE_URL')) define('APP_BASE_URL', 'http://localhost');
        if (!defined('SITE_TITLE')) define('SITE_TITLE', 'Test Site');
        if (!defined('DEFAULT_PAGE_NAME')) define('DEFAULT_PAGE_NAME', 'default');
        if (!defined('ENABLE_RSS')) define('ENABLE_RSS', false);
        if (!defined('LOG_FILE')) define('LOG_FILE', self::$tempUploadsDir . '/app_test.log'); // Use temp dir for logs too
        if (!defined('SESSION_TIMEOUT_DURATION')) define('SESSION_TIMEOUT_DURATION', 3600);
        if (!defined('MAX_UPLOAD_SIZE_MB')) define('MAX_UPLOAD_SIZE_MB', 10);
        if (!defined('THUMBNAIL_WIDTH')) define('THUMBNAIL_WIDTH', 200);
        if (!defined('THUMBNAIL_HEIGHT')) define('THUMBNAIL_HEIGHT', 200);
        if (!defined('DEFAULT_THEME')) define('DEFAULT_THEME', 'default');
        if (!defined('DEBUG_MODE')) define('DEBUG_MODE', false);
        if (!defined('API_KEY')) define('API_KEY', 'test_api_key'); // For API client tests if any

        // Include db_connect.php to establish the connection and run schema setup
        // db_connect.php internally calls setup_db.php if DB_PATH is :memory:
        require_once APP_ROOT_PATH . '/api/db_connect.php';
        
        // The get_db_connection() function is in db_connect.php
        // It initializes $pdo and also calls setup_db() which creates schema.
        global $pdo; // db_connect.php makes $pdo global
        if (isset($GLOBALS['pdo_instance'])) { // db_connect.php might store it in $GLOBALS
            self::$pdo = $GLOBALS['pdo_instance'];
        } elseif ($pdo instanceof PDO) {
             self::$pdo = $pdo;
        } else {
            // Fallback if global $pdo isn't set as expected, try to get it directly
            self::$pdo = get_db_connection(); 
        }

        if (!self::$pdo) {
            $this->fail("Failed to establish a database connection for tests.");
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo) {
            self::$pdo = null; // Close the connection
        }
        
        // Clean up the temporary uploads directory
        if (is_dir(self::$tempUploadsDir)) {
            // Remove all files in the directory
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$tempUploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }
            rmdir(self::$tempUploadsDir);
        }

        // Reset globals that might have been set by API scripts
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = []; // Be cautious with $_SERVER, PHPUnit might rely on some parts
    }

    /**
     * Helper method to simulate a request to an API script.
     *
     * @param string $method 'GET' or 'POST'
     * @param string $target The API script path relative to APP_ROOT_PATH (e.g., 'api/notes.php')
     * @param array $data Associative array for $_GET or $_POST data
     * @param array $files Associative array for $_FILES data (for uploads)
     * @return mixed Decoded JSON response or raw output
     */
    protected function request(string $method, string $target, array $data = [], array $files = [])
    {
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        
        if (strtoupper($method) === 'GET') {
            $_GET = $data;
        } else {
            $_POST = $data;
        }
        $_FILES = $files; // For file uploads

        // Ensure the target path is absolute
        $scriptPath = APP_ROOT_PATH . '/' . ltrim($target, '/');

        if (!file_exists($scriptPath)) {
            $this->fail("API script not found: {$scriptPath}");
        }

        ob_start();
        try {
            // Make $pdo available to the script's scope if it's not already global
            // Some scripts might expect $pdo to be globally available
            if (self::$pdo) {
                 $GLOBALS['pdo'] = self::$pdo; // Ensure it's global for the script
            }
            include $scriptPath;
        } catch (Exception $e) {
            // Catch exceptions from the script to allow for graceful failure reporting
            ob_end_clean();
            $this->fail("Exception caught in API script {$target}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
        $output = ob_get_clean();

        // Attempt to decode JSON, but return raw output if it fails
        $decodedOutput = json_decode($output, true);
        return $decodedOutput !== null ? $decodedOutput : $output;
    }
}
?>
