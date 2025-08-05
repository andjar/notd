<?php

use PHPUnit\Framework\TestCase;

class TransclusionChildrenTest extends TestCase {
    private $pdo;
    private $dataManager;

    protected function setUp(): void {
        // Include necessary files
        require_once __DIR__ . '/../api/db_connect.php';
        require_once __DIR__ . '/../api/DataManager.php';
        require_once __DIR__ . '/../api/UuidUtils.php';
        require_once __DIR__ . '/../config.php';

        // Create test database
        $this->pdo = get_db_connection();
        
        // Load schema
        $schema = file_get_contents(__DIR__ . '/../db/schema.sql');
        $this->pdo->exec($schema);
        
        $this->dataManager = new \App\DataManager($this->pdo);
    }

    protected function tearDown(): void {
        $this->pdo = null;
    }

    private function createTestPageAndNotes() {
        // Create a test page with unique name
        $pageId = \App\UuidUtils::generateUuidV7();
        $uniquePageName = 'Test Page ' . uniqid();
        $pageStmt = $this->pdo->prepare("INSERT INTO Pages (id, name, content, active) VALUES (?, ?, ?, 1)");
        $pageStmt->execute([$pageId, $uniquePageName, 'Test page content']);

        // Create parent note
        $parentId = \App\UuidUtils::generateUuidV7();
        $parentStmt = $this->pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index, active) VALUES (?, ?, ?, ?, 1)");
        $parentStmt->execute([$parentId, $pageId, 'Parent note content', 1]);

        // Create child note 1
        $child1Id = \App\UuidUtils::generateUuidV7();
        $child1Stmt = $this->pdo->prepare("INSERT INTO Notes (id, page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, ?, 1)");
        $child1Stmt->execute([$child1Id, $pageId, $parentId, 'Child 1 content', 1]);

        // Create child note 2
        $child2Id = \App\UuidUtils::generateUuidV7();
        $child2Stmt = $this->pdo->prepare("INSERT INTO Notes (id, page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, ?, 1)");
        $child2Stmt->execute([$child2Id, $pageId, $parentId, 'Child 2 content', 2]);

        // Create grandchild note
        $grandchildId = \App\UuidUtils::generateUuidV7();
        $grandchildStmt = $this->pdo->prepare("INSERT INTO Notes (id, page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, ?, 1)");
        $grandchildStmt->execute([$grandchildId, $pageId, $child1Id, 'Grandchild content', 1]);

        return [
            'pageId' => $pageId,
            'parentId' => $parentId,
            'child1Id' => $child1Id,
            'child2Id' => $child2Id,
            'grandchildId' => $grandchildId
        ];
    }

    public function testGetNoteWithChildrenReturnsParentAndChildren() {
        $data = $this->createTestPageAndNotes();
        
        $noteWithChildren = $this->dataManager->getNoteWithChildren($data['parentId']);
        
        $this->assertNotNull($noteWithChildren);
        $this->assertEquals('Parent note content', $noteWithChildren['content']);
        $this->assertArrayHasKey('children', $noteWithChildren);
        $this->assertCount(2, $noteWithChildren['children']);
        
        // Check first child
        $this->assertEquals('Child 1 content', $noteWithChildren['children'][0]['content']);
        $this->assertArrayHasKey('children', $noteWithChildren['children'][0]);
        $this->assertCount(1, $noteWithChildren['children'][0]['children']);
        
        // Check grandchild
        $this->assertEquals('Grandchild content', $noteWithChildren['children'][0]['children'][0]['content']);
        $this->assertArrayHasKey('children', $noteWithChildren['children'][0]['children'][0]);
        $this->assertCount(0, $noteWithChildren['children'][0]['children'][0]['children']);
        
        // Check second child
        $this->assertEquals('Child 2 content', $noteWithChildren['children'][1]['content']);
        $this->assertArrayHasKey('children', $noteWithChildren['children'][1]);
        $this->assertCount(0, $noteWithChildren['children'][1]['children']);
    }

    public function testGetNoteWithChildrenWithMaxDepthLimit() {
        $data = $this->createTestPageAndNotes();
        
        // Test with depth limit of 2 (should include parent + children but not grandchildren)
        $noteWithChildren = $this->dataManager->getNoteWithChildren($data['parentId'], false, false, 2);
        
        $this->assertNotNull($noteWithChildren);
        $this->assertEquals('Parent note content', $noteWithChildren['content']);
        $this->assertArrayHasKey('children', $noteWithChildren);
        $this->assertCount(2, $noteWithChildren['children']);
        
        // Children should not have their children loaded due to depth limit
        $this->assertEquals('Child 1 content', $noteWithChildren['children'][0]['content']);
        $this->assertArrayHasKey('children', $noteWithChildren['children'][0]);
        $this->assertCount(0, $noteWithChildren['children'][0]['children']);
    }

    public function testGetNoteWithChildrenCircularReferenceProtection() {
        $data = $this->createTestPageAndNotes();
        
        // Create a circular reference by making parent's parent the child
        $updateStmt = $this->pdo->prepare("UPDATE Notes SET parent_note_id = ? WHERE id = ?");
        $updateStmt->execute([$data['grandchildId'], $data['parentId']]);
        
        // This should not cause infinite recursion
        $noteWithChildren = $this->dataManager->getNoteWithChildren($data['parentId']);
        
        $this->assertNotNull($noteWithChildren);
        // The function should handle circular reference gracefully
        $this->assertEquals('Parent note content', $noteWithChildren['content']);
    }

    public function testGetNoteWithChildrenNonexistentNote() {
        $noteWithChildren = $this->dataManager->getNoteWithChildren('00000000-0000-0000-0000-000000000000');
        
        $this->assertNull($noteWithChildren);
    }

    public function testGetNoteWithChildrenLeafNote() {
        $data = $this->createTestPageAndNotes();
        
        // Get a leaf note (grandchild)
        $noteWithChildren = $this->dataManager->getNoteWithChildren($data['grandchildId']);
        
        $this->assertNotNull($noteWithChildren);
        $this->assertEquals('Grandchild content', $noteWithChildren['content']);
        $this->assertArrayHasKey('children', $noteWithChildren);
        $this->assertCount(0, $noteWithChildren['children']);
    }
}