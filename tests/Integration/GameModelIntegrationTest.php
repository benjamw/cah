<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Database\Database;
use CAH\Models\Game;
use CAH\Tests\TestCase;

class GameModelIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM games WHERE game_id LIKE 'GM%'");
        parent::tearDown();
    }

    private function basePlayerData(string $creatorId = 'p1'): array
    {
        return [
            'settings' => [
                'rando_enabled' => false,
                'unlimited_renew' => false,
                'max_score' => 8,
                'hand_size' => 10,
            ],
            'state' => 'waiting',
            'creator_id' => $creatorId,
            'players' => [
                [
                    'id' => $creatorId,
                    'name' => 'Creator',
                    'score' => 0,
                    'hand' => [],
                    'is_creator' => true,
                ],
            ],
            'player_order' => [],
            'order_locked' => false,
            'current_czar_id' => null,
            'current_prompt_card' => null,
            'current_round' => 0,
            'submissions' => [],
        ];
    }

    public function testCreateFindExistsUpdateAndDeleteFlow(): void
    {
        $gameId = 'GM01';
        $tags = [TEST_TAG_ID];
        $drawPile = ['response' => [1, 2, 3], 'prompt' => [301, 302]];
        $playerData = $this->basePlayerData();

        $created = Game::create($gameId, $tags, $drawPile, $playerData);
        $this->assertTrue($created);
        $this->assertTrue(Game::exists($gameId));

        $found = Game::find($gameId);
        $this->assertNotNull($found);
        $this->assertSame($gameId, $found['game_id']);
        $this->assertSame($tags, $found['tags']);

        $updated = Game::update($gameId, [
            'draw_pile' => ['response' => [9, 8], 'prompt' => [307]],
            'discard_pile' => [1001, 1002],
        ]);
        $this->assertSame(1, $updated);

        $drawPileValue = Game::getDrawPile($gameId);
        $this->assertSame([9, 8], $drawPileValue['response']);
        $this->assertSame([307], $drawPileValue['prompt']);

        $playerData['state'] = 'playing';
        $playerData['current_round'] = 2;
        $updatedPlayerData = Game::updatePlayerData($gameId, $playerData);
        $this->assertSame(1, $updatedPlayerData);
        $this->assertSame('playing', Game::getPlayerData($gameId)['state']);

        $pilesUpdated = Game::updatePiles($gameId, ['response' => [7], 'prompt' => [306]], [2001]);
        $this->assertSame(1, $pilesUpdated);
        $latestDrawPile = Game::getDrawPile($gameId);
        $this->assertSame([7], $latestDrawPile['response']);
        $this->assertSame([306], $latestDrawPile['prompt']);

        $this->assertGreaterThanOrEqual(1, Game::getActiveCount());

        $deleted = Game::delete($gameId);
        $this->assertSame(1, $deleted);
        $this->assertFalse(Game::exists($gameId));
        $this->assertNull(Game::find($gameId));
        $this->assertNull(Game::getDrawPile($gameId));
        $this->assertNull(Game::getPlayerData($gameId));
    }

    public function testRoundHistoryAndAgeBasedQueries(): void
    {
        $gameId = 'GM02';
        Game::create($gameId, [TEST_TAG_ID], ['response' => [1], 'prompt' => [301]], $this->basePlayerData('p2'));

        $append = Game::appendRoundHistory($gameId, [
            'round' => 1,
            'winner_id' => 'p2',
            'prompt_card' => 301,
        ]);
        $this->assertSame(1, $append);

        $history = Game::getRoundHistory($gameId);
        $this->assertIsArray($history);
        $firstRound = is_string($history[0]) ? json_decode($history[0], true) : $history[0];
        $this->assertIsArray($firstRound);
        $this->assertSame(1, $firstRound['round']);
        $this->assertSame('p2', $firstRound['winner_id']);

        // Age the game so getOlderThan/deleteOlderThan have deterministic coverage.
        Database::execute(
            "UPDATE games SET created_at = DATE_SUB(NOW(), INTERVAL 14 DAY) WHERE game_id = ?",
            [$gameId]
        );

        $older = Game::getOlderThan(7);
        $this->assertContains($gameId, $older);

        $deletedOlder = Game::deleteOlderThan(7);
        $this->assertGreaterThanOrEqual(1, $deletedOlder);
        $this->assertNull(Game::find($gameId));
    }
}

