<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\RoundService;
use CAH\Services\GameService;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Enums\GameEndReason;
use CAH\Exceptions\UnauthorizedException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\InvalidGameStateException;

/**
 * Round Service Integration Tests
 */
class RoundServiceTest extends TestCase
{
    private function createPlayingGame(): array
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $gameState = GameService::startGame($gameId, $creatorId);

        return [
            'game_id' => $gameId,
            'game_state' => $gameState,
        ];
    }
    
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

    public function testSubmitCardsSuccessfully(): void
    {
        $game = $this->createPlayingGame();
        $gameId = $game['game_id'];
        $gameState = $game['game_state'];

        $czarId = $gameState['current_czar_id'];
        $nonCzarPlayer = null;

        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $nonCzarPlayer = $player;
                break;
            }
        }

        // Get how many cards the black card requires
        $blackCardId = $this->getCardId($gameState['current_black_card']);
        $requiredCards = CardService::getBlackCardChoices($blackCardId);
        $cardsToSubmit = $this->getCardIds(array_slice($nonCzarPlayer['hand'], 0, $requiredCards));
        $result = RoundService::submitCards($gameId, $nonCzarPlayer['id'], $cardsToSubmit);

        $this->assertCount(1, $result['submissions']);
        $this->assertEquals($nonCzarPlayer['id'], $result['submissions'][0]['player_id']);
    }

    public function testCzarCannotSubmitCards(): void
    {
        $game = $this->createPlayingGame();
        $gameId = $game['game_id'];
        $gameState = $game['game_state'];

        $czarId = $gameState['current_czar_id'];
        $czar = GameService::findPlayer($gameState, $czarId);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Card Czar cannot submit cards');

        RoundService::submitCards($gameId, $czarId, [1]);
    }

    public function testCannotSubmitWrongNumberOfCards(): void
    {
        $game = $this->createPlayingGame();
        $gameId = $game['game_id'];
        $gameState = $game['game_state'];

        $czarId = $gameState['current_czar_id'];
        $nonCzarPlayer = null;

        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $nonCzarPlayer = $player;
                break;
            }
        }

        // Get how many cards are required and submit wrong number
        $blackCardId = $this->getCardId($gameState['current_black_card']);
        $requiredCards = CardService::getBlackCardChoices($blackCardId);
        $wrongNumber = $requiredCards + 1; // Submit one more than required
        $cardsToSubmit = $this->getCardIds(array_slice($nonCzarPlayer['hand'], 0, $wrongNumber));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Must submit exactly');

        RoundService::submitCards($gameId, $nonCzarPlayer['id'], $cardsToSubmit);
    }

    public function testCannotSubmitCardsNotInHand(): void
    {
        $game = $this->createPlayingGame();
        $gameId = $game['game_id'];
        $gameState = $game['game_state'];

        $czarId = $gameState['current_czar_id'];
        $nonCzarPlayer = null;

        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $nonCzarPlayer = $player;
                break;
            }
        }

        // Get how many cards the black card requires
        $blackCardId = $this->getCardId($gameState['current_black_card']);
        $requiredCards = CardService::getBlackCardChoices($blackCardId);

        // Try to submit cards not in hand (use correct number of invalid cards)
        $invalidCards = array_fill(0, $requiredCards, 99999);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('do not have');

        RoundService::submitCards($gameId, $nonCzarPlayer['id'], $invalidCards);
    }

    public function testCannotSubmitTwice(): void
    {
        $game = $this->createPlayingGame();
        $gameId = $game['game_id'];
        $gameState = $game['game_state'];

        $czarId = $gameState['current_czar_id'];
        $nonCzarPlayer = null;

        foreach ($gameState['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $nonCzarPlayer = $player;
                break;
            }
        }

        // Get how many cards the black card requires
        $blackCardId = $this->getCardId($gameState['current_black_card']);
        $requiredCards = CardService::getBlackCardChoices($blackCardId);
        $cardsToSubmit = $this->getCardIds(array_slice($nonCzarPlayer['hand'], 0, $requiredCards));

        // First submission should succeed
        RoundService::submitCards($gameId, $nonCzarPlayer['id'], $cardsToSubmit);

        // Second submission should fail
        $this->expectException(InvalidGameStateException::class);
        $this->expectExceptionMessage('already submitted');

        RoundService::submitCards($gameId, $nonCzarPlayer['id'], $cardsToSubmit);
    }

    public function testCheckForWinner(): void
    {
        $playerData = [
            'settings' => ['max_score' => 5],
            'players' => [
                ['id' => '1', 'name' => 'Player 1', 'score' => 3],
                ['id' => '2', 'name' => 'Player 2', 'score' => 5],
                ['id' => '3', 'name' => 'Player 3', 'score' => 2],
            ],
        ];

        $winner = RoundService::checkForWinner($playerData);

        $this->assertNotNull($winner);
        $this->assertEquals('2', $winner['id']);
        $this->assertEquals(5, $winner['score']);
    }

    public function testCheckForWinnerReturnsNullWhenNoWinner(): void
    {
        $playerData = [
            'settings' => ['max_score' => 10],
            'players' => [
                ['id' => '1', 'name' => 'Player 1', 'score' => 3],
                ['id' => '2', 'name' => 'Player 2', 'score' => 5],
            ],
        ];

        $winner = RoundService::checkForWinner($playerData);

        $this->assertNull($winner);
    }

    public function testGameEndsWhenOutOfBlackCards(): void
    {
        $game = $this->createPlayingGame();
        $gameId = $game['game_id'];

        // Work with the authoritative game state from the database (non-hydrated)
        $currentGame = Game::find($gameId);
        $playerData = $currentGame['player_data'];

        $czarId = $playerData['current_czar_id'];
        $blackCardId = $playerData['current_black_card'];
        $requiredCards = CardService::getBlackCardChoices($blackCardId);

        // Have all non-czar players submit the correct number of cards
        foreach ($playerData['players'] as $player) {
            if ($player['id'] === $czarId || ! empty($player['is_rando'])) {
                continue;
            }

            $handCards = array_slice($player['hand'], 0, $requiredCards);

            // Sanity check: make sure the test setup actually gave this player enough cards
            $this->assertCount(
                $requiredCards,
                $handCards,
                'Test setup error: player does not have enough cards to submit'
            );

            RoundService::submitCards($gameId, $player['id'], $handCards);
        }

        // Reload game to get submissions and current draw pile
        $currentGame = Game::find($gameId);
        $playerData = $currentGame['player_data'];

        // Give the first non-czar player a higher score so we can verify winner detection
        $expectedWinnerId = null;
        foreach ($playerData['players'] as &$p) {
            if ($p['id'] !== $czarId) {
                $p['score'] = 5;
                $expectedWinnerId = $p['id'];
                break;
            }
        }

        // Empty the black pile to trigger the game-ending condition
        Game::update($gameId, [
            'draw_pile' => ['white' => $currentGame['draw_pile']['white'], 'black' => []],
            'player_data' => $playerData,
        ]);

        // Advance to next round - should trigger game end due to no black cards
        $result = RoundService::advanceToNextRound($gameId);

        // Verify game ended correctly
        $this->assertEquals('finished', $result['state']);
        $this->assertEquals($expectedWinnerId, $result['winner_id']);
        $this->assertArrayHasKey('finished_at', $result);
        $this->assertNotEmpty($result['finished_at']);
        $this->assertEquals(GameEndReason::NO_BLACK_CARDS_LEFT->value, $result['end_reason'] ?? null);
    }
}
