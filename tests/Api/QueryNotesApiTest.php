<?php
// tests/Api/QueryNotesApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase;
use PDO;

class QueryNotesApiTest extends BaseTestCase
{
    protected static $page1Id;
    protected static $page2Id;
    protected static $note1Id;
    protected static $note2Id;
    protected static $note3Id;
    protected static $note4Id;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$pdo) {
            $this->fail("PDO connection not available in QueryNotesApiTest::setUp");
        }
        
        // Clear data from previous tests
        self::$pdo->exec("DELETE FROM Properties");
        self::$pdo->exec("DELETE FROM Notes");
        self::$pdo->exec("DELETE FROM Pages");

        // Page1
        $stmtPage1 = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmtPage1->execute([':name' => 'Query Test Page 1']);
        self::$page1Id = self::$pdo->lastInsertId();

        // Page2
        $stmtPage2 = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmtPage2->execute([':name' => 'Query Test Page 2']);
        self::$page2Id = self::$pdo->lastInsertId();

        // Note1 (Page1): content "Note Alpha", property status::TODO, tag::Urgent
        $stmtNote1 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote1->execute([':page_id' => self::$page1Id, ':content' => 'Note Alpha with important keywords']);
        self::$note1Id = self::$pdo->lastInsertId();
        $this->addPropertyDirectly(self::$note1Id, 'status', 'TODO');
        $this->addPropertyDirectly(self::$note1Id, 'tag', 'Urgent');

        // Note2 (Page1): content "Note Beta", property status::DONE, tag::Review
        $stmtNote2 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote2->execute([':page_id' => self::$page1Id, ':content' => 'Note Beta for review']);
        self::$note2Id = self::$pdo->lastInsertId();
        $this->addPropertyDirectly(self::$note2Id, 'status', 'DONE');
        $this->addPropertyDirectly(self::$note2Id, 'tag', 'Review');

        // Note3 (Page2): content "Note Gamma", property status::TODO, tag::Later
        $stmtNote3 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote3->execute([':page_id' => self::$page2Id, ':content' => 'Note Gamma to process later']);
        self::$note3Id = self::$pdo->lastInsertId();
        $this->addPropertyDirectly(self::$note3Id, 'status', 'TODO');
        $this->addPropertyDirectly(self::$note3Id, 'tag', 'Later');

        // Note4 (Page2): content "Note Delta", no specific properties for query testing
        $stmtNote4 = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmtNote4->execute([':page_id' => self::$page2Id, ':content' => 'Note Delta, plain content']);
        self::$note4Id = self::$pdo->lastInsertId();
    }

    private function addPropertyDirectly(int $noteId, string $name, string $value, int $internal = 0)
    {
        $sql = "INSERT INTO Properties (note_id, name, value, internal) VALUES (:note_id, :name, :value, :internal)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([
            ':note_id' => $noteId,
            ':name' => $name,
            ':value' => $value,
            ':internal' => $internal
        ]);
    }
    
    protected function tearDown(): void
    {
        if (self::$pdo) {
            self::$pdo->exec("DELETE FROM Properties");
            self::$pdo->exec("DELETE FROM Notes");
            self::$pdo->exec("DELETE FROM Pages");
        }
        parent::tearDown();
    }


    // --- Test POST /api/query_notes.php (Execute Valid Queries) ---

    public function testQueryDirectNotesTable()
    {
        $query = "SELECT id, content FROM Notes WHERE content LIKE '%Beta%'"; // Ensure content is selected for assertion
        $payload = [
            'sql_query' => $query,
            'include_properties' => false,
            'page' => 1,
            'per_page' => 10
        ];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertEquals(self::$note2Id, $response['data']['data'][0]['id']);
        $this->assertEquals('Note Beta for review', $response['data']['data'][0]['content']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertEquals(1, $response['data']['pagination']['total_items']);
    }

    public function testQueryJoinWithProperties()
    {
        $query = "SELECT DISTINCT N.id, N.content FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'status' AND P.value = 'TODO' ORDER BY N.id";
        $payload = [
            'sql_query' => $query,
            'include_properties' => true, // Test property inclusion
            'page' => 1,
            'per_page' => 10
        ];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));
        
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(2, $response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(2, $response['data']['pagination']['total_items']);

        $note1Data = null;
        $note3Data = null;

        foreach($response['data']['data'] as $note) {
            if ($note['id'] == self::$note1Id) $note1Data = $note;
            if ($note['id'] == self::$note3Id) $note3Data = $note;
        }

        $this->assertNotNull($note1Data);
        $this->assertNotNull($note3Data);

        // Check properties for Note1 (status:TODO, tag:Urgent)
        $this->assertArrayHasKey('properties', $note1Data);
        $this->assertArrayHasKey('status', $note1Data['properties']);
        $this->assertEquals('TODO', $note1Data['properties']['status'][0]['value']);
        $this->assertEquals(0, $note1Data['properties']['status'][0]['internal']);
        $this->assertArrayHasKey('tag', $note1Data['properties']);
        $this->assertEquals('Urgent', $note1Data['properties']['tag'][0]['value']);
        
        // Check properties for Note3 (status:TODO, tag:Later)
        $this->assertArrayHasKey('properties', $note3Data);
        $this->assertArrayHasKey('status', $note3Data['properties']);
        $this->assertEquals('TODO', $note3Data['properties']['status'][0]['value']);
        $this->assertArrayHasKey('tag', $note3Data['properties']);
        $this->assertEquals('Later', $note3Data['properties']['tag'][0]['value']);
    }

    public function testQueryJoinWithPropertiesSpecificPage()
    {
        $query = "SELECT DISTINCT N.id, N.content FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE N.page_id = " . self::$page1Id . " AND P.name = 'tag' AND P.value = 'Urgent'";
        $payload = [
            'sql_query' => $query,
            'include_properties' => true,
            'page' => 1,
            'per_page' => 10
        ];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertEquals(self::$note1Id, $response['data']['data'][0]['id']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['total_items']);

        // Verify property structure for the returned note
        $noteData = $response['data']['data'][0];
        $this->assertArrayHasKey('properties', $noteData);
        $this->assertArrayHasKey('tag', $noteData['properties']);
        $this->assertEquals('Urgent', $noteData['properties']['tag'][0]['value']);
        $this->assertEquals(0, $noteData['properties']['tag'][0]['internal']);
        // Also check for 'status' property
        $this->assertArrayHasKey('status', $noteData['properties']);
        $this->assertEquals('TODO', $noteData['properties']['status'][0]['value']);
    }

    public function testQuerySubqueryWithProperties()
    {
        $query = "SELECT id, content FROM Notes WHERE id IN (SELECT note_id FROM Properties WHERE name = 'tag' AND value = 'Review')";
        $payload = [
            'sql_query' => $query,
            'include_properties' => false, // Properties not directly selected, so this primarily affects default fields
            'page' => 1,
            'per_page' => 10
        ];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(1, $response['data']['data']);
        $this->assertEquals(self::$note2Id, $response['data']['data'][0]['id']);
        $this->assertArrayHasKey('pagination', $response['data']);
    }

    public function testQueryReturningNoResults()
    {
        $query = "SELECT id FROM Notes WHERE content LIKE '%NonExistentUniqueContentString%'";
        $payload = [
            'sql_query' => $query,
            'include_properties' => false,
            'page' => 1,
            'per_page' => 10
        ];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertEmpty($response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }

    // --- Test POST /api/query_notes.php (Reject Invalid/Forbidden Queries) ---

    public function testQueryForbiddenKeywords()
    {
        $forbiddenQueries = [
            'DELETE FROM Notes WHERE id = 1',
            'UPDATE Notes SET content = "new" WHERE id = 1',
            'INSERT INTO Notes (content) VALUES ("hacked")',
            'DROP TABLE Notes',
            'SELECT id FROM Notes; DROP TABLE Pages; --'
        ];

        foreach ($forbiddenQueries as $query) {
            $payload = ['sql_query' => $query, 'page' => 1, 'per_page' => 10];
            $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));
            $this->assertEquals('error', $response['status'], "Query should have failed: $query");
            // The exact message can vary based on which validation rule it hits first.
            $this->assertMatchesRegularExpression('/(Query must be one of the allowed patterns|Query contains forbidden SQL keywords|Semicolons are only allowed)/', $response['message'], "Message for query: $query");
        }
    }

    public function testQueryInvalidStructure()
    {
        $invalidQueries = [
            'SELECT * FROM Notes WHERE id = 1', // API expects specific SELECT id patterns
            'SELECT name FROM Pages WHERE id = ' . self::$page1Id, // Not an allowed pattern
        ];

        foreach ($invalidQueries as $query) {
            $payload = ['sql_query' => $query, 'page' => 1, 'per_page' => 10];
            $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));
            $this->assertEquals('error', $response['status'], "Query structure should be invalid: $query");
            $this->assertStringContainsString('Query must be one of the allowed patterns', $response['message']);
        }
    }
    
    public function testQueryUnauthorizedTables()
    {
        // Assuming SomeOtherTable does not exist or is not Notes, Properties, Pages
        // The regex checks for FROM or JOIN followed by something not in the allowed list.
        // This test might be tricky if SomeOtherTable actually exists in schema from another test.
        // For now, let's assume it doesn't or the regex is good enough.
        $query = "SELECT id FROM Notes JOIN SomeOtherTable ON Notes.id = SomeOtherTable.note_id WHERE Notes.id = 1";
        // To make it more robust, let's try to use a system table if possible, e.g. sqlite_master
        // However, the regex might not be smart enough for subqueries referencing other tables.
        // The current regex is: '/\bFROM\s+(?!(?:Notes|Properties|Pages)\b)\w+/i'
        // This means "FROM " followed by a word not in (Notes|Properties|Pages).
        // A query like 'SELECT id FROM Notes WHERE id IN (SELECT user_id FROM Users WHERE name = "admin")'
        // would NOT be caught by this specific regex, but would be by the overall pattern match.
        // The overall pattern match is stricter.
        
        // This query will fail the overall pattern match first.
        $payload = ['sql_query' => $query, 'page' => 1, 'per_page' => 10];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Query must be one of the allowed patterns', $response['message']);

        // A query that passes initial pattern but uses a forbidden table in a sub-query
        // This is harder to catch with current validation which focuses on the main FROM/JOIN.
        // The current script's validation might not catch all sub-query table abuses if the main query fits a pattern.
        // Example: SELECT id FROM Notes WHERE id IN (SELECT note_id FROM Properties WHERE value IN (SELECT secret FROM secrets_table))
        // This specific case is not explicitly tested as the current validation is pattern-based for the main query.
    }


    public function testQuerySqlComments()
    {
        $queriesWithComments = [
            // "SELECT id FROM Notes WHERE id = 1; -- comment" // This is caught by semicolon check first.
            "SELECT id FROM Notes WHERE id = 1 -- comment", // No semicolon, but still a comment
            "SELECT id FROM Notes WHERE id = 1 /* comment */",
        ];
        
        // Test the one without semicolon first, as it's a direct comment violation
        $payloadComment = ['sql_query' => $queriesWithComments[0], 'page' => 1, 'per_page' => 10];
        $responseComment = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payloadComment));
        $this->assertEquals('error', $responseComment['status']);
        $this->assertStringContainsString('Query contains forbidden SQL keywords/characters or comments', $responseComment['message']);
        $this->assertStringContainsString('--', $responseComment['message']);

        $payloadBlockComment = ['sql_query' => $queriesWithComments[1], 'page' => 1, 'per_page' => 10];
        $responseBlockComment = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payloadBlockComment));
        $this->assertEquals('error', $responseBlockComment['status']);
        $this->assertStringContainsString('Query contains forbidden SQL keywords/characters or comments', $responseBlockComment['message']);
        $this->assertStringContainsString('/*', $responseBlockComment['message']);
        
        // Test semicolon not at very end (which also implies a comment or second statement)
        $payloadSemicolonComment = ['sql_query' => "SELECT id FROM Notes WHERE id = 1; -- comment", 'page' => 1, 'per_page' => 10];
        $responseSemicolonComment = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payloadSemicolonComment));
        $this->assertEquals('error', $responseSemicolonComment['status']);
        $this->assertEquals('Semicolons are only allowed at the very end of the query.', $responseSemicolonComment['message']);

    }

    public function testQueryMultipleStatements()
    {
        $query = "SELECT id FROM Notes WHERE id = 1; SELECT id FROM Notes WHERE id = 2";
        $payload = ['sql_query' => $query, 'page' => 1, 'per_page' => 10];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Semicolons are only allowed at the very end of the query.', $response['message']);
    }

    // --- Failure Case (Missing sql_query parameter) ---
    public function testQueryMissingSqlParameter()
    {
        // Payload missing sql_query key
        $payloadMissing = ['include_properties' => false, 'page' => 1, 'per_page' => 10];
        $response = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payloadMissing));
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing or empty sql_query parameter in JSON payload.', $response['message']);

        // Empty sql_query value
        $payloadEmpty = ['sql_query' => '', 'include_properties' => false, 'page' => 1, 'per_page' => 10];
        $responseEmpty = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payloadEmpty));
        $this->assertEquals('error', $responseEmpty['status']);
        $this->assertEquals('Missing or empty sql_query parameter in JSON payload.', $responseEmpty['message']);
    }

    public function testQueryPagination()
    {
        // We have 4 notes. Let's query all of them and test pagination.
        // Note: ORDER BY is important for pagination consistency.
        $query = "SELECT id, content FROM Notes ORDER BY id ASC"; 
        
        // Page 1, 2 per page
        $payload1 = ['sql_query' => $query, 'include_properties' => false, 'page' => 1, 'per_page' => 2];
        $response1 = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload1));
        $this->assertEquals('success', $response1['status']);
        $this->assertCount(2, $response1['data']['data']);
        $this->assertEquals(self::$note1Id, $response1['data']['data'][0]['id']);
        $this->assertEquals(self::$note2Id, $response1['data']['data'][1]['id']);
        $this->assertEquals(1, $response1['data']['pagination']['current_page']);
        $this->assertEquals(2, $response1['data']['pagination']['per_page']);
        $this->assertEquals(4, $response1['data']['pagination']['total_items']);
        $this->assertEquals(2, $response1['data']['pagination']['total_pages']);

        // Page 2, 2 per page
        $payload2 = ['sql_query' => $query, 'include_properties' => false, 'page' => 2, 'per_page' => 2];
        $response2 = $this->request('POST', '/v1/api/query_notes.php', [], [], json_encode($payload2));
        $this->assertEquals('success', $response2['status']);
        $this->assertCount(2, $response2['data']['data']);
        $this->assertEquals(self::$note3Id, $response2['data']['data'][0]['id']);
        $this->assertEquals(self::$note4Id, $response2['data']['data'][1]['id']);
        $this->assertEquals(2, $response2['data']['pagination']['current_page']);
        $this->assertEquals(4, $response2['data']['pagination']['total_items']);
    }
}
?>
