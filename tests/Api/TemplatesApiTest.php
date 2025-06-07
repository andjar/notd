<?php
// tests/Api/TemplatesApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO; // Though not directly used for DB ops, it's standard.

class TemplatesApiTest extends BaseTestCase
{
    private $testNoteTemplateDir;
    private $testPageTemplateDir;
    private $createdTemplateFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Define TEMPLATES_PATH if not already defined by config.php inclusion in BaseTestCase or bootstrap
        // BaseTestCase should define APP_ROOT_PATH. config.php defines TEMPLATES_PATH relative to that.
        if (!defined('TEMPLATES_PATH')) {
            // This assumes config.php would have been sourced if running the actual app.
            // For tests, we might need to define it if not picked up from a test-specific config.
            // Let's assume BaseTestCase or bootstrap ensures config.php constants are available.
            // If not, we might need: define('TEMPLATES_PATH', APP_ROOT_PATH . '/assets/template');
            // For this test, we'll use a path within our controlled temp uploads dir for safety.
            // This avoids modifying actual asset templates.
             define('TEMPLATES_PATH_ORIGINAL', APP_ROOT_PATH . '/assets/template'); // Save original if exists
             if(defined('UPLOADS_DIR')) { // UPLOADS_DIR is defined in BaseTestCase as a temp dir
                define('TEMPLATES_PATH', self::$tempUploadsDir . '/test_templates');
             } else {
                $this->fail("UPLOADS_DIR (temp dir for tests) is not defined.");
             }
        }
        
        $this->testNoteTemplateDir = TEMPLATES_PATH . '/note';
        $this->testPageTemplateDir = TEMPLATES_PATH . '/page';

        // Ensure base test template directories exist
        if (!is_dir($this->testNoteTemplateDir)) {
            mkdir($this->testNoteTemplateDir, 0777, true);
        }
        if (!is_dir($this->testPageTemplateDir)) {
            mkdir($this->testPageTemplateDir, 0777, true);
        }
        
        // Clean up any pre-existing test template files from previous runs (if any)
        $this->cleanupTestTemplates();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestTemplates();
        
        // Remove the test_templates directory itself
        if (defined('TEMPLATES_PATH') && strpos(TEMPLATES_PATH, 'test_templates') !== false) {
             if (is_dir(TEMPLATES_PATH . '/note')) rmdir(TEMPLATES_PATH . '/note');
             if (is_dir(TEMPLATES_PATH . '/page')) rmdir(TEMPLATES_PATH . '/page');
             if (is_dir(TEMPLATES_PATH)) rmdir(TEMPLATES_PATH);
        }
        
        // Restore original TEMPLATES_PATH if we overrode it
        // This is tricky with constants. A better way would be to have TemplateProcessor allow path injection.
        // For now, this constant override is specific to this test class execution.

