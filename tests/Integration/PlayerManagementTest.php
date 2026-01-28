<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\GameService;
use CAH\Services\RoundService;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\PlayerNotFoundException;

/**
 * Player Management Integration Tests
 */
class PlayerManagementTest extends TestCase
{
    /**
     * Extract card ID from hydrated card (which might be an array or int)
     */
    private function getCardId($card): int
    {
        return is_array($card) ? $card['card_id'] : $card;
    }
    
    /**
     * Extract card IDs from array of cards (which might be hydrated or not)
     */
    private function getCardIds(array $cards): array
    {
        if (empty($cards)) {
            return [];
        }
        return is_array($cards[0]) ? array_column($cards, 'card_id') : $cards;
    }
    
    public function testRemovePlayerFromWaitingGame(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        $player3 = GameService::joinGame($gameId, 'Player Three');
        $player4 = GameService::joinGame($gameId, 'Player Four');

        // Remove player 2 (need 4 players to remove one, min is 3)
        GameService::removePlayer($gameId, $creatorId, $player2['player_id']);

        $game = Game::find($gameId);
        $this->assertCount(3, $game['player_data']['players']);

        // Verify player 2 is not in the game
        $playerIds = array_column($game['player_data']['players'], 'id');
        $this->assertNotContains($player2['player_id'], $playerIds);
    }

    public function testRemoveNonExistentPlayer(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');
        GameService::joinGame($gameId, 'Player Four');

        $this->expectException(PlayerNotFoundException::class);

        GameService::removePlayer($gameId, $creatorId, 'non-existent-player-id');
    }

