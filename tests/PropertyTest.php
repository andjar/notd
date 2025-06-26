<?php
// tests/PropertyTest.php

use PHPUnit\Framework\TestCase;

class PropertyTest extends TestCase
{
    private $pdo;
    private $dm;

    protected function setUp(): void
    {
        require __DIR__ . '/bootstrap.php';
        $this->pdo = new PDO('sqlite:' . DB_PATH);
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
}
