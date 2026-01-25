<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\GameService;
use CAH\Services\RoundService;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Exceptions\ValidationException;

/**
 * Czar Rotation Integration Tests
 *
 * Tests the czar rotation system, player order building, and skipped player detection
 */
class CzarRotationTest extends TestCase
{
    /**
     * Test that player order builds correctly during first rotation
     */
    public function testPlayerOrderBuildsCorrectly(): void
    {
        // Create game with 4 players
        $createResult = GameService::createGame('Player 1', [TEST_TAG_ID], [
            'max_score' => 10,
            'hand_size' => 10,
        ]);
        $gameId = $createResult['game_id'];
        $player1Id = $createResult['player_id'];

        $result2 = GameService::joinGame($gameId, 'Player 2');
        $player2Id = $result2['player_id'];

        $result3 = GameService::joinGame($gameId, 'Player 3');
        $player3Id = $result3['player_id'];

        $result4 = GameService::joinGame($gameId, 'Player 4');
        $player4Id = $result4['player_id'];

        // Start game
        $gameState = GameService::startGame($gameId, $player1Id);
        $firstCzarId = $gameState['current_czar_id'];

        // Verify order is not locked yet
        $this->assertFalse($gameState['order_locked']);
        $this->assertEmpty($gameState['player_order']);

        // Submit cards for all non-czar players
        $nonCzarPlayers = array_filter(
            $gameState['players'],
            fn($p) => $p['id'] !== $firstCzarId && empty($p['is_rando'])
        );
        
        foreach ($nonCzarPlayers as $player) {
            $blackCardId = is_array($gameState['current_black_card']) 
                ? $gameState['current_black_card']['card_id'] 
                : $gameState['current_black_card'];
            $blanksNeeded = CardService::getBlackCardChoices($blackCardId);
            $cardIds = array_slice($this->getCardIds($player['hand']), 0, $blanksNeeded);
            if (count($cardIds) === $blanksNeeded) {
                RoundService::submitCards($gameId, $player['id'], $cardIds);
            }
        }

        // Pick a winner
        $updatedState = Game::find($gameId)['player_data'];
        $winnerId = $updatedState['submissions'][0]['player_id'];
        $gameState = RoundService::pickWinner($gameId, $firstCzarId, $winnerId);

        // Set next czar (Player 2)
        $gameState = GameService::setNextCzar($gameId, $firstCzarId, $player2Id);

        // Verify player order has both czars now
        $this->assertContains($firstCzarId, $gameState['player_order']);
        $this->assertContains($player2Id, $gameState['player_order']);
        $this->assertFalse($gameState['order_locked']);
    }

    /**
     * Test that order locks when it completes a full circle
     */
    public function testOrderLocksWhenCircleCompletes(): void
    {
        // Create game with 3 players for faster testing
        $createResult = GameService::createGame('Player 1', [TEST_TAG_ID], [
            'max_score' => 10,
            'hand_size' => 10,
        ]);
        $gameId = $createResult['game_id'];
        $player1Id = $createResult['player_id'];

        $result2 = GameService::joinGame($gameId, 'Player 2');
        $player2Id = $result2['player_id'];

        $result3 = GameService::joinGame($gameId, 'Player 3');
        $player3Id = $result3['player_id'];

        // Start game
        $gameState = GameService::startGame($gameId, $player1Id);
        $firstCzarId = $gameState['current_czar_id']; // Could be any player, randomly selected

        // Build a specific order: firstCzar -> player1 -> player2 -> player3 -> back to firstCzar
        // But we need to ensure player1, 2, 3 are all different from firstCzar
        $playersToRotate = [$player1Id, $player2Id, $player3Id];
        $nextPlayers = array_values(array_filter($playersToRotate, fn($id) => $id !== $firstCzarId));
        
        // If firstCzar is one of our players, we need to adjust
        if ($firstCzarId === $player1Id || $firstCzarId === $player2Id || $firstCzarId === $player3Id) {
            // Just rotate through all 3 players in order
            $gameState = $this->playRoundAndSetNextCzar($gameId, $firstCzarId, $playersToRotate[0]);
            $gameState = $this->playRoundAndSetNextCzar($gameId, $playersToRotate[0], $playersToRotate[1]);
            $gameState = $this->playRoundAndSetNextCzar($gameId, $playersToRotate[1], $playersToRotate[2]);
            $gameState = $this->playRoundAndSetNextCzar($gameId, $playersToRotate[2], $firstCzarId);
            
            // Order should now be locked
            $this->assertTrue($gameState['order_locked'], 'Order should lock when completing circle');
        } else {
            // FirstCzar is not one of our 3 players, this shouldn't happen in this test setup
            $this->fail('Unexpected czar selection');
        }
    }