    public function testSelectNextCzar(): void
    {
        // Test setNextCzar - czar selects next czar
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        $player3 = GameService::joinGame($gameId, 'Player Three');

        $gameState = GameService::startGame($gameId, $creatorId);
        $firstCzarId = $gameState['current_czar_id'];

        // Find a different player to be next czar
        $nextCzarId = null;
        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $firstCzarId) {
                $nextCzarId = $player['id'];
                break;
            }
        }

        // Current czar selects next czar
        $result = GameService::setNextCzar($gameId, $firstCzarId, $nextCzarId);

        $this->assertArrayHasKey('player_order', $result);
        $this->assertContains($firstCzarId, $result['player_order']);
        $this->assertContains($nextCzarId, $result['player_order']);
    }

    public function testCzarRotationFollowsPlayerOrder(): void
    {
        // Test that player order is established and locked correctly
        // Simulate the full round flow: start game -> round 1 -> round 2 -> round 3
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        $player3 = GameService::joinGame($gameId, 'Player Three');

        $gameState = GameService::startGame($gameId, $creatorId);
        $allPlayerIds = [$creatorId, $player2['player_id'], $player3['player_id']];

        // Round 1: First czar selects next
        $czar1 = $gameState['current_czar_id'];
        $otherPlayers = array_filter($allPlayerIds, fn($id) => $id !== $czar1);
        $otherPlayers = array_values($otherPlayers);
        
        $result = GameService::setNextCzar($gameId, $czar1, $otherPlayers[0]);
        $this->assertContains($czar1, $result['player_order']);
        $this->assertCount(2, $result['player_order']);
        $this->assertFalse($result['order_locked'], 'Order should not be locked after round 1');

        // Round 2: Second czar selects next
        $czar2 = $otherPlayers[0];
        $result = GameService::setNextCzar($gameId, $czar2, $otherPlayers[1]);
        $this->assertCount(3, $result['player_order']);
        $this->assertFalse($result['order_locked'], 'Order should not be locked after round 2');

        // Round 3: Third czar selects first czar (completes the circle)
        $czar3 = $otherPlayers[1];
        $result = GameService::setNextCzar($gameId, $czar3, $czar1);
        
        // Now the order should be locked because we've completed the circle
        $this->assertTrue($result['order_locked'], 'Order should be locked after completing the circle');
        $this->assertCount(3, $result['player_order']);
    }

    public function testFindPlayer(): void
    {
        $playerData = [
            'players' => [
                ['id' => 'player-1', 'name' => 'Alice', 'score' => 0],
                ['id' => 'player-2', 'name' => 'Bob', 'score' => 3],
                ['id' => 'player-3', 'name' => 'Charlie', 'score' => 1],
            ],
        ];

        $player = GameService::findPlayer($playerData, 'player-2');

        $this->assertNotNull($player);
        $this->assertEquals('Bob', $player['name']);
        $this->assertEquals(3, $player['score']);
    }

    public function testFindPlayerReturnsNullForNonExistent(): void
    {
        $playerData = [
            'players' => [
                ['id' => 'player-1', 'name' => 'Alice', 'score' => 0],
            ],
        ];

        $player = GameService::findPlayer($playerData, 'non-existent');

        $this->assertNull($player);
    }

    public function testJoinGameLate(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        $player3 = GameService::joinGame($gameId, 'Player Three');

        // Start the game
        $gameState = GameService::startGame($gameId, $creatorId);

        // Get player names from game state
        $creatorName = null;
        $player2Name = null;
        foreach ($gameState['players'] as $player) {
            if ($player['id'] === $creatorId) {
                $creatorName = $player['name'];
            }
            if ($player['id'] === $player2['player_id']) {
                $player2Name = $player['name'];
            }
        }

        // Join late (requires player names, not IDs)
        $latePlayer = GameService::joinGameLate(
            $gameId,
            'Late Player',
            $creatorName,
            $player2Name
        );

        $this->assertArrayHasKey('player_id', $latePlayer);

        $game = Game::find($gameId);
        $this->assertCount(4, $game['player_data']['players']);

        // Verify player was added to the game
        $playerIds = array_column($game['player_data']['players'], 'id');
        $this->assertContains($latePlayer['player_id'], $playerIds);
    }

    public function testCannotRemoveBelowMinimumPlayers(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        $player3 = GameService::joinGame($gameId, 'Player Three');

        // Try to remove a player (would leave only 2 players, below minimum of 3)
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('would leave fewer than');

        GameService::removePlayer($gameId, $creatorId, $player2['player_id']);
    }

    public function testRemoveCzarDuringActiveRoundResetsRound(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        $player3 = GameService::joinGame($gameId, 'Player Three');
        $player4 = GameService::joinGame($gameId, 'Player Four');

        // Start the game
        $gameState = GameService::startGame($gameId, $creatorId);
        $czarId = $gameState['current_czar_id'];
        $originalBlackCard = $gameState['current_prompt_card'];

        // If creator is the czar, we can't remove them (they can't remove themselves)
        // So skip to next czar until we get a non-creator czar
        while ($czarId === $creatorId) {
            $gameState = GameService::skipCzar($gameId, $creatorId);
            $czarId = $gameState['current_czar_id'];
        }

        // Get how many cards the prompt card requires
        $promptCardId = $this->getCardId($gameState['current_prompt_card']);
        $requiredCards = CardService::getPromptCardChoices($promptCardId);

        // Non-czar players submit cards
        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $czarId && ! empty($player['hand'])) {
                $cardsToSubmit = $this->getCardIds(array_slice($player['hand'], 0, $requiredCards));
                RoundService::submitCards($gameId, $player['id'], $cardsToSubmit);
            }
        }

        // Verify submissions exist
        $game = Game::find($gameId);
        $this->assertNotEmpty($game['player_data']['submissions']);

        // Remove the czar (who is not the creator)
        $result = GameService::removePlayer($gameId, $creatorId, $czarId);

        // Verify round was reset
        $this->assertEmpty($result['submissions'], 'Submissions should be cleared');
        $this->assertNotEquals($originalBlackCard, $result['current_prompt_card'], 'New prompt card should be drawn');
        $this->assertNotEquals($czarId, $result['current_czar_id'], 'New czar should be assigned');

        // Verify submitted cards were returned to players' hands
        foreach ($result['players'] as $player) {
            if ($player['id'] !== $result['current_czar_id'] && empty($player['is_rando'])) {
                // Players should have their full hand back
                $this->assertGreaterThanOrEqual(10, count($player['hand']), 'Player should have cards returned');
            }
        }
    }

    public function testRemoveNonCzarDuringActiveRoundDoesNotResetRound(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        $player3 = GameService::joinGame($gameId, 'Player Three');
        $player4 = GameService::joinGame($gameId, 'Player Four');

        // Start the game
        $gameState = GameService::startGame($gameId, $creatorId);
        $czarId = $gameState['current_czar_id'];
        $originalBlackCardId = $this->getCardId($gameState['current_prompt_card']);

        // Find a non-czar, non-creator player to remove
        $nonCzarPlayer = null;
        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $czarId && $player['id'] !== $creatorId) {
                $nonCzarPlayer = $player;
                break;
            }
        }

        // Get how many cards the prompt card requires
        $requiredCards = CardService::getPromptCardChoices($originalBlackCardId);

        // Get full game state from database to access hands
        $game = \CAH\Models\Game::find($gameId);
        $players = $game['player_data']['players'];

        // Non-czar players submit cards
        foreach ($players as $player) {
            if ($player['id'] !== $czarId && ! empty($player['hand'])) {
                $cardsToSubmit = $this->getCardIds(array_slice($player['hand'], 0, $requiredCards));
                RoundService::submitCards($gameId, $player['id'], $cardsToSubmit);
            }
        }

        // Remove a non-czar player
        $result = GameService::removePlayer($gameId, $creatorId, $nonCzarPlayer['id']);

        // Verify round was NOT reset (compare card IDs)
        $resultBlackCardId = $this->getCardId($result['current_prompt_card']);
        $this->assertEquals($originalBlackCardId, $resultBlackCardId, 'Black card should remain the same');
        $this->assertEquals($czarId, $result['current_czar_id'], 'Czar should remain the same');

        // Submissions should still exist (minus the removed player's submission)
        $this->assertNotEmpty($result['submissions'], 'Other submissions should remain');
    }
}
