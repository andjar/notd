<?php
// tests/PageTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\DataManager;

class PageTest extends TestCase
{
    private $pdo;
    private $dm;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite:' . DB_PATH);
        $this->dm = new DataManager($this->pdo);
    }

    public function testGetPages()
    {
        $result = $this->dm->getPages();
        $this->assertCount(1, $result['data']);
        $this->assertEquals('Home', $result['data'][0]['name']);
    }

    public function testJournalPageExclusion()
    {
        // Add a journal page
        $this->pdo->exec("INSERT INTO Pages (name, content) VALUES ('Journal', '{type::journal}')");

        $result = $this->dm->getPages(1, 20, ['exclude_journal' => true]);
        $this->assertCount(1, $result['data']); // Should exclude journal page and only return Home
    }
}