    /**
     * Test that skipped players are detected
     */
    public function testSkippedPlayersAreDetected(): void
    {
        // Create game with 4 players
        $createResult = GameService::createGame('Player 1', [TEST_TAG_ID], [
            'max_score' => 10,
            'hand_size' => 10,
        ]);
        $gameId = $createResult['game_id'];
        $player1Id = $createResult['player_id'];

        $result2 = GameService::joinGame($gameId, 'Player 2');
        $player2Id = $result2['player_id'];

        $result3 = GameService::joinGame($gameId, 'Player 3');
        $player3Id = $result3['player_id'];

        $result4 = GameService::joinGame($gameId, 'Player 4');
        $player4Id = $result4['player_id'];

        // Start game (Player 1 is first czar)
        $gameState = GameService::startGame($gameId, $player1Id);

        // Skip Player 2: 1 -> 3 -> 4 -> 1
        $gameState = $this->playRoundAndSetNextCzar($gameId, $player1Id, $player3Id);
        $this->assertFalse($gameState['order_locked']);

        $gameState = $this->playRoundAndSetNextCzar($gameId, $player3Id, $player4Id);
        $this->assertFalse($gameState['order_locked']);

        // Complete circle back to Player 1 (skipping Player 2)
        $gameState = $this->playRoundAndSetNextCzar($gameId, $player4Id, $player1Id);
        
        // Order should NOT be locked yet due to skipped player
        $this->assertFalse($gameState['order_locked']);
        $this->assertArrayHasKey('skipped_players', $gameState);
        $this->assertContains($player2Id, $gameState['skipped_players']['ids']);
        $this->assertContains('Player 2', $gameState['skipped_players']['names']);
    }

