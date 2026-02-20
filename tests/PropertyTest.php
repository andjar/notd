<?php
// tests/PropertyTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\DataManager;
use App\PatternProcessor;

class PropertyTest extends TestCase
{
    private $pdo;
    private $dm;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite:' . DB_PATH);
        $this->dm = new DataManager($this->pdo);
    }

    public function testSystemPropertyAppendBehavior()
    {
        // Get the first note ID from the seeded data
        $stmt = $this->pdo->query("SELECT id FROM Notes WHERE content = 'First note'");
        $noteId = $stmt->fetchColumn();
        
        // Add two system properties with same name and weight=4 (should append)
        $prop1Id = \App\UuidUtils::generateUuidV7();
        $prop2Id = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Properties (id, note_id, name, value, weight) VALUES ('$prop1Id', '$noteId', 'log', 'entry1', 4)");
        $this->pdo->exec("INSERT INTO Properties (id, note_id, name, value, weight) VALUES ('$prop2Id', '$noteId', 'log', 'entry2', 4)");

        $props = $this->dm->getNoteProperties($noteId, true);
        $this->assertCount(2, $props['log']);
    }

    public function testPropertyWeightConfiguration()
    {
        // Get the first note ID from the seeded data
        $stmt = $this->pdo->query("SELECT id FROM Notes WHERE content = 'First note'");
        $noteId = $stmt->fetchColumn();
        
        $internalProps = $this->dm->getNoteProperties($noteId, false);
        $this->assertArrayNotHasKey('internal', $internalProps); // weight=3 should be hidden unless requested

        $allProps = $this->dm->getNoteProperties($noteId, true);
        $this->assertArrayHasKey('internal', $allProps);
    }

    public function testMultiplePropertiesWithSameName()
    {
        // Test that multiple properties with the same name are all preserved
        // This was the bug we fixed where only the last property was retained
        
        // Create a test page with multiple properties of the same name
        $pageId = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Pages (id, name, content) VALUES ('$pageId', 'Multiple Props Test', 'Test content')");
        
        // Add multiple properties with the same name but different values
        $prop1Id = \App\UuidUtils::generateUuidV7();
        $prop2Id = \App\UuidUtils::generateUuidV7();
        $prop3Id = \App\UuidUtils::generateUuidV7();
        $prop4Id = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Properties (id, page_id, name, value, weight) VALUES ('$prop1Id', '$pageId', 'favorite', 'true', 2)");
        $this->pdo->exec("INSERT INTO Properties (id, page_id, name, value, weight) VALUES ('$prop2Id', '$pageId', 'favorite', 'false', 2)");
        $this->pdo->exec("INSERT INTO Properties (id, page_id, name, value, weight) VALUES ('$prop3Id', '$pageId', 'type', 'person', 2)");
        $this->pdo->exec("INSERT INTO Properties (id, page_id, name, value, weight) VALUES ('$prop4Id', '$pageId', 'type', 'journal', 2)");
        
        // Get page properties
        $page = $this->dm->getPageById($pageId);
        $properties = $page['properties'];
        
        // Verify all properties are present
        $this->assertArrayHasKey('favorite', $properties, 'favorite property should exist');
        $this->assertArrayHasKey('type', $properties, 'type property should exist');
        
        // Verify multiple values for favorite property
        $this->assertCount(2, $properties['favorite'], 'favorite property should have 2 values');
        $favoriteValues = array_column($properties['favorite'], 'value');
        $this->assertContains('true', $favoriteValues, 'favorite should contain true');
        $this->assertContains('false', $favoriteValues, 'favorite should contain false');
        
        // Verify multiple values for type property
        $this->assertCount(2, $properties['type'], 'type property should have 2 values');
        $typeValues = array_column($properties['type'], 'value');
        $this->assertContains('person', $typeValues, 'type should contain person');
        $this->assertContains('journal', $typeValues, 'type should contain journal');
    }

    public function testPropertyExtractionAndSaving()
    {
        // Test the complete flow: content extraction -> property saving -> retrieval
        require_once __DIR__ . '/../api/PatternProcessor.php';
        
        // Create a test page
        $pageId = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Pages (id, name, content) VALUES ('$pageId', 'Extraction Test', 'Test content')");
        
        // Test content with multiple properties of the same name
        $testContent = "{favorite::true} {type::person} {favorite::false} {type::journal}";
        
        // Process content through pattern processor
        $patternProcessor = new PatternProcessor($this->pdo);
        $result = $patternProcessor->processContent($testContent, 'page', $pageId, ['pdo' => $this->pdo]);
        
        // Verify all properties were extracted
        $this->assertCount(4, $result['properties'], 'Should extract 4 properties');
        
        // Save properties
        $patternProcessor->saveProperties($result['properties'], 'page', $pageId);
        
        // Verify properties were saved to database
        $stmt = $this->pdo->prepare("SELECT name, value, weight FROM Properties WHERE page_id = ? ORDER BY name, value");
        $stmt->execute([$pageId]);
        $savedProperties = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(4, $savedProperties, 'Should have 4 properties in database');
        
        // Verify specific properties
        $propertyMap = [];
        foreach ($savedProperties as $prop) {
            $propertyMap[$prop['name']][] = $prop['value'];
        }
        
        $this->assertArrayHasKey('favorite', $propertyMap, 'favorite property should be saved');
        $this->assertArrayHasKey('type', $propertyMap, 'type property should be saved');
        $this->assertCount(2, $propertyMap['favorite'], 'favorite should have 2 values');
        $this->assertCount(2, $propertyMap['type'], 'type should have 2 values');
        $this->assertContains('true', $propertyMap['favorite'], 'favorite should contain true');
        $this->assertContains('false', $propertyMap['favorite'], 'favorite should contain false');
        $this->assertContains('person', $propertyMap['type'], 'type should contain person');
        $this->assertContains('journal', $propertyMap['type'], 'type should contain journal');
    }

    public function testPropertyReplacementBehavior()
    {
        // Test that replace behavior correctly handles multiple properties
        // Create a page with initial properties
        $pageId = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Pages (id, name, content) VALUES ('$pageId', 'Replace Test', 'Test content')");
        
        // Add initial properties
        $prop1Id = \App\UuidUtils::generateUuidV7();
        $prop2Id = \App\UuidUtils::generateUuidV7();
        $this->pdo->exec("INSERT INTO Properties (id, page_id, name, value, weight) VALUES ('$prop1Id', '$pageId', 'status', 'old', 2)");
        $this->pdo->exec("INSERT INTO Properties (id, page_id, name, value, weight) VALUES ('$prop2Id', '$pageId', 'priority', 'low', 2)");
        
        // Simulate content update with new properties (replace behavior)
        $newContent = "{status::new} {priority::high} {status::active}";
        
        require_once __DIR__ . '/../api/PatternProcessor.php';
        $patternProcessor = new PatternProcessor($this->pdo);
        $result = $patternProcessor->processContent($newContent, 'page', $pageId, ['pdo' => $this->pdo]);
        
        // Save new properties (this should replace old ones)
        $patternProcessor->saveProperties($result['properties'], 'page', $pageId);
        
        // Verify old properties are gone and new ones are present
        $stmt = $this->pdo->prepare("SELECT name, value FROM Properties WHERE page_id = ? ORDER BY name, value");
        $stmt->execute([$pageId]);
        $finalProperties = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $propertyMap = [];
        foreach ($finalProperties as $prop) {
            $propertyMap[$prop['name']][] = $prop['value'];
        }
        
        // Old values should be gone
        $this->assertArrayNotHasKey('old', $propertyMap['status'] ?? [], 'Old status should be replaced');
        $this->assertArrayNotHasKey('low', $propertyMap['priority'] ?? [], 'Old priority should be replaced');
        
        // New values should be present
        $this->assertContains('new', $propertyMap['status'] ?? [], 'New status should be present');
        $this->assertContains('active', $propertyMap['status'] ?? [], 'Active status should be present');
        $this->assertContains('high', $propertyMap['priority'] ?? [], 'High priority should be present');
    }
}
