<?php
// tests/DataManagerTest.php

use PHPUnit\Framework\TestCase;

class DataManagerTest extends TestCase
{
    private $pdo;
    private $dm;

    protected function setUp(): void
    {
        require __DIR__ . '/bootstrap.php';
        $this->pdo = new PDO('sqlite:' . DB_PATH);
        $this->dm = new DataManager($this->pdo);
    }

    public function testGetPageById()
    {
        $page = $this->dm->getPageById(1);
        $this->assertEquals('Home', $page['name']);
        $this->assertArrayHasKey('properties', $page);
    }

    public function testGetNoteProperties()
    {
        $props = $this->dm->getNoteProperties(1);
        $this->assertArrayHasKey('status', $props);
        $this->assertEquals('TODO', $props['status'][0]['value']);
    }

    public function testParentPropertiesInheritance()
    {
        // Create parent note
        $this->pdo->exec("INSERT INTO Notes (page_id, content) VALUES (1, 'Parent Note')");
        $parentId = $this->pdo->lastInsertId();

        // Add property to parent
        $this->pdo->exec("INSERT INTO Properties (note_id, name, value) VALUES ($parentId, 'inherited', 'parent-value')");

        // Create child note with parent
        $this->pdo->exec("INSERT INTO Notes (page_id, parent_note_id, content) VALUES (1, $parentId, 'Child Note')");
        $childId = $this->pdo->lastInsertId();

        // Retrieve child with parent properties
        $child = $this->dm->getNoteById($childId, true, true);

        $this->assertArrayHasKey('parent_properties', $child);
        $this->assertEquals('parent-value', $child['parent_properties']['inherited'][0]['value']);
    }

    public function testPropertyVisibilityRules()
    {
        $props = $this->dm->getNoteProperties(1, false); // exclude internal
        $this->assertArrayHasKey('status', $props);
        $this->assertArrayNotHasKey('internal', $props);
    }

    // Add other tests for your DataManager methods as needed...
}
