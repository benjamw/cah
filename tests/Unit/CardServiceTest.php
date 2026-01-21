<?php

declare(strict_types=1);

namespace CAH\Tests\Unit;

use CAH\Tests\TestCase;
use CAH\Services\CardService;
use CAH\Exceptions\InsufficientCardsException;

/**
 * Card Service Unit Tests
 */
class CardServiceTest extends TestCase
{
    public function testDrawWhiteCards(): void
    {
        $pile = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $result = CardService::drawWhiteCards($pile, 3);

        $this->assertCount(3, $result['cards']);
        $this->assertCount(7, $result['remaining_pile']);
        $this->assertEquals([1, 2, 3], $result['cards']);
        $this->assertEquals([4, 5, 6, 7, 8, 9, 10], $result['remaining_pile']);
    }

    public function testDrawWhiteCardsThrowsExceptionWhenInsufficientCards(): void
    {
        $pile = [1, 2, 3];

        $this->expectException(InsufficientCardsException::class);
        $this->expectExceptionMessage('Insufficient white cards');

        CardService::drawWhiteCards($pile, 5);
    }

    public function testDrawBlackCard(): void
    {
        $pile = [101, 102, 103];
        $result = CardService::drawBlackCard($pile);

        $this->assertEquals(101, $result['card']);
        $this->assertEquals([102, 103], $result['remaining_pile']);
    }

    public function testDrawBlackCardThrowsExceptionWhenEmpty(): void
    {
        $pile = [];

        $this->expectException(InsufficientCardsException::class);
        $this->expectExceptionMessage('Insufficient black cards');

        CardService::drawBlackCard($pile);
    }

    public function testCalculateBonusCards(): void
    {
        $this->assertEquals(0, CardService::calculateBonusCards(1));
        $this->assertEquals(0, CardService::calculateBonusCards(2));
        $this->assertEquals(2, CardService::calculateBonusCards(3));
        $this->assertEquals(3, CardService::calculateBonusCards(4));
        $this->assertEquals(4, CardService::calculateBonusCards(5));
    }

    public function testDealBonusCards(): void
    {
        $players = [
            ['id' => '1', 'hand' => [1, 2, 3]],
            ['id' => '2', 'hand' => [4, 5, 6]],
        ];
        $whitePile = [10, 11, 12, 13, 14, 15];

        $remainingPile = CardService::dealBonusCards($players, $whitePile, 2);

        // Each player should have 2 bonus cards added
        $this->assertCount(5, $players[0]['hand']); // 3 original + 2 bonus
        $this->assertCount(5, $players[1]['hand']); // 3 original + 2 bonus

        // 4 cards should be removed from pile (2 per player)
        $this->assertCount(2, $remainingPile);
    }

    public function testDealBonusCardsWithZeroBonus(): void
    {
        $players = [
            ['id' => '1', 'hand' => [1, 2, 3]],
        ];
        $whitePile = [10, 11, 12];

        $remainingPile = CardService::dealBonusCards($players, $whitePile, 0);

        // No cards should be dealt
        $this->assertCount(3, $players[0]['hand']);
        $this->assertCount(3, $remainingPile);
    }

    public function testDiscardCards(): void
    {
        $discardPile = [1, 2, 3];
        $cardsToDiscard = [4, 5, 6];

        $result = CardService::discardCards($discardPile, $cardsToDiscard);

        $this->assertEquals([1, 2, 3, 4, 5, 6], $result);
    }

    public function testReturnCardsToPile(): void
    {
        $pile = [1, 2, 3];
        $cardsToReturn = [4, 5];

        $result = CardService::returnCardsToPile($pile, $cardsToReturn);

        $this->assertEquals([1, 2, 3, 4, 5], $result);
    }

    public function testIsDrawPileLow(): void
    {
        $lowPile = array_fill(0, 50, 1);
        $goodPile = array_fill(0, 150, 1);

        $this->assertTrue(CardService::isDrawPileLow($lowPile, 100));
        $this->assertFalse(CardService::isDrawPileLow($goodPile, 100));
    }

    public function testGetDrawPileWarning(): void
    {
        $lowPile = array_fill(0, 50, 1);
        $goodPile = array_fill(0, 150, 1);

        $warning = CardService::getDrawPileWarning($lowPile, 100);
        $this->assertNotNull($warning);
        $this->assertStringContainsString('50 cards remaining', $warning);

        $noWarning = CardService::getDrawPileWarning($goodPile, 100);
        $this->assertNull($noWarning);
    }

    public function testReshuffleDiscardPile(): void
    {
        $drawPile = [1, 2, 3, 4, 5];
        $discardPile = [10, 11, 12, 13, 14];

        $result = CardService::reshuffleDiscardPile($drawPile, $discardPile);

        // Verify discard pile is empty
        $this->assertEmpty($result['discard_pile']);

        // Verify all cards are in draw pile
        $this->assertCount(10, $result['draw_pile']);

        // Verify original draw pile cards are at the top (in same order)
        $topCards = array_slice($result['draw_pile'], 0, 5);
        $this->assertEquals($drawPile, $topCards);

        // Verify discard pile cards are at the bottom (shuffled)
        $bottomCards = array_slice($result['draw_pile'], 5);
        $this->assertCount(5, $bottomCards);
        foreach ($discardPile as $cardId) {
            $this->assertContains($cardId, $bottomCards);
        }
    }

    public function testReshuffleEmptyDiscardPile(): void
    {
        $drawPile = [1, 2, 3];
        $discardPile = [];

        $result = CardService::reshuffleDiscardPile($drawPile, $discardPile);

        $this->assertEquals($drawPile, $result['draw_pile']);
        $this->assertEmpty($result['discard_pile']);
    }
}
