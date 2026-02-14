<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Database\Database;
use CAH\Enums\CardType;
use CAH\Models\Card;
use CAH\Models\Pack;
use CAH\Tests\TestCase;

class PackModelIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_packs WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE 'CoveragePack%')");
        Database::execute("DELETE FROM cards_to_packs WHERE pack_id IN (SELECT pack_id FROM packs WHERE name LIKE 'CoveragePack%')");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'CoveragePack%'");
        Database::execute("DELETE FROM packs WHERE name LIKE 'CoveragePack%'");
        parent::tearDown();
    }

    public function testCrudAndLookupMethods(): void
    {
        $packId = Pack::create('CoveragePack Alpha', '1.0', '{"source":"test"}', '2025-01-01', true);
        $this->assertGreaterThan(0, $packId);

        $found = Pack::find($packId);
        $this->assertNotNull($found);
        $this->assertSame('CoveragePack Alpha', $found['name']);

        $byNameVersion = Pack::findByNameAndVersion('CoveragePack Alpha', '1.0');
        $this->assertNotNull($byNameVersion);
        $this->assertSame($packId, (int) $byNameVersion['pack_id']);

        $updated = Pack::update($packId, ['name' => 'CoveragePack Alpha Updated', 'active' => 0]);
        $this->assertSame(1, $updated);
        $this->assertSame(0, (int) Pack::find($packId)['active']);

        $this->assertSame(1, Pack::setActive($packId, true));
        $this->assertSame(1, (int) Pack::find($packId)['active']);

        $all = Pack::getAll();
        $allIds = array_column($all, 'pack_id');
        $this->assertContains($packId, $allIds);

        $active = Pack::getAllActive();
        $activeIds = array_column($active, 'pack_id');
        $this->assertContains($packId, $activeIds);
    }

    public function testBulkActivationAndCountQueries(): void
    {
        $packA = Pack::create('CoveragePack Bulk A', '1.0', null, null, false);
        $packB = Pack::create('CoveragePack Bulk B', '1.0', null, null, false);
        $packC = Pack::create('CoveragePack Bulk C', '1.0', null, null, true);

        $affected = Pack::setActiveBulk([$packA, $packB], true);
        $this->assertSame(2, $affected);
        $this->assertSame(1, (int) Pack::find($packA)['active']);
        $this->assertSame(1, (int) Pack::find($packB)['active']);

        $activeOnly = Pack::getAllWithCounts(true);
        $inactiveOnly = Pack::getAllWithCounts(false);
        $activeIds = array_column($activeOnly, 'pack_id');
        $inactiveIds = array_column($inactiveOnly, 'pack_id');
        $this->assertContains($packA, $activeIds);
        $this->assertNotContains($packA, $inactiveIds);
        $this->assertNotContains($packB, $inactiveIds);

        $this->assertSame(1, Pack::setActive($packC, false));
        $this->assertContains($packC, array_column(Pack::getAllWithCounts(false), 'pack_id'));
    }

    public function testCardAssociationMethods(): void
    {
        $pack1 = Pack::create('CoveragePack Assoc 1', '1.0', null, null, true);
        $pack2 = Pack::create('CoveragePack Assoc 2', '1.0', null, null, true);
        $card1 = Card::create(CardType::RESPONSE, 'CoveragePack card 1', null, true);
        $card2 = Card::create(CardType::PROMPT, 'CoveragePack card 2', 1, true);

        $this->assertTrue(Pack::addToCard($card1, $pack1));
        $this->assertFalse(Pack::addToCard($card1, $pack1)); // duplicate path

        $this->assertSame(1, Pack::addMultiplePacksToCard($card1, [$pack2]));
        $this->assertSame(0, Pack::addMultiplePacksToCard($card1, []));

        $this->assertSame(2, Pack::addPackToMultipleCards([$card1, $card2], $pack1));
        $this->assertSame(0, Pack::addPackToMultipleCards([], $pack1));

        $card1Packs = Pack::getCardPacks($card1, false);
        $card1PackIds = array_column($card1Packs, 'pack_id');
        $this->assertContains($pack1, $card1PackIds);
        $this->assertContains($pack2, $card1PackIds);

        $byCard = Pack::getCardPacksForMultipleCards([$card1, $card2], false);
        $this->assertArrayHasKey($card1, $byCard);
        $this->assertArrayHasKey($card2, $byCard);
        $this->assertNotEmpty($byCard[$card1]);
        $this->assertNotEmpty($byCard[$card2]);
        $this->assertSame([], Pack::getCardPacksForMultipleCards([]));

        $this->assertGreaterThanOrEqual(1, Pack::getCardCount($pack1));
        $this->assertGreaterThanOrEqual(1, Pack::getCardCount($pack1, CardType::RESPONSE));
        $this->assertGreaterThanOrEqual(1, Pack::getCardCount($pack1, CardType::PROMPT));

        $removed = Pack::removeFromCard($card1, $pack2);
        $this->assertSame(1, $removed);
        $this->assertNotContains($pack2, array_column(Pack::getCardPacks($card1, false), 'pack_id'));
    }

    public function testFindManyAndDelete(): void
    {
        $pack1 = Pack::create('CoveragePack FindMany 1', '1.0', null, null, true);
        $pack2 = Pack::create('CoveragePack FindMany 2', '1.0', null, null, true);

        $found = Pack::findMany([$pack1, $pack2]);
        $ids = array_column($found, 'pack_id');
        $this->assertContains($pack1, $ids);
        $this->assertContains($pack2, $ids);
        $this->assertSame([], Pack::findMany([]));

        $this->assertSame(1, Pack::delete($pack2));
        $this->assertNull(Pack::find($pack2));
    }
}