    /**
     * Test placing a skipped player in the order
     */
    public function testPlaceSkippedPlayer(): void
    {
        // Create game with 3 players
        $createResult = GameService::createGame('Player 1', [TEST_TAG_ID], [
            'max_score' => 10,
            'hand_size' => 10,
        ]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $result2 = GameService::joinGame($gameId, 'Player 2');
        $player2Id = $result2['player_id'];

        $result3 = GameService::joinGame($gameId, 'Player 3');
        $player3Id = $result3['player_id'];

        // Start game and create order with Player 2 skipped
        GameService::startGame($gameId, $creatorId);
        $this->playRoundAndSetNextCzar($gameId, $creatorId, $player3Id);
        $gameState = $this->playRoundAndSetNextCzar($gameId, $player3Id, $creatorId);

        // Verify Player 2 is skipped
        $this->assertArrayHasKey('skipped_players', $gameState);
        $this->assertContains($player2Id, $gameState['skipped_players']['ids']);

        // Place Player 2 before Player 3 (between creator and player 3)
        $gameState = GameService::placeSkippedPlayer($gameId, $creatorId, $player2Id, $player3Id);

        // Verify player is placed correctly
        $this->assertEquals([$creatorId, $player2Id, $player3Id], $gameState['player_order']);
        $this->assertTrue($gameState['order_locked']);
        $this->assertArrayNotHasKey('skipped_players', $gameState);
    }

    /**
     * Test that only creator can place skipped players
     */
    public function testOnlyCreatorCanPlaceSkippedPlayers(): void
    {
        $this->expectException(\CAH\Exceptions\UnauthorizedException::class);

        // Create game with skipped players
        $createResult = GameService::createGame('Player 1', [TEST_TAG_ID], [
            'max_score' => 10,
            'hand_size' => 10,
        ]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $result2 = GameService::joinGame($gameId, 'Player 2');
        $player2Id = $result2['player_id'];

        $result3 = GameService::joinGame($gameId, 'Player 3');
        $player3Id = $result3['player_id'];

        GameService::startGame($gameId, $creatorId);
        $this->playRoundAndSetNextCzar($gameId, $creatorId, $player3Id);
        $this->playRoundAndSetNextCzar($gameId, $player3Id, $creatorId);

        // Try to place as non-creator (should fail)
        GameService::placeSkippedPlayer($gameId, $player3Id, $player2Id, $player3Id);
    }

    /**
     * Test getNextCzar filters out players who left
     */
    public function testGetNextCzarSkipsRemovedPlayers(): void
    {
        // Create game with 4 players
        $createResult = GameService::createGame('Player 1', [TEST_TAG_ID], [
            'max_score' => 10,
            'hand_size' => 10,
        ]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        $result2 = GameService::joinGame($gameId, 'Player 2');
        $player2Id = $result2['player_id'];

        $result3 = GameService::joinGame($gameId, 'Player 3');
        $player3Id = $result3['player_id'];

        $result4 = GameService::joinGame($gameId, 'Player 4');
        $player4Id = $result4['player_id'];

        // Start and build order: 1 -> 2 -> 3 -> 4 -> 1
        GameService::startGame($gameId, $creatorId);
        $this->playRoundAndSetNextCzar($gameId, $creatorId, $player2Id);
        $this->playRoundAndSetNextCzar($gameId, $player2Id, $player3Id);
        $this->playRoundAndSetNextCzar($gameId, $player3Id, $player4Id);
        $gameState = $this->playRoundAndSetNextCzar($gameId, $player4Id, $creatorId);

        // Order is now locked
        $this->assertTrue($gameState['order_locked']);

        // Remove Player 3
        GameService::removePlayer($gameId, $creatorId, $player3Id);

        // Play a round with creator as czar, next should be Player 2 (not removed Player 3)
        $gameState = $this->playRoundAndSetNextCzar($gameId, $creatorId, null);
        
        // Verify next czar is Player 2 (skipping removed Player 3)
        $this->assertEquals($player2Id, $gameState['current_czar_id']);
    }

    /**
     * Helper function to play a round and set next czar
     */
    private function playRoundAndSetNextCzar(string $gameId, string $currentCzarId, ?string $nextCzarId): array
    {
        $game = Game::find($gameId);
        $gameState = $game['player_data'];
        
        // Get the black card to determine how many cards to submit
        $blackCard = $gameState['current_black_card'];
        $blackCardId = is_array($blackCard) ? $blackCard['card_id'] : $blackCard;
        
        // Get the number of blanks from the black card
        $blanksNeeded = CardService::getBlackCardChoices($blackCardId);

        // Submit cards for all non-czar players
        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $currentCzarId && empty($player['is_rando']) && !empty($player['hand'])) {
                $cardIds = array_slice($this->getCardIds($player['hand']), 0, $blanksNeeded);
                if (count($cardIds) === $blanksNeeded) {
                    RoundService::submitCards($gameId, $player['id'], $cardIds);
                }
            }
        }

        // Pick a winner
        $updatedState = Game::find($gameId)['player_data'];
        if (!empty($updatedState['submissions'])) {
            $winnerId = $updatedState['submissions'][0]['player_id'];
            $gameState = RoundService::pickWinner($gameId, $currentCzarId, $winnerId);
        }

        // If nextCzarId is null, use automatic selection
        if ($nextCzarId === null) {
            // Refetch game state to get updated player_data after picking winner
            $updatedGame = Game::find($gameId);
            $nextCzarId = GameService::getNextCzar($updatedGame['player_data']);
        }

        // Set next czar
        $gameState = GameService::setNextCzar($gameId, $currentCzarId, $nextCzarId);

        // Advance round
        return RoundService::advanceToNextRound($gameId);
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
}
