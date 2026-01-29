<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\CardImportService;
use CAH\Models\Tag;
use CAH\Models\Card;
use CAH\Enums\CardType;
use CAH\Database\Database;

/**
 * Card Import Integration Test
 *
 * Tests the full card import process including database operations
 */
class CardImportTest extends TestCase
{
    private static bool $needsReseed = false;
    
    protected function setUp(): void
    {
        parent::setUp();
        // Don't delete between tests - let each test use unique names
        if ( ! self::$needsReseed) {
            // Only delete once at the start
            Database::execute('DELETE FROM cards_to_tags');
            Database::execute('DELETE FROM tags');
            Database::execute('DELETE FROM cards');
            self::$needsReseed = true;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
    
    public static function tearDownAfterClass(): void
    {
        // Re-seed base test data once after all tests in this class complete
        if (self::$needsReseed) {
            $connection = Database::getConnection();

            // Clean up all test data first (in reverse order of dependencies)
            $connection->exec("DELETE FROM cards_to_tags");
            $connection->exec("DELETE FROM tags");
            $connection->exec("DELETE FROM cards");

            // Reset auto-increment for cards and tags
            $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
            $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");

            // Insert test response cards
            $stmt = $connection->prepare("INSERT INTO cards (type, copy) VALUES ('response', ?)");
            for ($i = 1; $i <= 300; $i++) {
                $stmt->execute([sprintf('White Card %03d', $i)]);
            }

            // Insert test prompt cards
            $stmt = $connection->prepare("INSERT INTO cards (type, copy, choices) VALUES ('prompt', ?, ?)");
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
            $totalCards = 370; // 300 response + 70 prompt
            $stmt = $connection->prepare("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)");
            for ($i = 1; $i <= $totalCards; $i++) {
                $stmt->execute([$i, $tagId]);
            }

            self::$needsReseed = false;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Test importing a card without tags
     */
    public function testImportCardWithoutTags(): void
    {
        $cardText = 'This is a test prompt card with ____.';
        $cardId = CardImportService::importCard(CardType::PROMPT, $cardText);

        $this->assertNotNull($cardId);
        $this->assertIsInt($cardId);

        // Verify card was created
        $card = Card::getById($cardId);
        $this->assertNotNull($card);
        $this->assertEquals($cardText, $card['copy']);
        $this->assertEquals('prompt', $card['type']);
    }

    /**
     * Test importing a card and adding tags
     */
    public function testImportCardWithTags(): void
    {
        // Import card
        $cardText = 'This is a profane card.';
        $cardId = CardImportService::importCard(CardType::RESPONSE, $cardText);
        $this->assertNotNull($cardId);

        // Create tags with unique names for this test
        $profanityTagId = Tag::create('Profanity_withTags', null, true);
        $violenceTagId = Tag::create('Violence_withTags', null, true);

        // Add tags to card
        Tag::addToCard($cardId, $profanityTagId);
        Tag::addToCard($cardId, $violenceTagId);

        // Verify tags were added
        $tags = Tag::getCardTags($cardId);
        $this->assertCount(2, $tags);

        $tagNames = array_column($tags, 'name');
        $this->assertContains('Profanity_withTags', $tagNames);
        $this->assertContains('Violence_withTags', $tagNames);
    }

    /**
     * Test that duplicate tags are not created
     */
    public function testDuplicateTagsNotCreated(): void
    {
        // Create a tag with unique name for this test
        $tagId1 = Tag::create('Profanity_duplicateTest', null, true);
        
        // Try to find existing tag (simulating import logic)
        $existingTags = Tag::getAll();
        $tagId2 = null;
        foreach ($existingTags as $existingTag) {
            if (strcasecmp($existingTag['name'], 'Profanity_duplicateTest') === 0) {
                $tagId2 = $existingTag['tag_id'];
                break;
            }
        }

        $this->assertEquals($tagId1, $tagId2);
        
        // Verify only one tag exists
        $allTags = Tag::getAll();
        $profanityTags = array_filter($allTags, function($tag) {
            return strcasecmp($tag['name'], 'Profanity_duplicateTest') === 0;
        });
        $this->assertCount(1, $profanityTags);
    }

    /**
     * Test case-insensitive tag matching
     */
    public function testCaseInsensitiveTagMatching(): void
    {
        // Create a tag with mixed case and unique name for this test
        $tagId1 = Tag::create('Profanity_caseTest', null, true);
        
        // Try to find with different case
        $existingTags = Tag::getAll();
        $tagId2 = null;
        foreach ($existingTags as $existingTag) {
            if (strcasecmp($existingTag['name'], 'PROFANITY_CASETEST') === 0) {
                $tagId2 = $existingTag['tag_id'];
                break;
            }
        }

        $this->assertEquals($tagId1, $tagId2);
    }

    /**
     * Test importing multiple cards with shared tags
     */
    public function testImportMultipleCardsWithSharedTags(): void
    {
        // Create a shared tag with unique name for this test
        $profanityTagId = Tag::create('Profanity_multipleCards', null, true);

        // Import two cards
        $cardId1 = CardImportService::importCard(CardType::RESPONSE, 'First profane card');
        $cardId2 = CardImportService::importCard(CardType::RESPONSE, 'Second profane card');

        // Add same tag to both cards
        Tag::addToCard($cardId1, $profanityTagId);
        Tag::addToCard($cardId2, $profanityTagId);

        // Verify both cards have the tag
        $tags1 = Tag::getCardTags($cardId1);
        $tags2 = Tag::getCardTags($cardId2);

        $this->assertCount(1, $tags1);
        $this->assertCount(1, $tags2);
        $this->assertEquals('Profanity_multipleCards', $tags1[0]['name']);
        $this->assertEquals('Profanity_multipleCards', $tags2[0]['name']);

        // Verify only one tag exists in database
        $allTags = Tag::getAll();
        $profanityTags = array_filter($allTags, function($tag) {
            return strcasecmp($tag['name'], 'Profanity_multipleCards') === 0;
        });
        $this->assertCount(1, $profanityTags);
    }

    /**
     * Test importing CSV with no tags
     */
    public function testImportCsvWithNoTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "This is a test card,,,,,,,,,,\n";
        $csvContent .= "Another test card,,,,,,,,,,\n";

        $lines = str_getcsv($csvContent, "\n");
        $imported = 0;

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue; // Skip header
            }

            $data = str_getcsv($line);
            if (empty($data[0])) {
                continue;
            }

            $cardText = trim($data[0]);

            // Get tags from columns 1-10, trim and filter out empty values
            $tagColumns = array_slice($data, 1, 10);
            $tags = [];
            foreach ($tagColumns as $tag) {
                $tag = trim($tag);
                if ( ! empty($tag)) {
                    $tags[] = $tag;
                }
            }

            $cardId = CardImportService::importCard(CardType::RESPONSE, $cardText);
            $this->assertNotNull($cardId);
            $this->assertEmpty($tags);
            $imported++;
        }

        $this->assertEquals(2, $imported);
    }

    /**
     * Test importing CSV with tags
     */
    public function testImportCsvWithTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "A profane card,Profanity,,,,,,,,,\n";
        $csvContent .= "A violent and sexual card,Violence,Sexually Explicit,,,,,,,,\n";
        $csvContent .= "A clean card,,,,,,,,,,\n";

        $lines = str_getcsv($csvContent, "\n");
        $imported = 0;
        $cardIds = [];

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue; // Skip header
            }

