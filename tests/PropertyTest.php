<?php
// tests/PropertyTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\DataManager;

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
        // Add two system properties with same name and weight=4 (should append)
        $this->pdo->exec("INSERT INTO Properties (note_id, name, value, weight) VALUES (1, 'log', 'entry1', 4)");
        $this->pdo->exec("INSERT INTO Properties (note_id, name, value, weight) VALUES (1, 'log', 'entry2', 4)");

        $props = $this->dm->getNoteProperties(1, true);
        $this->assertCount(2, $props['log']);
    }

    public function testPropertyWeightConfiguration()
    {
        $internalProps = $this->dm->getNoteProperties(1, false);
        $this->assertArrayNotHasKey('internal', $internalProps); // weight=3 should be hidden unless requested

        $allProps = $this->dm->getNoteProperties(1, true);
        $this->assertArrayHasKey('internal', $allProps);
    }

    public function testMultiplePropertiesWithSameName()
    {
        // Test that multiple properties with the same name are all preserved
        // This was the bug we fixed where only the last property was retained
        
        // Create a test page with multiple properties of the same name
        $this->pdo->exec("INSERT INTO Pages (name, content) VALUES ('Multiple Props Test', 'Test content')");
        $pageId = $this->pdo->lastInsertId();
        
        // Add multiple properties with the same name but different values
        $this->pdo->exec("INSERT INTO Properties (page_id, name, value, weight) VALUES ($pageId, 'favorite', 'true', 2)");
        $this->pdo->exec("INSERT INTO Properties (page_id, name, value, weight) VALUES ($pageId, 'favorite', 'false', 2)");
        $this->pdo->exec("INSERT INTO Properties (page_id, name, value, weight) VALUES ($pageId, 'type', 'person', 2)");
        $this->pdo->exec("INSERT INTO Properties (page_id, name, value, weight) VALUES ($pageId, 'type', 'journal', 2)");
        
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
        require_once __DIR__ . '/../api/pattern_processor.php';
        
        // Create a test page
        $this->pdo->exec("INSERT INTO Pages (name, content) VALUES ('Extraction Test', 'Test content')");
        $pageId = $this->pdo->lastInsertId();
        
        // Test content with multiple properties of the same name
        $testContent = "{favorite::true} {type::person} {favorite::false} {type::journal}";
        
        // Process content through pattern processor
        $patternProcessor = new \PatternProcessor($this->pdo);
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
        $this->pdo->exec("INSERT INTO Pages (name, content) VALUES ('Replace Test', 'Test content')");
        $pageId = $this->pdo->lastInsertId();
        
        // Add initial properties
        $this->pdo->exec("INSERT INTO Properties (page_id, name, value, weight) VALUES ($pageId, 'status', 'old', 2)");
        $this->pdo->exec("INSERT INTO Properties (page_id, name, value, weight) VALUES ($pageId, 'priority', 'low', 2)");
        
        // Simulate content update with new properties (replace behavior)
        $newContent = "{status::new} {priority::high} {status::active}";
        
        require_once __DIR__ . '/../api/pattern_processor.php';
        $patternProcessor = new \PatternProcessor($this->pdo);
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
