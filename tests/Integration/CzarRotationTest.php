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

        $result4 = GameService::joinGame($gameId, 'Player 4');

        // Start game
        $gameState = GameService::startGame($gameId, $player1Id);
        $firstCzarId = $gameState['current_czar_id'];

        // Verify order is not locked yet
        $this->assertFalse($gameState['order_locked']);
        $this->assertEmpty($gameState['player_order']);

        // Fetch fresh game state from database (not filtered)
        $game = Game::find($gameId);
        $gameState = $game['player_data'];

        // Submit cards for all non-czar players
        $nonCzarPlayers = array_filter(
            $gameState['players'],
            fn(array $p): bool => $p['id'] !== $firstCzarId && empty($p['is_rando'])
        );

        foreach ($nonCzarPlayers as $player) {
            $promptCardId = is_array($gameState['current_prompt_card'])
                ? $gameState['current_prompt_card']['card_id']
                : $gameState['current_prompt_card'];
            $blanksNeeded = CardService::getPromptCardChoices($promptCardId);
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
        array_values(array_filter($playersToRotate, fn(string $id): bool => $id !== $firstCzarId));

        // If firstCzar is one of our players, we need to adjust
        if (in_array($firstCzarId, [$player1Id, $player2Id, $player3Id], true)) {
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

        // Start game (first czar is random)
        $gameState = GameService::startGame($gameId, $player1Id);
        $firstCzar = $gameState['current_czar_id'];

        $allPlayers = [$player1Id, $player2Id, $player3Id, $player4Id];
        $playerToSkip = null;
        $activePlayers = [];

        // Determine which player to skip (not the first czar)
        $nonCzarPlayers = array_filter($allPlayers, fn(string $id): bool => $id !== $firstCzar);
        $nonCzarPlayers = array_values($nonCzarPlayers);

        // Skip the middle player (index 1 of non-czar players)
        $playerToSkip = $nonCzarPlayers[1];
        $activePlayers = [$nonCzarPlayers[0], $nonCzarPlayers[2]];

        // Play rounds to create order, skipping one player
        // Round 1: first -> active[0]
        $gameState = $this->playRoundAndSetNextCzar($gameId, $firstCzar, $activePlayers[0]);
        $this->assertFalse($gameState['order_locked']);

        // Round 2: active[0] -> active[1]
        $gameState = $this->playRoundAndSetNextCzar($gameId, $activePlayers[0], $activePlayers[1]);
        $this->assertFalse($gameState['order_locked']);

        // Round 3: active[1] -> first (complete circle, detect skip)
        $gameState = $this->playRoundAndSetNextCzar($gameId, $activePlayers[1], $firstCzar);

        // Order should NOT be locked yet due to skipped player
        $this->assertFalse($gameState['order_locked']);
        $this->assertArrayHasKey('skipped_players', $gameState);
        $this->assertContains($playerToSkip, $gameState['skipped_players']['ids']);
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

        // Start game and create order with one player skipped
        $gameState = GameService::startGame($gameId, $creatorId);
        $firstCzar = $gameState['current_czar_id'];

        $allPlayers = [$creatorId, $player2Id, $player3Id];
        $playerToSkip = null;
        $activePlayers = [];

        // Determine which player to skip (not the first czar)
        foreach ($allPlayers as $pid) {
            if ($pid !== $firstCzar) {
                if ($playerToSkip === null) {
                    $playerToSkip = $pid; // Skip the first non-czar
                } else {
                    $activePlayers[] = $pid;
                }
            }
        }
        $activePlayers[] = $firstCzar; // Add first czar at the end

        // Play rounds to create order, skipping one player
        $gameState = $this->playRoundAndSetNextCzar($gameId, $firstCzar, $activePlayers[0]);
        $gameState = $this->playRoundAndSetNextCzar($gameId, $activePlayers[0], $firstCzar);

        // Verify the skipped player was detected
        $this->assertArrayHasKey('skipped_players', $gameState);
        $this->assertContains($playerToSkip, $gameState['skipped_players']['ids']);

        // Place the skipped player somewhere in the order
        // We know playerToSkip is skipped, activePlayers[0] and firstCzar are in order
        // Place playerToSkip before activePlayers[0]
        $gameState = GameService::placeSkippedPlayer($gameId, $creatorId, $playerToSkip, $activePlayers[0]);

        // Verify player is placed correctly
        $this->assertCount(3, $gameState['player_order'], 'Order should have all 3 players');
        $this->assertTrue($gameState['order_locked']);
        $this->assertArrayNotHasKey('skipped_players', $gameState);
        $this->assertContains($playerToSkip, $gameState['player_order'], 'Skipped player should now be in order');
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

        $gameState = GameService::startGame($gameId, $creatorId);
        $firstCzar = $gameState['current_czar_id'];

        $allPlayers = [$creatorId, $player2Id, $player3Id];
        $nonCzarPlayers = array_values(array_filter($allPlayers, fn(string $id): bool => $id !== $firstCzar));

        // Create rotation skipping one player
        $this->playRoundAndSetNextCzar($gameId, $firstCzar, $nonCzarPlayers[0]);
        $this->playRoundAndSetNextCzar($gameId, $nonCzarPlayers[0], $firstCzar);

        // Find who was skipped and who is not creator
        $skippedPlayerId = $nonCzarPlayers[1];
        $nonCreatorId = $nonCzarPlayers[0] !== $creatorId ? $nonCzarPlayers[0] : $nonCzarPlayers[1];
        if ($nonCreatorId === $skippedPlayerId) {
            $nonCreatorId = $firstCzar !== $creatorId ? $firstCzar : $nonCzarPlayers[0];
        }

        // Try to place as non-creator (should fail)
        GameService::placeSkippedPlayer($gameId, $nonCreatorId, $skippedPlayerId, $firstCzar);
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

        // Start and build order (get the actual first czar, don't assume)
        $gameState = GameService::startGame($gameId, $creatorId);
        $firstCzar = $gameState['current_czar_id'];

        // Build a full rotation to lock the order
        // We need to know who comes after whom, so let's just play 4 rounds
        $allPlayers = [$creatorId, $player2Id, $player3Id, $player4Id];
        $nonFirstCzar = array_values(array_filter($allPlayers, fn(string $id): bool => $id !== $firstCzar));

        // Round 1: first czar -> player A
        $this->playRoundAndSetNextCzar($gameId, $firstCzar, $nonFirstCzar[0]);
        // Round 2: player A -> player B
        $this->playRoundAndSetNextCzar($gameId, $nonFirstCzar[0], $nonFirstCzar[1]);
        // Round 3: player B -> player C
        $this->playRoundAndSetNextCzar($gameId, $nonFirstCzar[1], $nonFirstCzar[2]);
        // Round 4: player C -> first czar (complete circle)
        $gameState = $this->playRoundAndSetNextCzar($gameId, $nonFirstCzar[2], $firstCzar);

        // Order is now locked
        $this->assertTrue($gameState['order_locked']);

        // Remove Player 3 (who is not currently the czar)
        GameService::removePlayer($gameId, $creatorId, $player3Id);

        // Get the current state to see who the czar is now
        $game = Game::find($gameId);
        $gameState = $game['player_data'];
        $currentCzarId = $gameState['current_czar_id'];

        // Play a round with current czar
        $gameState = $this->playRoundAndSetNextCzar($gameId, $currentCzarId, null);

        // The next czar should skip over the removed player
        // We can't assert which specific player it is without knowing the order,
        // but we can verify player3 is NOT the czar
        $this->assertNotEquals($player3Id, $gameState['current_czar_id'], 'Removed player should not be czar');
    }

    /**
     * Helper function to play a round and set next czar
     */
    private function playRoundAndSetNextCzar(string $gameId, string $currentCzarId, ?string $nextCzarId): array
    {
        $game = Game::find($gameId);
        $gameState = $game['player_data'];

        // Verify the current czar matches (in case game state has changed)
        if ($gameState['current_czar_id'] !== $currentCzarId) {
            throw new \Exception("Czar mismatch: expected {$currentCzarId}, actual {$gameState['current_czar_id']}");
        }

        // Get the prompt card to determine how many cards to submit
        $promptCard = $gameState['current_prompt_card'];
        $promptCardId = is_array($promptCard) ? $promptCard['card_id'] : $promptCard;

        // Get the number of blanks from the prompt card
        $blanksNeeded = CardService::getPromptCardChoices($promptCardId);

        // Submit cards for all non-czar players
        foreach ($gameState['players'] as $player) {
            $isCzar = ($player['id'] === $currentCzarId);
            $isRando = ! empty($player['is_rando']);
            $hasHand = ! empty($player['hand']);

            if ( ! $isCzar && ! $isRando && $hasHand) {
                $cardIds = array_slice($this->getCardIds($player['hand']), 0, $blanksNeeded);
                if (count($cardIds) === $blanksNeeded) {
                    RoundService::submitCards($gameId, $player['id'], $cardIds);
                }
            }
        }

        // Pick a winner
        $updatedState = Game::find($gameId)['player_data'];
        if ( ! empty($updatedState['submissions'])) {
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
        GameService::setNextCzar($gameId, $currentCzarId, $nextCzarId);

        // Advance round
        return RoundService::advanceToNextRound($gameId);
    }

    /**
     * Extract card IDs from array of cards (which might be hydrated or not)
     */
    private function getCardIds(array $cards): array
    {
        if ($cards === []) {
            return [];
        }
        return is_array($cards[0]) ? array_column($cards, 'card_id') : $cards;
    }
}
