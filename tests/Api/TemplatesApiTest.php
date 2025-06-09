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

    // --- Test GET /v1/api/templates.php?type={type} ---
    public function testGetNoteTemplatesSuccess()
    {
        $this->createDummyTemplateFile('note', 'my_test_note_template', "Test note template {{date}}");
        $params = ['type' => 'note', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params);

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        
        $found = false;
        foreach ($response['data']['data'] as $template) {
            if ($template['name'] === 'my_test_note_template') {
                $found = true;
                $this->assertStringContainsString(date('Y-m-d'), $template['content']); // {{date}} should be resolved
                $this->assertStringNotContainsString("{{date}}", $template['content']);
                break;
            }
        }
        $this->assertTrue($found, "Test note template not found in response.");
        if ($found) { // If the template was found, total items should be at least 1
             $this->assertGreaterThanOrEqual(1, $response['data']['pagination']['total_items']);
        }
    }

    public function testGetPageTemplatesSuccess()
    {
        $this->createDummyTemplateFile('page', 'my_test_page_template', "Page content with placeholder: {{input:Enter Value}}");
        $params = ['type' => 'page', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);

        $found = false;
        foreach ($response['data']['data'] as $template) {
            if ($template['name'] === 'my_test_page_template') {
                $found = true;
                $this->assertStringContainsString("{{input:Enter Value}}", $template['content']);
                break;
            }
        }
        $this->assertTrue($found, "Test page template not found.");
    }

    public function testGetTemplatesNoTemplatesOfType()
    {
        $params = ['type' => 'note', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }

    public function testGetTemplatesInvalidType()
    {
        $params = ['type' => 'invalid_type', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid template type', $response['message']);
    }

    public function testGetTemplates() // Gets all templates (both types)
    {
        $this->createDummyTemplateFile('note', 'default_note_tpl', "Default note content");
        $this->createDummyTemplateFile('page', 'default_page_tpl', "Default page content");
        $params = ['page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params); 
        
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertGreaterThanOrEqual(2, $response['data']['pagination']['total_items']);

        $foundNote = false;
        $foundPage = false;
        foreach ($response['data']['data'] as $template) {
            if ($template['name'] === 'default_note_tpl') $foundNote = true;
            if ($template['name'] === 'default_page_tpl') $foundPage = true;
        }
        $this->assertTrue($foundNote, "Default note template not found in response");
        $this->assertTrue($foundPage, "Default page template not found in response");
    }

    public function testGetTemplatesWithName()
    {
        $this->createDummyTemplateFile('note', 'test_template', "Test template content");
        // When fetching by specific name, pagination might not apply, or it's a list of 1.
        // Assuming it returns a list for consistency, even if only one match.
        $params = ['name' => 'test_template', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertEquals('test_template', $response['data']['data'][0]['name']);
        $this->assertEquals('Test template content', $response['data']['data'][0]['content']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['total_items']);
    }

    public function testGetTemplatesWithNonexistentName()
    {
        $params = ['name' => 'nonexistent', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }

    public function testGetTemplatesWithEmptyName() // Should list all if name is empty
    {
        $this->createDummyTemplateFile('note', 'some_note_tpl_for_empty_name_test', "content");
        $params = ['name' => '', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/templates.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertNotEmpty($response['data']['data']); // Expecting previously created template
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertGreaterThanOrEqual(1, $response['data']['pagination']['total_items']);
    }

    // --- Test POST /v1/api/templates.php (Create Template) ---
    public function testPostCreateNoteTemplateSuccess()
    {
        $templateData = [
            'type' => 'note',
            'name' => 'new_note_tpl_from_post',
            'content' => 'Hello from POST {{placeholder}}'
        ];
        $payload = ['action' => 'create', 'data' => $templateData];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        // Expect the created template object in response['data']
        $this->assertEquals($templateData['name'], $response['data']['name']);
        $this->assertEquals($templateData['content'], $response['data']['content']); // Assuming content is returned as-is
        $this->assertEquals($templateData['type'], $response['data']['type']);

        // Location header check
        $this->assertArrayHasKey('headers', $response, "Response should have headers array");
        $this->assertArrayHasKey('Location', $response['headers'], "Response should have Location header");
        // Location might be /v1/api/templates.php?type=note&name=new_note_tpl_from_post or similar
        $this->assertStringContainsString('/v1/api/templates.php?name=' . urlencode($templateData['name']), $response['headers']['Location']);
        $this->assertStringContainsString('type=' . $templateData['type'], $response['headers']['Location']);
        
        $expectedPath = $this->testNoteTemplateDir . '/new_note_tpl_from_post.php';
        $this->assertFileExists($expectedPath);
        $this->assertEquals($templateData['content'], file_get_contents($expectedPath));
        $this->createdTemplateFiles['note'][] = 'new_note_tpl_from_post';
    }

    public function testPostCreatePageTemplateSuccess()
    {
        $templateData = ['type' => 'page', 'name' => 'new_page_tpl_post', 'content' => 'Page content'];
        $payload = ['action' => 'create', 'data' => $templateData];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
        $this->assertEquals($templateData['name'], $response['data']['name']);
        
        $expectedPath = $this->testPageTemplateDir . '/new_page_tpl_post.php';
        $this->assertFileExists($expectedPath);
        $this->createdTemplateFiles['page'][] = 'new_page_tpl_post';
    }
    
    // Renamed to testPostUpdateTemplateContent, using action 'update'
    public function testPostUpdateTemplateContent()
    {
        $this->createDummyTemplateFile('note', 'template_to_update', 'Old content');
        $updateData = [
            'type' => 'note', 
            'current_name' => 'template_to_update', 
            'new_name' => 'template_to_update', // Name not changing in this case
            'content' => 'New content'
        ];
        $payload = ['action' => 'update', 'data' => $updateData];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('template_to_update', $response['data']['name']);
        $this->assertEquals('New content', $response['data']['content']);
        $this->assertEquals('New content', file_get_contents($this->testNoteTemplateDir . '/template_to_update.php'));
    }

    public function testPostUpdateTemplateNameAndContent()
    {
        $this->createDummyTemplateFile('note', 'old_template_name', 'Initial content for rename');
        $updateData = [
            'type' => 'note',
            'current_name' => 'old_template_name',
            'new_name' => 'new_template_name_after_update',
            'content' => 'Updated content after rename'
        ];
        $payload = ['action' => 'update', 'data' => $updateData];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertEquals($updateData['new_name'], $response['data']['name']);
        $this->assertEquals($updateData['content'], $response['data']['content']);
        
        $this->assertFileDoesNotExist($this->testNoteTemplateDir . '/old_template_name.php');
        $this->assertFileExists($this->testNoteTemplateDir . '/new_template_name_after_update.php');
        $this->assertEquals($updateData['content'], file_get_contents($this->testNoteTemplateDir . '/new_template_name_after_update.php'));
        $this->createdTemplateFiles['note'][] = 'new_template_name_after_update'; // Track for cleanup
    }


    public function testPostCreateTemplateFailureCases()
    {
        $payloadNoType = ['action' => 'create', 'data' => ['name' => 'n', 'content' => 'c']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadNoType));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid template type', $response['message']);

        $payloadNoName = ['action' => 'create', 'data' => ['type' => 'note', 'content' => 'c']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadNoName));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Template name is required', $response['message']);

        // Assuming content is also required for creation
        $payloadNoContent = ['action' => 'create', 'data' => ['type' => 'note', 'name' => 'n']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadNoContent));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Template content is required', $response['message']); 
        
        $payloadBadType = ['action' => 'create', 'data' => ['type' => 'bad_type', 'name' => 'n', 'content' => 'c']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadBadType));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid template type', $response['message']);
    }

    // --- Test POST /v1/api/templates.php with action=delete ---
    public function testPostDeleteNoteTemplateSuccess()
    {
        $this->createDummyTemplateFile('note', 'del_me_note_post', 'content to delete');
        $deleteData = ['type' => 'note', 'name' => 'del_me_note_post'];
        $payload = ['action' => 'delete', 'data' => $deleteData];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        // Assuming response data contains info about deleted template
        $this->assertEquals('del_me_note_post', $response['data']['deleted_template_name']);
        $this->assertEquals('note', $response['data']['deleted_template_type']);
        $this->assertFileDoesNotExist($this->testNoteTemplateDir . '/del_me_note_post.php');
    }

    public function testPostDeletePageTemplateSuccess()
    {
        $this->createDummyTemplateFile('page', 'del_me_page_post', 'page content to delete');
        $deleteData = ['type' => 'page', 'name' => 'del_me_page_post'];
        $payload = ['action' => 'delete', 'data' => $deleteData];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payload));
        $this->assertEquals('success', $response['status']);
        $this->assertFileDoesNotExist($this->testPageTemplateDir . '/del_me_page_post.php');
    }

    public function testPostDeleteTemplateFailureCases()
    {
        $payloadNoName = ['action' => 'delete', 'data' => ['type' => 'note']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadNoName));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Template name and type are required for deletion.', $response['message']);

        $payloadNoType = ['action' => 'delete', 'data' => ['name' => 'somename']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadNoType));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Template name and type are required for deletion.', $response['message']);

        $payloadNonExistent = ['action' => 'delete', 'data' => ['name' => 'non_existent_tpl_post', 'type' => 'note']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadNonExistent));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Failed to delete template. File not found or permission issue.', $response['message']);

        $payloadInvalidType = ['action' => 'delete', 'data' => ['name' => 'somename', 'type' => 'invalid_type_del_post']];
        $response = $this->request('POST', '/v1/api/templates.php', [], [], json_encode($payloadInvalidType));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid template type', $response['message']);
    }
}
?>