        parent::tearDown();
    }

    private function cleanupTestTemplates()
    {
        foreach ($this->createdTemplateFiles as $type => $filenames) {
            foreach ($filenames as $filename) {
                $path = TEMPLATES_PATH . '/' . $type . '/' . $filename . '.php';
                if (file_exists($path)) {
                    unlink($path);
                }
                 $pathJson = TEMPLATES_PATH . '/' . $type . '/' . $filename . '.json';
                 if (file_exists($pathJson)) {
                    unlink($pathJson);
                }
            }
        }
        $this->createdTemplateFiles = [];

        // General cleanup for any .php or .json files in test template dirs
        $noteFiles = glob($this->testNoteTemplateDir . '/*.php');
        foreach ($noteFiles as $file) unlink($file);
        $noteFilesJson = glob($this->testNoteTemplateDir . '/*.json');
        foreach ($noteFilesJson as $file) unlink($file);

        $pageFiles = glob($this->testPageTemplateDir . '/*.php');
        foreach ($pageFiles as $file) unlink($file);
        $pageFilesJson = glob($this->testPageTemplateDir . '/*.json');
        foreach ($pageFilesJson as $file) unlink($file);
    }

    private function createDummyTemplateFile(string $type, string $name, string $content): string
    {
        $dir = ($type === 'note') ? $this->testNoteTemplateDir : $this->testPageTemplateDir;
        $filename = $name . '.php'; // TemplateProcessor primarily looks for .php
        $path = $dir . '/' . $filename;
        file_put_contents($path, $content);
        $this->createdTemplateFiles[$type][] = $name; // Track for cleanup
        return $path;
    }

    // --- Test GET /api/templates.php?type={type} ---
    public function testGetNoteTemplatesSuccess()
    {
        $this->createDummyTemplateFile('note', 'my_test_note_template', "Test note template {{date}}");
        $response = $this->request('GET', 'api/templates.php', ['type' => 'note']);

        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $found = false;
        foreach ($response['data'] as $template) {
            if ($template['name'] === 'my_test_note_template') {
                $found = true;
                $this->assertStringContainsString(date('Y-m-d'), $template['content']); // {{date}} should be resolved
                $this->assertStringNotContainsString("{{date}}", $template['content']);
                break;
            }
        }
        $this->assertTrue($found, "Test note template not found in response.");
    }

    public function testGetPageTemplatesSuccess()
    {
        $this->createDummyTemplateFile('page', 'my_test_page_template', "Page content with placeholder: {{input:Enter Value}}");
        $response = $this->request('GET', 'api/templates.php', ['type' => 'page']);
        $this->assertTrue($response['success']);
        $found = false;
        foreach ($response['data'] as $template) {
            if ($template['name'] === 'my_test_page_template') {
                $found = true;
                // Placeholders like {{input:}} are returned as is by processTemplate, to be handled by UI
                $this->assertStringContainsString("{{input:Enter Value}}", $template['content']);
                break;
            }
        }
        $this->assertTrue($found, "Test page template not found.");
    }

    public function testGetTemplatesNoTemplatesOfType()
    {
        // Ensure no note templates exist by cleaning up first (setUp does this)
        $response = $this->request('GET', 'api/templates.php', ['type' => 'note']);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertEmpty($response['data']);
    }

    public function testGetTemplatesInvalidType()
    {
        $response = $this->request('GET', 'api/templates.php', ['type' => 'invalid_type']);
        $this->assertFalse($response['success']);
        $this->assertEquals('Invalid template type', $response['error']['message']);
    }

    public function testGetTemplates()
    {
        $this->createDummyTemplateFile('note', 'default_note_tpl', "Default note content");
        $response = $this->request('GET', 'api/templates.php'); // No type param
        $this->assertTrue($response['success']);
        $found = false;
        foreach ($response['data'] as $template) {
            if ($template['name'] === 'default_note_tpl') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Default note template not found in response");
    }

    public function testGetTemplatesWithName()
    {
        $this->createDummyTemplateFile('note', 'test_template', "Test template content");
        $response = $this->request('GET', 'api/templates.php', ['name' => 'test_template']);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertCount(1, $response['data']);
        $this->assertEquals('test_template', $response['data'][0]['name']);
        $this->assertEquals('Test template content', $response['data'][0]['content']);
    }

    public function testGetTemplatesWithNonexistentName()
    {
        $response = $this->request('GET', 'api/templates.php', ['name' => 'nonexistent']);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertEmpty($response['data']);
    }

    public function testGetTemplatesWithEmptyName()
    {
        $response = $this->request('GET', 'api/templates.php', ['name' => '']);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertEmpty($response['data']);
    }

    // --- Test POST /api/templates.php (Create Template) ---
    public function testPostCreateNoteTemplateSuccess()
    {
        $data = [
            'type' => 'note',
            'name' => 'new_note_tpl_from_post',
            'content' => 'Hello from POST {{placeholder}}'
        ];
        $response = $this->request('POST', 'api/templates.php', $data);

        $this->assertTrue($response['success']);
        $this->assertEquals('Template created successfully', $response['data']['message']);
        
        $expectedPath = $this->testNoteTemplateDir . '/new_note_tpl_from_post.php';
        $this->assertFileExists($expectedPath);
        $this->assertEquals($data['content'], file_get_contents($expectedPath));
        $this->createdTemplateFiles['note'][] = 'new_note_tpl_from_post'; // Track for cleanup
    }

    public function testPostCreatePageTemplateSuccess()
    {
        $data = ['type' => 'page', 'name' => 'new_page_tpl_post', 'content' => 'Page content'];
        $response = $this->request('POST', 'api/templates.php', $data);
        $this->assertTrue($response['success']);
        $expectedPath = $this->testPageTemplateDir . '/new_page_tpl_post.php';
        $this->assertFileExists($expectedPath);
        $this->createdTemplateFiles['page'][] = 'new_page_tpl_post';
    }
    
    public function testPostCreateTemplateOverwriteExisting()
    {
        $this->createDummyTemplateFile('note', 'overwrite_me', 'Old content');
        $data = ['type' => 'note', 'name' => 'overwrite_me', 'content' => 'New content'];
        $response = $this->request('POST', 'api/templates.php', $data);
        $this->assertTrue($response['success']);
        $this->assertEquals('New content', file_get_contents($this->testNoteTemplateDir . '/overwrite_me.php'));
    }


    public function testPostCreateTemplateFailureCases()
    {
        $response = $this->request('POST', 'api/templates.php', ['name' => 'n', 'content' => 'c']); // Missing type
        $this->assertFalse($response['success']);
        $this->assertEquals('Invalid template type', $response['error']['message']);

        $response = $this->request('POST', 'api/templates.php', ['type' => 'note', 'content' => 'c']); // Missing name
        $this->assertFalse($response['success']);
        $this->assertEquals('Template name is required', $response['error']['message']);

        $response = $this->request('POST', 'api/templates.php', ['type' => 'note', 'name' => 'n']); // Missing content
        $this->assertFalse($response['success']);
        $this->assertEquals('Template name is required', $response['error']['message']);
        
        $response = $this->request('POST', 'api/templates.php', ['type' => 'bad_type', 'name' => 'n', 'content' => 'c']);
        $this->assertFalse($response['success']);
        $this->assertEquals('Invalid template type', $response['error']['message']);
    }

    // --- Test DELETE /api/templates.php?name={name}&type={type} ---
    public function testDeleteNoteTemplateSuccess()
    {
        $this->createDummyTemplateFile('note', 'del_me_note', 'content to delete');
        // Note: DELETE is simulated via GET params as per phpdesktop constraints mentioned in prompt
        // The API script templates.php checks $_GET for name and type for DELETE method.
        // Our request helper currently puts all $data into $_GET for GET, and $_POST for POST.
        // For DELETE, the API script expects params in $_GET.
        $response = $this->request('DELETE', 'api/templates.php', ['name' => 'del_me_note', 'type' => 'note']);

        $this->assertTrue($response['success']);
        $this->assertEquals('Template deleted successfully', $response['data']['message']);
        $this->assertFileDoesNotExist($this->testNoteTemplateDir . '/del_me_note.php');
    }

    public function testDeletePageTemplateSuccess()
    {
        $this->createDummyTemplateFile('page', 'del_me_page', 'page content to delete');
        $response = $this->request('DELETE', 'api/templates.php', ['name' => 'del_me_page', 'type' => 'page']);
        $this->assertTrue($response['success']);
        $this->assertFileDoesNotExist($this->testPageTemplateDir . '/del_me_page.php');
    }

    public function testDeleteTemplateFailureCases()
    {
        // Missing name
        $response = $this->request('DELETE', 'api/templates.php', ['type' => 'note']);
        $this->assertFalse($response['success']);
        $this->assertEquals('Template name and type are required', $response['error']['message']);

        // Missing type
        $response = $this->request('DELETE', 'api/templates.php', ['name' => 'somename']);
        $this->assertFalse($response['success']);
        $this->assertEquals('Template name and type are required', $response['error']['message']);

        // Template does not exist
        $response = $this->request('DELETE', 'api/templates.php', ['name' => 'non_existent_tpl', 'type' => 'note']);
        // The TemplateProcessor::deleteTemplate returns false if file doesn't exist or unlink fails.
        // The API script then returns "Failed to delete template" with a 500.
        $this->assertFalse($response['success']);
        $this->assertEquals('Failed to delete template', $response['error']['message']);
        // $this->assertEquals(500, $response['statusCode']); // If BaseTestCase could return status code

        // Invalid type
        $response = $this->request('DELETE', 'api/templates.php', ['name' => 'somename', 'type' => 'invalid_type_del']);
        $this->assertFalse($response['success']);
        $this->assertEquals('Invalid template type', $response['error']['message']);
    }
}
?>