            $data = str_getcsv($line);
            if (empty($data[0])) {
                continue;
            }

            $cardText = trim($data[0]);

            // Get tags from columns 1-10, trim and filter out empty values
            $tagColumns = array_slice($data, 1, 10);
            $tags = [];
            foreach ($tagColumns as $tag) {
                $tag = trim($tag);
                if ( ! empty($tag)) {
                    $tags[] = $tag;
                }
            }

            $cardId = CardImportService::importCard(CardType::RESPONSE, $cardText);
            $this->assertNotNull($cardId);

            // Add tags if any
            if ( ! empty($tags)) {
                foreach ($tags as $tagName) {
                    // Find or create tag
                    $existingTags = Tag::getAll();
                    $tagId = null;
                    foreach ($existingTags as $existingTag) {
                        if (strcasecmp($existingTag['name'], $tagName) === 0) {
                            $tagId = $existingTag['tag_id'];
                            break;
                        }
                    }

                    if ( ! $tagId) {
                        $tagId = Tag::create($tagName, null, true);
                    }

                    Tag::addToCard($cardId, $tagId);
                }
            }

            $cardIds[] = ['id' => $cardId, 'expected_tag_count' => count($tags)];
            $imported++;
        }

        $this->assertEquals(3, $imported);

        // Verify first card has 1 tag
        $tags1 = Tag::getCardTags($cardIds[0]['id']);
        $this->assertCount(1, $tags1);
        $this->assertEquals('Profanity', $tags1[0]['name']);

        // Verify second card has 2 tags
        $tags2 = Tag::getCardTags($cardIds[1]['id']);
        $this->assertCount(2, $tags2);
        $tagNames = array_column($tags2, 'name');
        $this->assertContains('Violence', $tagNames);
        $this->assertContains('Sexually Explicit', $tagNames);

        // Verify third card has no tags
        $tags3 = Tag::getCardTags($cardIds[2]['id']);
        $this->assertCount(0, $tags3);
    }

    /**
     * Test importing CSV with quoted text containing commas
     */
    public function testImportCsvWithQuotedText(): void
    {
        // Test individual CSV lines with quoted text
        $line1 = '"A card with, commas in it",Profanity,,,,,,,,,';
        $line2 = '"Another card, with commas, and more commas",Violence,Sexually Explicit,,,,,,,,';

        $data1 = str_getcsv($line1);
        $cardText1 = trim($data1[0]);
        $this->assertEquals('A card with, commas in it', $cardText1);

        $data2 = str_getcsv($line2);
        $cardText2 = trim($data2[0]);
        $this->assertEquals('Another card, with commas, and more commas', $cardText2);

        // Import the cards
        $cardId1 = CardImportService::importCard(CardType::RESPONSE, $cardText1);
        $cardId2 = CardImportService::importCard(CardType::RESPONSE, $cardText2);

        $this->assertNotNull($cardId1);
        $this->assertNotNull($cardId2);

        // Verify cards were created with correct text
        $card1 = Card::getById($cardId1);
        $card2 = Card::getById($cardId2);

        $this->assertEquals('A card with, commas in it', $card1['copy']);
        $this->assertEquals('Another card, with commas, and more commas', $card2['copy']);
    }

    /**
     * Test importing CSV with whitespace in tags
     */
    public function testImportCsvWithWhitespaceInTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "Test card,  Profanity  , Sexually Explicit ,Violence  ,,,,,,\n";

        $lines = str_getcsv($csvContent, "\n");

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue; // Skip header
            }

            $data = str_getcsv($line);
            if (empty($data[0])) {
                continue;
            }

            $cardText = trim($data[0]);

            // Get tags from columns 1-10, trim and filter out empty values
            $tagColumns = array_slice($data, 1, 10);
            $tags = [];
            foreach ($tagColumns as $tag) {
                $tag = trim($tag);
                if ( ! empty($tag)) {
                    $tags[] = $tag;
                }
            }

            // Verify tags were trimmed correctly
            $this->assertCount(3, $tags);
            $this->assertEquals('Profanity', $tags[0]);
            $this->assertEquals('Sexually Explicit', $tags[1]);
            $this->assertEquals('Violence', $tags[2]);

            $cardId = CardImportService::importCard(CardType::RESPONSE, $cardText);
            $this->assertNotNull($cardId);

            // Add tags
            foreach ($tags as $tagName) {
                $existingTags = Tag::getAll();
                $tagId = null;
                foreach ($existingTags as $existingTag) {
                    if (strcasecmp($existingTag['name'], $tagName) === 0) {
                        $tagId = $existingTag['tag_id'];
                        break;
                    }
                }

                if ( ! $tagId) {
                    $tagId = Tag::create($tagName, null, true);
                }

                Tag::addToCard($cardId, $tagId);
            }

            // Verify all tags were added
            $cardTags = Tag::getCardTags($cardId);
            $this->assertCount(3, $cardTags);
        }
    }

    /**
     * Test importing CSV with empty lines
     */
    public function testImportCsvWithEmptyLines(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "First card,Profanity,,,,,,,,,\n";
        $csvContent .= ",,,,,,,,,,\n"; // Empty line
        $csvContent .= "Second card,Violence,,,,,,,,,\n";
        $csvContent .= "\n"; // Blank line

        $lines = str_getcsv($csvContent, "\n");
        $imported = 0;

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue; // Skip header
            }

            $data = str_getcsv($line);
            if (empty($data[0])) {
                continue; // Skip empty lines
            }

            $cardText = trim($data[0]);
            $cardId = CardImportService::importCard(CardType::RESPONSE, $cardText);
            $this->assertNotNull($cardId);
            $imported++;
        }

        // Should only import 2 cards, skipping empty lines
        $this->assertEquals(2, $imported);
    }

    /**
     * Test that duplicate tags are not created during CSV import
     */
    public function testCsvImportDoesNotCreateDuplicateTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "First card,Profanity,,,,,,,,,\n";
        $csvContent .= "Second card,Profanity,,,,,,,,,\n";
        $csvContent .= "Third card,PROFANITY,,,,,,,,,\n"; // Different case

        $lines = str_getcsv($csvContent, "\n");

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue; // Skip header
            }

            $data = str_getcsv($line);
            if (empty($data[0])) {
                continue;
            }

            $cardText = trim($data[0]);

            // Get tags from columns 1-10, trim and filter out empty values
            $tagColumns = array_slice($data, 1, 10);
            $tags = [];
            foreach ($tagColumns as $tag) {
                $tag = trim($tag);
                if ( ! empty($tag)) {
                    $tags[] = $tag;
                }
            }

            $cardId = CardImportService::importCard(CardType::RESPONSE, $cardText);

            // Add tags
            foreach ($tags as $tagName) {
                // Find or create tag (case-insensitive)
                $existingTags = Tag::getAll();
                $tagId = null;
                foreach ($existingTags as $existingTag) {
                    if (strcasecmp($existingTag['name'], $tagName) === 0) {
                        $tagId = $existingTag['tag_id'];
                        break;
                    }
                }

                if ( ! $tagId) {
                    $tagId = Tag::create($tagName, null, true);
                }

                Tag::addToCard($cardId, $tagId);
            }
        }

        // Verify only one "Profanity" tag exists (case-insensitive)
        $allTags = Tag::getAll();
        $profanityTags = array_filter($allTags, function($tag) {
            return strcasecmp($tag['name'], 'Profanity') === 0;
        });
        $this->assertCount(1, $profanityTags);
    }
}
