<?php
// tests/Api/SearchApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO;

class SearchApiTest extends BaseTestCase
{
    protected static $page1Id;
    protected static $note1Id;
    protected static $note2Id;
    protected static $page2Id;
    protected static $note3Id;
    protected static $note4Id;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$pdo) {
            $this->fail("PDO connection not available in SearchApiTest::setUp");
        }
        
        // Clear any existing data to prevent interference
        self::$pdo->exec("DELETE FROM Properties");
        self::$pdo->exec("DELETE FROM Notes");
        self::$pdo->exec("DELETE FROM Pages");
        self::$pdo->exec("DELETE FROM Notes_fts WHERE Notes_fts MATCH 'apples OR oranges OR bananas OR fruit'"); // Clear FTS table too


        // Page 1: "Landing Page"
        $stmtPage1 = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmtPage1->execute([':name' => 'Landing Page']);
        self::$page1Id = self::$pdo->lastInsertId();

        // Note 1 (on Page 1): "This note talks about apples and oranges. {fruit::apple} [[Fruit Details]]"
        $contentNote1 = "This note talks about apples and oranges. fruit::apple [[Fruit Details]]";
        $stmtNote1 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote1->execute([':page_id' => self::$page1Id, ':content' => $contentNote1]);
        self::$note1Id = self::$pdo->lastInsertId();
        // Manually add properties that would be parsed by PatternProcessor
        $this->addPropertyDirectly('note', self::$note1Id, 'fruit', 'apple');
        $this->addPropertyDirectly('note', self::$note1Id, 'links_to_page', 'Fruit Details');


        // Note 2 (on Page 1): "Another note mentioning bananas. TODO: Buy bananas."
        // Content should imply a task. "status::TODO"
        $contentNote2 = "Another note mentioning bananas. TODO: Buy bananas. status::TODO";
        $stmtNote2 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote2->execute([':page_id' => self::$page1Id, ':content' => $contentNote2]);
        self::$note2Id = self::$pdo->lastInsertId();
        $this->addPropertyDirectly('note', self::$note2Id, 'status', 'TODO');


        // Page 2: "Fruit Details"
        $stmtPage2 = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmtPage2->execute([':name' => 'Fruit Details']);
        self::$page2Id = self::$pdo->lastInsertId();

        // Note 3 (on Page 2): "Detailed information about various fruits. Oranges are great."
        $contentNote3 = "Detailed information about various fruits. Oranges are great.";
        $stmtNote3 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote3->execute([':page_id' => self::$page2Id, ':content' => $contentNote3]);
        self::$note3Id = self::$pdo->lastInsertId();

        // Note 4 (on Page 2): "DONE: Research apples."
        // Content should imply a completed task. "status::DONE"
        $contentNote4 = "DONE: Research apples. status::DONE";
        $stmtNote4 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote4->execute([':page_id' => self::$page2Id, ':content' => $contentNote4]);
        self::$note4Id = self::$pdo->lastInsertId();
        $this->addPropertyDirectly('note', self::$note4Id, 'status', 'DONE');
        
        // Ensure FTS5 table is populated by simulating an insert/update after content is set.
        // This is usually handled by triggers in the main app. For tests, a manual population might be needed
        // if the initial INSERTs didn't trigger it for FTS5.
        // A simple UPDATE will trigger the FTS content update.
        self::$pdo->exec("UPDATE Notes SET content = content || '' WHERE id IN (" . self::$note1Id . "," . self::$note2Id . "," . self::$note3Id . "," . self::$note4Id . ")");

    }
    
    private function addPropertyDirectly(string $entityType, int $entityId, string $name, string $value, int $internal = 0): int
    {
        $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';
        $otherIdColumn = ($entityType === 'page') ? 'note_id' : 'page_id';

        $sql = "INSERT INTO Properties ({$idColumn}, {$otherIdColumn}, name, value, internal) VALUES (:entityId, NULL, :name, :value, :internal)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([
            ':entityId' => $entityId,
            ':name' => $name,
            ':value' => $value,
            ':internal' => $internal
        ]);
        return self::$pdo->lastInsertId();
    }


    protected function tearDown(): void
    {
        if (self::$pdo) {
            self::$pdo->exec("DELETE FROM Properties");
            self::$pdo->exec("DELETE FROM Notes_fts WHERE Notes_fts MATCH 'apples OR oranges OR bananas OR fruit'");
            self::$pdo->exec("DELETE FROM Notes");
            self::$pdo->exec("DELETE FROM Pages");
        }
        parent::tearDown();
    }

    // --- Test GET /api/search.php?q={term} (Full-Text Search) ---
    public function testSearchFullTextTermFound()
    {
        $params = ['q' => 'apples', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        // The setup creates two notes with "apples" (Note1 and Note4)
        $this->assertCount(2, $response['data']['data'], "Expected 2 notes for 'apples'"); 
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(2, $response['data']['pagination']['total_items']);

        $foundNote1 = false;
        $foundNote4 = false;
        foreach ($response['data']['data'] as $result) {
            $this->assertArrayHasKey('note_id', $result);
            $this->assertArrayHasKey('content_snippet', $result);
            $this->assertStringContainsString('<mark>apples</mark>', $result['content_snippet']);
            if ($result['note_id'] == self::$note1Id) $foundNote1 = true;
            if ($result['note_id'] == self::$note4Id) $foundNote4 = true;
        }
        $this->assertTrue($foundNote1, "Note 1 (apples) not found in search results.");
        $this->assertTrue($foundNote4, "Note 4 (apples) not found in search results.");
    }

    public function testSearchFullTextTermFoundMultiple()
    {
        $params = ['q' => 'oranges', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(2, $response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(2, $response['data']['pagination']['total_items']);

        $foundNote1 = false;
        $foundNote3 = false;
        foreach ($response['data']['data'] as $result) {
            if ($result['note_id'] == self::$note1Id) $foundNote1 = true;
            if ($result['note_id'] == self::$note3Id) $foundNote3 = true;
        }
        $this->assertTrue($foundNote1, "Note 1 (oranges) not found.");
        $this->assertTrue($foundNote3, "Note 3 (oranges) not found.");
    }

    public function testSearchFullTextTermNotFound()
    {
        $params = ['q' => 'zyxwvu', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }
    
    public function testSearchFullTextEmptyQuery()
    {
        $params = ['q' => '', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }


    // --- Test GET /api/search.php?backlinks_for_page_name={name} ---
    public function testSearchBacklinksPageHasBacklinks()
    {
        $params = ['backlinks_for_page_name' => 'Fruit Details', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['total_items']);

        $backlink = $response['data']['data'][0];
        $this->assertEquals(self::$note1Id, $backlink['note_id']);
        $this->assertEquals(self::$page1Id, $backlink['page_id']);
        $this->assertEquals('Landing Page', $backlink['source_page_name']);
        $this->assertStringContainsString('[[<mark>Fruit Details</mark>]]', $backlink['content_snippet']);
    }

    public function testSearchBacklinksPageHasNoBacklinks()
    {
        $params = ['backlinks_for_page_name' => 'Landing Page', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }
    
    public function testSearchBacklinksTargetPageNonExistent()
    {
        $params = ['backlinks_for_page_name' => 'NonExistentPageForBacklinks', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }

    public function testSearchBacklinksEmptyPageName()
    {
        $params = ['backlinks_for_page_name' => '', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }


    // --- Test GET /api/search.php?tasks={status} ---
    public function testSearchTasksTodo()
    {
        $params = ['tasks' => 'todo', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['total_items']);

        $task = $response['data']['data'][0];
        $this->assertEquals(self::$note2Id, $task['note_id']);
        $this->assertEquals('Landing Page', $task['page_name']);
        $this->assertArrayHasKey('properties', $task);
        $this->assertArrayHasKey('status', $task['properties']);
        $this->assertEquals('TODO', $task['properties']['status'][0]['value']);
        $this->assertEquals(0, $task['properties']['status'][0]['internal']);
        $this->assertStringContainsString('TODO', $task['content_snippet']);
    }

    public function testSearchTasksDone()
    {
        $params = ['tasks' => 'done', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['total_items']);

        $task = $response['data']['data'][0];
        $this->assertEquals(self::$note4Id, $task['note_id']);
        $this->assertEquals('Fruit Details', $task['page_name']);
        $this->assertArrayHasKey('properties', $task);
        $this->assertArrayHasKey('status', $task['properties']);
        $this->assertEquals('DONE', $task['properties']['status'][0]['value']);
        $this->assertEquals(0, $task['properties']['status'][0]['internal']);
        $this->assertStringContainsString('DONE', $task['content_snippet']);
    }
    
    public function testSearchTasksNoTasksOfStatus()
    {
        // The API only supports 'todo' and 'done'. Any other value is an invalid status.
        $params = ['tasks' => 'doing', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid task status. Must be "todo" or "done".', $response['message']);
    }

    public function testSearchTasksInvalidStatus()
    {
        $params = ['tasks' => 'invalidstatus', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid task status. Must be "todo" or "done".', $response['message']);
    }
    
    public function testSearchTasksEmptyStatus()
    {
        $params = ['tasks' => '', 'page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Invalid task status. Must be "todo" or "done".', $response['message']);
    }


    // --- Test Failure Case (No search parameter) ---
    public function testSearchNoParameter()
    {
        // Also need to include pagination params even if no search param, or API might reject based on that first
        $params = ['page' => 1, 'per_page' => 10];
        $response = $this->request('GET', '/v1/api/search.php', $params);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing required search parameter: q, backlinks_for_page_name, or tasks', $response['message']);
    }
}
?>
