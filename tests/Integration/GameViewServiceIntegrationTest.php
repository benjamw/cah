<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Services\GameViewService;
use CAH\Tests\TestCase;

class GameViewServiceIntegrationTest extends TestCase
{
    public function testHydrateCardsHydratesCoreGameCardFields(): void
    {
        $playerData = [
            'state' => 'playing',
            'players' => [
                ['id' => 'p1', 'hand' => [1, 2]],
                ['id' => 'p2', 'hand' => [3]],
            ],
            'current_prompt_card' => 301,
            'submissions' => [
                ['player_id' => 'p1', 'cards' => [4, 5]],
            ],
        ];

        $hydrated = GameViewService::hydrateCards($playerData);

        $this->assertIsArray($hydrated['players'][0]['hand'][0]);
        $this->assertArrayHasKey('card_id', $hydrated['players'][0]['hand'][0]);
        $this->assertIsArray($hydrated['current_prompt_card']);
        $this->assertSame(301, $hydrated['current_prompt_card']['card_id']);
        $this->assertIsArray($hydrated['submissions'][0]['cards'][0]);
    }

    public function testHydrateCardsHandlesMissingCardsWithUnknownPlaceholder(): void
    {
        $missingCardId = 999999;
        $playerData = [
            'state' => 'playing',
            'players' => [
                ['id' => 'p1', 'hand' => [$missingCardId]],
            ],
            'current_prompt_card' => $missingCardId,
            'submissions' => [
                ['player_id' => 'p1', 'cards' => [$missingCardId]],
            ],
        ];

        $hydrated = GameViewService::hydrateCards($playerData);

        $this->assertSame('Unknown', $hydrated['players'][0]['hand'][0]['copy']);
        $this->assertSame('Unknown', $hydrated['current_prompt_card']['copy']);
        $this->assertSame('Unknown', $hydrated['submissions'][0]['cards'][0]['copy']);
    }

    public function testFilterHandsHidesOtherHandsAndPartialSubmissions(): void
    {
        $playerData = [
            'players' => [
                ['id' => 'czar', 'hand' => []],
                ['id' => 'p1', 'hand' => [['card_id' => 1]]],
                ['id' => 'p2', 'hand' => [['card_id' => 2]]],
            ],
            'current_czar_id' => 'czar',
            'submissions' => [
                ['player_id' => 'p1', 'cards' => [['card_id' => 1]]],
            ],
        ];

        $filtered = GameViewService::filterHands($playerData, 'p1');

        $this->assertNotEmpty($filtered['players'][1]['hand']);
        $this->assertSame([], $filtered['players'][0]['hand']);
        $this->assertSame([], $filtered['players'][2]['hand']);
        $this->assertSame(['submitted' => true], $filtered['submissions'][0]);
    }

    public function testFilterHandsShowsFinalSubmissionStateForCzar(): void
    {
        $playerData = [
            'players' => [
                ['id' => 'czar', 'hand' => []],
                ['id' => 'p1', 'hand' => [['card_id' => 1]]],
                ['id' => 'p2', 'hand' => [['card_id' => 2]]],
            ],
            'current_czar_id' => 'czar',
            'submissions' => [
                ['player_id' => 'p1', 'cards' => [['card_id' => 1]]],
                ['player_id' => 'p2', 'cards' => [['card_id' => 2]]],
            ],
        ];

        $filtered = GameViewService::filterHands($playerData, 'czar');

        $this->assertCount(2, $filtered['submissions']);
        $this->assertArrayHasKey('player_id', $filtered['submissions'][0]);
        $this->assertArrayHasKey('cards', $filtered['submissions'][0]);
    }

    public function testAddToastAndCleanExpiredToasts(): void
    {
        $playerData = [
            'toasts' => [
                ['id' => 'old', 'message' => 'old', 'created_at' => time() - 60],
            ],
        ];

        GameViewService::addToast($playerData, 'new toast', 'info');
        $this->assertCount(1, $playerData['toasts']);
        $this->assertSame('new toast', $playerData['toasts'][0]['message']);

        $cleaned = GameViewService::cleanExpiredToasts([
            'toasts' => [
                ['id' => 'fresh', 'message' => 'fresh', 'created_at' => time() - 5],
                ['id' => 'stale', 'message' => 'stale', 'created_at' => time() - 45],
            ],
        ]);

        $this->assertCount(1, $cleaned['toasts']);
        $this->assertSame('fresh', $cleaned['toasts'][0]['id']);
    }
}

