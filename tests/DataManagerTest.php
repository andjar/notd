<?php
// tests/DataManagerTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\DataManager;

class DataManagerTest extends TestCase
{
    private $pdo;
    private $dm;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite:' . DB_PATH);
        $this->dm = new DataManager($this->pdo);
    }

    public function testGetPageById()
    {
        $stmt = $this->pdo->query("SELECT id FROM Pages WHERE name = 'Home'");
        $pageId = $stmt->fetchColumn();

        $page = $this->dm->getPageById($pageId);
        $this->assertEquals('Home', $page['name']);
        $this->assertArrayHasKey('properties', $page);
    }

    public function testGetNoteProperties()
    {
        $stmt = $this->pdo->query("SELECT id FROM Notes WHERE content = 'First note'");
        $noteId = $stmt->fetchColumn();

        $props = $this->dm->getNoteProperties($noteId);
        $this->assertArrayHasKey('status', $props);
        $this->assertEquals('TODO', $props['status'][0]['value']);
    }

    public function testParentPropertiesInheritance()
    {
        $stmt = $this->pdo->query("SELECT id FROM Pages WHERE name = 'Home'");
        $pageId = $stmt->fetchColumn();

        // Create parent note
        $parentId = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Notes (id, page_id, content) VALUES ('$parentId', '$pageId', 'Parent Note')");

        // Add property to parent
        $this->pdo->exec("INSERT INTO Properties (note_id, name, value) VALUES ('$parentId', 'inherited', 'parent-value')");

        // Create child note with parent
        $childId = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Notes (id, page_id, parent_note_id, content) VALUES ('$childId', '$pageId', '$parentId', 'Child Note')");

        // Retrieve child with parent properties
        $child = $this->dm->getNoteById($childId, true, true);

        $this->assertArrayHasKey('parent_properties', $child);
        $this->assertEquals('parent-value', $child['parent_properties']['inherited'][0]['value']);
    }

    public function testPropertyVisibilityRules()
    {
        $stmt = $this->pdo->query("SELECT id FROM Notes WHERE content = 'First note'");
        $noteId = $stmt->fetchColumn();
        
        $props = $this->dm->getNoteProperties($noteId, false); // exclude internal
        $this->assertArrayHasKey('status', $props);
        $this->assertArrayNotHasKey('internal', $props);
    }

    // Add other tests for your DataManager methods as needed...
}
