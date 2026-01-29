<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Models\Card;
use CAH\Models\Pack;
use CAH\Models\Tag;
use CAH\Enums\CardType;
use CAH\Database\Database;

/**
 * Pack Filtering Integration Tests
 *
 * Tests that cards from inactive packs are properly excluded from game selection
 * while cards in active packs (or with no packs) are included.
 */
class PackFilteringTest extends TestCase
{
    private int $pack1Id;
    private int $pack2Id;
    private int $pack3Id;
    private int $tagId;

    /**
     * Set up test data: packs, cards, and relationships
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test packs
        $this->pack1Id = (int) Database::execute(
            "INSERT INTO packs (name, version, active) VALUES (?, ?, ?)",
            ['Test Pack 1', '1.0', 1]
        );
        Database::execute("UPDATE packs SET pack_id = LAST_INSERT_ID() WHERE pack_id = 0");
        $result = Database::fetchOne("SELECT LAST_INSERT_ID() as id");
        $this->pack1Id = (int) $result['id'];

        $this->pack2Id = (int) Database::execute(
            "INSERT INTO packs (name, version, active) VALUES (?, ?, ?)",
            ['Test Pack 2', '1.0', 1]
        );
        $result = Database::fetchOne("SELECT LAST_INSERT_ID() as id");
        $this->pack2Id = (int) $result['id'];

        $this->pack3Id = (int) Database::execute(
            "INSERT INTO packs (name, version, active) VALUES (?, ?, ?)",
            ['Test Pack 3 Inactive', '1.0', 0]
        );
        $result = Database::fetchOne("SELECT LAST_INSERT_ID() as id");
        $this->pack3Id = (int) $result['id'];

        // Create a test tag
        Database::execute(
            "INSERT INTO tags (name, active) VALUES (?, ?)",
            ['Test Tag', 1]
        );
        $result = Database::fetchOne("SELECT LAST_INSERT_ID() as id");
        $this->tagId = (int) $result['id'];
    }

    /**
     * Clean up test data
     */
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_packs WHERE pack_id IN (?, ?, ?)",
            [$this->pack1Id, $this->pack2Id, $this->pack3Id]);
        Database::execute("DELETE FROM packs WHERE pack_id IN (?, ?, ?)",
            [$this->pack1Id, $this->pack2Id, $this->pack3Id]);
        Database::execute("DELETE FROM cards_to_tags WHERE tag_id = ?", [$this->tagId]);
        Database::execute("DELETE FROM tags WHERE tag_id = ?", [$this->tagId]);
        Database::execute("DELETE FROM cards WHERE copy LIKE 'PackTest%'");

        parent::tearDown();
    }

    /**
     * Test that cards only in active packs are included
     */
    public function testCardsInActivePacksAreIncluded(): void
    {
        // Create a card in active pack 1
        $cardId = Card::create(CardType::RESPONSE, 'PackTest Card in Active Pack', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$cardId, $this->pack1Id]
        );

        // Get active cards
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);

        $this->assertContains($cardId, $cards, 'Card in active pack should be included');
    }

    /**
     * Test that cards only in inactive packs are excluded
     */
    public function testCardsOnlyInInactivePacksAreExcluded(): void
    {
        // Create a card only in inactive pack 3
        $cardId = Card::create(CardType::RESPONSE, 'PackTest Card Only in Inactive Pack', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$cardId, $this->pack3Id]
        );

        // Get active cards
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);

        $this->assertNotContains($cardId, $cards, 'Card only in inactive pack should be excluded');
    }



    /**
     * Test that cards in both active and inactive packs are included
     */
    public function testCardsInBothActiveAndInactivePacksAreIncluded(): void
    {
        // Create a card in both active pack 1 and inactive pack 3
        $cardId = Card::create(CardType::RESPONSE, 'PackTest Card in Both Active and Inactive', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?), (?, ?)",
            [$cardId, $this->pack1Id, $cardId, $this->pack3Id]
        );

        // Get active cards
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);

        $this->assertContains($cardId, $cards,
            'Card in both active and inactive packs should be included');
    }

    /**
     * Test that cards with no packs are included (backward compatibility)
     */
    public function testCardsWithNoPacksAreIncluded(): void
    {
        // Create a card with no pack assignments
        $cardId = Card::create(CardType::RESPONSE, 'PackTest Card with No Packs', null, true);

        // Get active cards
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);

        $this->assertContains($cardId, $cards,
            'Card with no pack assignments should be included for backward compatibility');
    }

    /**
     * Test that inactive cards are still excluded even if in active packs
     */
    public function testInactiveCardsAreExcludedEvenInActivePacks(): void
    {
        // Create an inactive card in active pack
        $cardId = Card::create(CardType::RESPONSE, 'PackTest Inactive Card in Active Pack', null, false);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$cardId, $this->pack1Id]
        );

        // Get active cards
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);

        $this->assertNotContains($cardId, $cards,
            'Inactive card should be excluded even if in active pack');
    }

    /**
     * Test pack filtering with tag filtering
     */
    public function testPackFilteringWorksWithTagFiltering(): void
    {
        // Create card in active pack with tag
        $card1 = Card::create(CardType::RESPONSE, 'PackTest Card Active Pack With Tag', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card1, $this->pack1Id]
        );
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$card1, $this->tagId]
        );

        // Create card in inactive pack with tag
        $card2 = Card::create(CardType::RESPONSE, 'PackTest Card Inactive Pack With Tag', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card2, $this->pack3Id]
        );
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$card2, $this->tagId]
        );

        // Get active cards with tag filter
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, [$this->tagId]);

        $this->assertContains($card1, $cards,
            'Card in active pack with matching tag should be included');
        $this->assertNotContains($card2, $cards,
            'Card in inactive pack should be excluded even with matching tag');
    }

    /**
     * Test Card::getActiveByType() also filters by pack status
     */
    public function testGetActiveByTypeFiltersInactivePacks(): void
    {
        // Create card in active pack
        $card1 = Card::create(CardType::PROMPT, 'PackTest Prompt in Active Pack', 1, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card1, $this->pack1Id]
        );

        // Create card in inactive pack
        $card2 = Card::create(CardType::PROMPT, 'PackTest Prompt in Inactive Pack', 1, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card2, $this->pack3Id]
        );

        // Get active cards by type
        $cards = Card::getActiveByType(CardType::PROMPT);
        $cardIds = array_column($cards, 'card_id');

        $this->assertContains($card1, $cardIds,
            'Card in active pack should be included');
        $this->assertNotContains($card2, $cardIds,
            'Card in inactive pack should be excluded');
    }

    /**
     * Test Tag::getAllActiveWithCounts() excludes cards from inactive packs
     */
    public function testTagCountsExcludeInactivePacks(): void
    {
        // Create card in active pack with tag
        $card1 = Card::create(CardType::RESPONSE, 'PackTest Tag Count Active', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card1, $this->pack1Id]
        );
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$card1, $this->tagId]
        );

        // Create card in inactive pack with tag
        $card2 = Card::create(CardType::RESPONSE, 'PackTest Tag Count Inactive', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card2, $this->pack3Id]
        );
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$card2, $this->tagId]
        );

        // Get tag counts
        $tags = Tag::getAllActiveWithCounts();
        $testTag = null;
        foreach ($tags as $tag) {
            if ($tag['tag_id'] == $this->tagId) {
                $testTag = $tag;
                break;
            }
        }

        $this->assertNotNull($testTag, 'Test tag should be found');
        $this->assertEquals(1, $testTag['response_card_count'],
            'Should only count card in active pack');
    }

    /**
     * Test Tag::getCardCount() excludes cards from inactive packs
     */
    public function testTagGetCardCountExcludesInactivePacks(): void
    {
        // Create 2 cards in active pack with tag
        $card1 = Card::create(CardType::RESPONSE, 'PackTest Count Active 1', null, true);
        $card2 = Card::create(CardType::RESPONSE, 'PackTest Count Active 2', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?), (?, ?)",
            [$card1, $this->pack1Id, $card2, $this->pack1Id]
        );
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?), (?, ?)",
            [$card1, $this->tagId, $card2, $this->tagId]
        );

        // Create 1 card in inactive pack with tag
        $card3 = Card::create(CardType::RESPONSE, 'PackTest Count Inactive', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card3, $this->pack3Id]
        );
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$card3, $this->tagId]
        );

        // Get card count for tag
        $count = Tag::getCardCount($this->tagId, CardType::RESPONSE);

        $this->assertEquals(2, $count,
            'Should only count cards in active packs');
    }

    /**
     * Test Pack::getAllWithCounts() only counts active cards
     */
    public function testPackCountsOnlyIncludeActiveCards(): void
    {
        // Create active card in pack 1
        $card1 = Card::create(CardType::RESPONSE, 'PackTest Active Card', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card1, $this->pack1Id]
        );

        // Create inactive card in pack 1
        $card2 = Card::create(CardType::RESPONSE, 'PackTest Inactive Card', null, false);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$card2, $this->pack1Id]
        );

        // Get pack counts
        $packs = Pack::getAllWithCounts();
        $testPack = null;
        foreach ($packs as $pack) {
            if ($pack['pack_id'] == $this->pack1Id) {
                $testPack = $pack;
                break;
            }
        }

        $this->assertNotNull($testPack, 'Test pack should be found');
        $this->assertEquals(1, $testPack['response_card_count'],
            'Should only count active cards');
    }

    /**
     * Test that deactivating a pack excludes its exclusive cards
     */
    public function testDeactivatingPackExcludesCards(): void
    {
        // Create a card only in pack 2 (currently active)
        $cardId = Card::create(CardType::RESPONSE, 'PackTest Card to be Excluded', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$cardId, $this->pack2Id]
        );

        // Verify card is included
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);
        $this->assertContains($cardId, $cards, 'Card should be included when pack is active');

        // Deactivate pack 2
        Database::execute("UPDATE packs SET active = 0 WHERE pack_id = ?", [$this->pack2Id]);

        // Verify card is now excluded
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);
        $this->assertNotContains($cardId, $cards, 'Card should be excluded when pack is deactivated');
    }

    /**
     * Test that activating a pack includes its cards
     */
    public function testActivatingPackIncludesCards(): void
    {
        // Create a card only in pack 3 (currently inactive)
        $cardId = Card::create(CardType::RESPONSE, 'PackTest Card to be Included', null, true);
        Database::execute(
            "INSERT INTO cards_to_packs (card_id, pack_id) VALUES (?, ?)",
            [$cardId, $this->pack3Id]
        );

        // Verify card is excluded
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);
        $this->assertNotContains($cardId, $cards, 'Card should be excluded when pack is inactive');

        // Activate pack 3
        Database::execute("UPDATE packs SET active = 1 WHERE pack_id = ?", [$this->pack3Id]);

        // Verify card is now included
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);
        $this->assertContains($cardId, $cards, 'Card should be included when pack is activated');
    }
}
