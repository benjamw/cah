<?php

declare(strict_types=1);

namespace CAH\Tests\Unit;

use CAH\Tests\TestCase;
use CAH\Database\Database;

/**
 * CSV Import Test
 *
 * Tests CSV parsing and import logic for cards with tags
 */
class CsvImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clean up any existing test data
        Database::execute('DELETE FROM cards_to_tags');
        Database::execute('DELETE FROM tags');
        Database::execute('DELETE FROM cards');
    }

    protected function tearDown(): void
    {
        // Clean up test data
        Database::execute('DELETE FROM cards_to_tags');
        Database::execute('DELETE FROM tags');
        Database::execute('DELETE FROM cards');
        
        // Re-seed base test data for other tests
        $this->reseedTestData();
        
        parent::tearDown();
    }
    
    /**
     * Re-seed base test data that other tests depend on
     */
    private function reseedTestData(): void
    {
        $connection = Database::getConnection();
        
        // Reset auto-increment for cards and tags
        $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
        $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");
        
        // Insert test white cards
        $stmt = $connection->prepare("INSERT INTO cards (card_type, value) VALUES ('white', ?)");
        for ($i = 1; $i <= 300; $i++) {
            $stmt->execute([sprintf('White Card %03d', $i)]);
        }
        
        // Insert test black cards
        $stmt = $connection->prepare("INSERT INTO cards (card_type, value, choices) VALUES ('black', ?, ?)");
        for ($i = 1; $i <= 40; $i++) {
            $stmt->execute([sprintf('Black Card %03d with ____.', $i), 1]);
        }
        for ($i = 41; $i <= 55; $i++) {
            $stmt->execute([sprintf('Black Card %03d with ____ and ____.', $i), 2]);
        }
        for ($i = 56; $i <= 70; $i++) {
            $stmt->execute([sprintf('Black Card %03d with ____, ____, and ____.', $i), 3]);
        }
        
        // Insert test tag
        $connection->exec("INSERT INTO tags (name) VALUES ('test_base')");
        $tagId = $connection->lastInsertId();
        
        // Tag all cards
        $totalCards = 370; // 300 white + 70 black
        $stmt = $connection->prepare("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)");
        for ($i = 1; $i <= $totalCards; $i++) {
            $stmt->execute([$i, $tagId]);
        }
    }

    /**
     * Test parsing a CSV line with no tags
     */
    public function testParseLineWithNoTags(): void
    {
        $line = 'This is a test card,,,,,,,,,,';
        $data = str_getcsv($line);
        
        $cardText = trim($data[0]);
        $tagColumns = array_slice($data, 1, 10);
        $tags = [];
        foreach ($tagColumns as $tag) {
            $tag = trim($tag);
            if (!empty($tag)) {
                $tags[] = $tag;
            }
        }

        $this->assertEquals('This is a test card', $cardText);
        $this->assertEmpty($tags);
    }

    /**
     * Test parsing a CSV line with one tag
     */
    public function testParseLineWithOneTag(): void
    {
        $line = 'This is a profane card,Profanity,,,,,,,,,';
        $data = str_getcsv($line);
        
        $cardText = trim($data[0]);
        $tagColumns = array_slice($data, 1, 10);
        $tags = [];
        foreach ($tagColumns as $tag) {
            $tag = trim($tag);
            if (!empty($tag)) {
                $tags[] = $tag;
            }
        }

        $this->assertEquals('This is a profane card', $cardText);
        $this->assertCount(1, $tags);
        $this->assertEquals('Profanity', $tags[0]);
    }

    /**
     * Test parsing a CSV line with multiple tags
     */
    public function testParseLineWithMultipleTags(): void
    {
        $line = 'This is a bad card,Profanity,Sexually Explicit,Violence,,,,,,';
        $data = str_getcsv($line);
        
        $cardText = trim($data[0]);
        $tagColumns = array_slice($data, 1, 10);
        $tags = [];
        foreach ($tagColumns as $tag) {
            $tag = trim($tag);
            if (!empty($tag)) {
                $tags[] = $tag;
            }
        }

        $this->assertEquals('This is a bad card', $cardText);
        $this->assertCount(3, $tags);
        $this->assertEquals(['Profanity', 'Sexually Explicit', 'Violence'], $tags);
    }

    /**
     * Test parsing a CSV line with quoted text containing commas
     */
    public function testParseLineWithQuotedText(): void
    {
        $line = '"A card with, commas in it",Profanity,,,,,,,,,';
        $data = str_getcsv($line);
        
        $cardText = trim($data[0]);
        $tagColumns = array_slice($data, 1, 10);
        $tags = [];
        foreach ($tagColumns as $tag) {
            $tag = trim($tag);
            if (!empty($tag)) {
                $tags[] = $tag;
            }
        }

        $this->assertEquals('A card with, commas in it', $cardText);
        $this->assertCount(1, $tags);
        $this->assertEquals('Profanity', $tags[0]);
    }

    /**
     * Test parsing a CSV line with whitespace in tags
     */
    public function testParseLineWithWhitespaceInTags(): void
    {
        $line = 'Test card,  Profanity  , Sexually Explicit ,,,,,,,';
        $data = str_getcsv($line);
        
        $cardText = trim($data[0]);
        $tagColumns = array_slice($data, 1, 10);
        $tags = [];
        foreach ($tagColumns as $tag) {
            $tag = trim($tag);
            if (!empty($tag)) {
                $tags[] = $tag;
            }
        }

        $this->assertEquals('Test card', $cardText);
        $this->assertCount(2, $tags);
        $this->assertEquals(['Profanity', 'Sexually Explicit'], $tags);
    }
}
