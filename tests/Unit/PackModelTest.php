<?php

declare(strict_types=1);

namespace CAH\Tests\Unit;

use CAH\Tests\TestCase;
use CAH\Models\Pack;
use CAH\Models\Card;
use CAH\Enums\CardType;
use CAH\Database\Database;

/**
 * Pack Model Unit Tests
 *
 * Tests CRUD operations and card relationship methods for the Pack model
 */
class PackModelTest extends TestCase
{
    private int $testPackId1;
    private int $testPackId2;
    private int $testCardId1;
    private int $testCardId2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test packs
        $this->testPackId1 = Pack::create('Unit Test Pack 1', '1.0', '{"test": true}', null, true);
        $this->testPackId2 = Pack::create('Unit Test Pack 2', '2.0', null, '2024-01-01 00:00:00', false);

        // Create test cards
        $this->testCardId1 = Card::create(CardType::RESPONSE, 'Unit Test Card 1', null, true);
        $this->testCardId2 = Card::create(CardType::RESPONSE, 'Unit Test Card 2', null, true);
    }

    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_packs WHERE pack_id IN (?, ?)",
            [$this->testPackId1, $this->testPackId2]);
        Database::execute("DELETE FROM packs WHERE name LIKE 'Unit Test Pack%'");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'Unit Test Card%'");

        parent::tearDown();
    }

    /**
     * Test Pack::create() creates a pack with all fields
     */
    public function testCreatePackWithAllFields(): void
    {
        $packId = Pack::create(
            'Full Pack Test',
            '3.0',
            '{"description": "test pack"}',
            '2025-01-01 12:00:00',
            false
        );

        $this->assertIsInt($packId);
        $this->assertGreaterThan(0, $packId);

        $pack = Pack::find($packId);
        $this->assertNotNull($pack);
        $this->assertEquals('Full Pack Test', $pack['name']);
        $this->assertEquals('3.0', $pack['version']);
        $this->assertEquals('{"description": "test pack"}', $pack['data']);
        $this->assertEquals('2025-01-01 12:00:00', $pack['release_date']);
        $this->assertEquals(0, $pack['active']);

        // Cleanup
        Pack::delete($packId);
    }

    /**
     * Test Pack::create() with minimal fields (defaults)
     */
    public function testCreatePackWithMinimalFields(): void
    {
        $packId = Pack::create('Minimal Pack Test');

        $this->assertIsInt($packId);
        $this->assertGreaterThan(0, $packId);

        $pack = Pack::find($packId);
        $this->assertNotNull($pack);
        $this->assertEquals('Minimal Pack Test', $pack['name']);
        $this->assertNull($pack['version']);
        $this->assertNull($pack['data']);
        $this->assertNull($pack['release_date']);
        $this->assertEquals(1, $pack['active']); // Default is active

        // Cleanup
        Pack::delete($packId);
    }

    /**
     * Test Pack::find() retrieves a pack by ID
     */
    public function testFindReturnsPackById(): void
    {
        $pack = Pack::find($this->testPackId1);

        $this->assertNotNull($pack);
        $this->assertEquals($this->testPackId1, $pack['pack_id']);
        $this->assertEquals('Unit Test Pack 1', $pack['name']);
        $this->assertEquals('1.0', $pack['version']);
        $this->assertEquals('{"test": true}', $pack['data']);
        $this->assertEquals(1, $pack['active']);
    }

    /**
     * Test Pack::find() returns null for non-existent pack
     */
    public function testFindReturnsNullForNonExistentPack(): void
    {
        $pack = Pack::find(999999);
        $this->assertNull($pack);
    }

    /**
     * Test Pack::findMany() retrieves multiple packs
     */
    public function testFindManyRetrievesMultiplePacks(): void
    {
        $packs = Pack::findMany([$this->testPackId1, $this->testPackId2]);

        $this->assertCount(2, $packs);
        $packIds = array_column($packs, 'pack_id');
        $this->assertContains($this->testPackId1, $packIds);
        $this->assertContains($this->testPackId2, $packIds);
    }

    /**
     * Test Pack::findMany() with empty array
     */
    public function testFindManyWithEmptyArray(): void
    {
        $packs = Pack::findMany([]);
        $this->assertIsArray($packs);
        $this->assertEmpty($packs);
    }

    /**
     * Test Pack::getAll() returns all packs
     */
    public function testGetAllReturnsPacks(): void
    {
        $packs = Pack::getAll();

        $this->assertIsArray($packs);
        $this->assertNotEmpty($packs);

        $packIds = array_column($packs, 'pack_id');
        $this->assertContains($this->testPackId1, $packIds);
        $this->assertContains($this->testPackId2, $packIds);
    }

    /**
     * Test Pack::getAllActive() returns only active packs
     */
    public function testGetAllActiveReturnsOnlyActivePacks(): void
    {
        $packs = Pack::getAllActive();

        $this->assertIsArray($packs);
        $packIds = array_column($packs, 'pack_id');

        $this->assertContains($this->testPackId1, $packIds, 'Active pack should be included');
        $this->assertNotContains($this->testPackId2, $packIds, 'Inactive pack should not be included');

        // Verify all returned packs are active
        foreach ($packs as $pack) {
            $this->assertEquals(1, $pack['active'], 'All packs should be active');
        }
    }

    /**
     * Test Pack::getAllWithCounts() includes card counts
     */
    public function testGetAllWithCountsIncludesCardCounts(): void
    {
        // Add cards to pack
        Pack::addToCard($this->testCardId1, $this->testPackId1);
        Pack::addToCard($this->testCardId2, $this->testPackId1);

        $packs = Pack::getAllWithCounts();

        $testPack = null;
        foreach ($packs as $pack) {
            if ($pack['pack_id'] == $this->testPackId1) {
                $testPack = $pack;
                break;
            }
        }

        $this->assertNotNull($testPack);
        $this->assertArrayHasKey('response_card_count', $testPack);
        $this->assertArrayHasKey('prompt_card_count', $testPack);
        $this->assertArrayHasKey('total_card_count', $testPack);
        $this->assertEquals(2, $testPack['response_card_count']);
        $this->assertEquals(2, $testPack['total_card_count']);
    }

    /**
     * Test Pack::getAllWithCounts() with activeOnly filter
     */
    public function testGetAllWithCountsActiveOnlyFilter(): void
    {
        $activePacks = Pack::getAllWithCounts(true);
        $inactivePacks = Pack::getAllWithCounts(false);

        $activeIds = array_column($activePacks, 'pack_id');
        $inactiveIds = array_column($inactivePacks, 'pack_id');

        $this->assertContains($this->testPackId1, $activeIds);
        $this->assertNotContains($this->testPackId2, $activeIds);

        $this->assertContains($this->testPackId2, $inactiveIds);
        $this->assertNotContains($this->testPackId1, $inactiveIds);
    }

    /**
     * Test Pack::update() updates pack fields
     */
    public function testUpdateModifiesPackFields(): void
    {
        $affected = Pack::update($this->testPackId1, [
            'name' => 'Updated Pack Name',
            'version' => '2.5',
            'active' => 0
        ]);

        $this->assertEquals(1, $affected);

        $pack = Pack::find($this->testPackId1);
        $this->assertEquals('Updated Pack Name', $pack['name']);
        $this->assertEquals('2.5', $pack['version']);
        $this->assertEquals(0, $pack['active']);
    }

    /**
     * Test Pack::update() ignores invalid fields
     */
    public function testUpdateIgnoresInvalidFields(): void
    {
        $affected = Pack::update($this->testPackId1, [
            'name' => 'Valid Update',
            'invalid_field' => 'Should be ignored',
            'pack_id' => 99999 // Should be ignored
        ]);

        $this->assertEquals(1, $affected);

        $pack = Pack::find($this->testPackId1);
        $this->assertEquals('Valid Update', $pack['name']);
        $this->assertEquals($this->testPackId1, $pack['pack_id']); // ID not changed
    }

    /**
     * Test Pack::update() with empty data array
     */
    public function testUpdateWithEmptyData(): void
    {
        $affected = Pack::update($this->testPackId1, []);
        $this->assertEquals(0, $affected);
    }

    /**
     * Test Pack::setActive() toggles pack status
     */
    public function testSetActiveTogglesPackStatus(): void
    {
        // Initially active
        $pack = Pack::find($this->testPackId1);
        $this->assertEquals(1, $pack['active']);

        // Deactivate
        $affected = Pack::setActive($this->testPackId1, false);
        $this->assertEquals(1, $affected);

        $pack = Pack::find($this->testPackId1);
        $this->assertEquals(0, $pack['active']);

        // Reactivate
        $affected = Pack::setActive($this->testPackId1, true);
        $this->assertEquals(1, $affected);

        $pack = Pack::find($this->testPackId1);
        $this->assertEquals(1, $pack['active']);
    }

    /**
     * Test Pack::delete() removes a pack
     */
    public function testDeleteRemovesPack(): void
    {
        $tempPackId = Pack::create('Pack to Delete', '1.0', null, null, true);

        $pack = Pack::find($tempPackId);
        $this->assertNotNull($pack);

        $affected = Pack::delete($tempPackId);
        $this->assertEquals(1, $affected);

        $pack = Pack::find($tempPackId);
        $this->assertNull($pack);
    }

    /**
     * Test Pack::addToCard() adds pack to card
     */
    public function testAddToCardAssociation(): void
    {
        $result = Pack::addToCard($this->testCardId1, $this->testPackId1);
        $this->assertTrue($result);

        $packs = Pack::getCardPacks($this->testCardId1);
        $packIds = array_column($packs, 'pack_id');
        $this->assertContains($this->testPackId1, $packIds);
    }

    /**
     * Test Pack::addToCard() returns false for duplicate
     */
    public function testAddToCardReturnsFalseForDuplicate(): void
    {
        Pack::addToCard($this->testCardId1, $this->testPackId1);
        $result = Pack::addToCard($this->testCardId1, $this->testPackId1);
        $this->assertFalse($result);
    }

    /**
     * Test Pack::addMultiplePacksToCard() adds multiple packs
     */
    public function testAddMultiplePacksToCard(): void
    {
        $added = Pack::addMultiplePacksToCard($this->testCardId1, [
            $this->testPackId1,
            $this->testPackId2
        ]);

        $this->assertEquals(2, $added);

        $packs = Pack::getCardPacks($this->testCardId1);
        $this->assertCount(2, $packs);
    }

    /**
     * Test Pack::addMultiplePacksToCard() with empty array
     */
    public function testAddMultiplePacksToCardWithEmptyArray(): void
    {
        $added = Pack::addMultiplePacksToCard($this->testCardId1, []);
        $this->assertEquals(0, $added);
    }

    /**
     * Test Pack::addPackToMultipleCards() bulk operation
     */
    public function testAddPackToMultipleCards(): void
    {
        $added = Pack::addPackToMultipleCards(
            [$this->testCardId1, $this->testCardId2],
            $this->testPackId1
        );

        $this->assertEquals(2, $added);

        $packs1 = Pack::getCardPacks($this->testCardId1);
        $packs2 = Pack::getCardPacks($this->testCardId2);

        $this->assertCount(1, $packs1);
        $this->assertCount(1, $packs2);
        $this->assertEquals($this->testPackId1, $packs1[0]['pack_id']);
        $this->assertEquals($this->testPackId1, $packs2[0]['pack_id']);
    }

    /**
     * Test Pack::addPackToMultipleCards() with empty array
     */
    public function testAddPackToMultipleCardsWithEmptyArray(): void
    {
        $added = Pack::addPackToMultipleCards([], $this->testPackId1);
        $this->assertEquals(0, $added);
    }

    /**
     * Test Pack::removeFromCard() removes association
     */
    public function testRemoveFromCard(): void
    {
        Pack::addToCard($this->testCardId1, $this->testPackId1);

        $affected = Pack::removeFromCard($this->testCardId1, $this->testPackId1);
        $this->assertEquals(1, $affected);

        $packs = Pack::getCardPacks($this->testCardId1);
        $this->assertEmpty($packs);
    }

    /**
     * Test Pack::getCardPacks() returns packs for a card
     */
    public function testGetCardPacksReturnsPacks(): void
    {
        Pack::addToCard($this->testCardId1, $this->testPackId1);
        Pack::addToCard($this->testCardId1, $this->testPackId2);

        $packs = Pack::getCardPacks($this->testCardId1);

        $this->assertCount(2, $packs);
        $packIds = array_column($packs, 'pack_id');
        $this->assertContains($this->testPackId1, $packIds);
        $this->assertContains($this->testPackId2, $packIds);
    }

    /**
     * Test Pack::getCardPacksForMultipleCards() batch fetch
     */
    public function testGetCardPacksForMultipleCards(): void
    {
        Pack::addToCard($this->testCardId1, $this->testPackId1);
        Pack::addToCard($this->testCardId2, $this->testPackId2);

        $packsByCard = Pack::getCardPacksForMultipleCards([
            $this->testCardId1,
            $this->testCardId2
        ]);

        $this->assertArrayHasKey($this->testCardId1, $packsByCard);
        $this->assertArrayHasKey($this->testCardId2, $packsByCard);
        $this->assertCount(1, $packsByCard[$this->testCardId1]);
        $this->assertCount(1, $packsByCard[$this->testCardId2]);
    }

    /**
     * Test Pack::getCardPacksForMultipleCards() with empty array
     */
    public function testGetCardPacksForMultipleCardsWithEmptyArray(): void
    {
        $packsByCard = Pack::getCardPacksForMultipleCards([]);
        $this->assertIsArray($packsByCard);
        $this->assertEmpty($packsByCard);
    }

    /**
     * Test Pack::getCardPacksForMultipleCards() includes empty arrays for cards with no packs
     */
    public function testGetCardPacksForMultipleCardsIncludesEmptyArrays(): void
    {
        $packsByCard = Pack::getCardPacksForMultipleCards([$this->testCardId1]);

        $this->assertArrayHasKey($this->testCardId1, $packsByCard);
        $this->assertIsArray($packsByCard[$this->testCardId1]);
        $this->assertEmpty($packsByCard[$this->testCardId1]);
    }

    /**
     * Test Pack::getCardCount() counts cards in a pack
     */
    public function testGetCardCountReturnsTotalCards(): void
    {
        Pack::addToCard($this->testCardId1, $this->testPackId1);
        Pack::addToCard($this->testCardId2, $this->testPackId1);

        $count = Pack::getCardCount($this->testPackId1);
        $this->assertEquals(2, $count);
    }

    /**
     * Test Pack::getCardCount() with card type filter
     */
    public function testGetCardCountWithTypeFilter(): void
    {
        // Create cards of different types
        $responseCard = Card::create(CardType::RESPONSE, 'Response Card', null, true);
        $promptCard = Card::create(CardType::PROMPT, 'Prompt Card', 1, true);

        Pack::addToCard($responseCard, $this->testPackId1);
        Pack::addToCard($promptCard, $this->testPackId1);

        $responseCount = Pack::getCardCount($this->testPackId1, CardType::RESPONSE);
        $promptCount = Pack::getCardCount($this->testPackId1, CardType::PROMPT);
        $totalCount = Pack::getCardCount($this->testPackId1);

        $this->assertEquals(1, $responseCount);
        $this->assertEquals(1, $promptCount);
        $this->assertEquals(2, $totalCount);

        // Cleanup
        Database::execute("DELETE FROM cards WHERE card_id IN (?, ?)", [$responseCard, $promptCard]);
    }

    /**
     * Test Pack::getCardCount() returns 0 for pack with no cards
     */
    public function testGetCardCountReturnsZeroForEmptyPack(): void
    {
        $count = Pack::getCardCount($this->testPackId2);
        $this->assertEquals(0, $count);
    }
}
