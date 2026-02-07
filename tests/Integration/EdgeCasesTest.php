<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\GameService;
use CAH\Services\RoundService;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Enums\GameState;
use CAH\Enums\GameEndReason;
use CAH\Exceptions\InsufficientCardsException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\GameException;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\PlayerNotFoundException;
use CAH\Models\Card;

/**
 * Edge Cases and Error Handling Tests
 */
class EdgeCasesTest extends TestCase
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
    
    public function testGameWithRandoCardrissian(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID], [
            'rando_enabled' => true,
        ]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $gameState = GameService::startGame($gameId, $creatorId);

        // Verify Rando was added
        $this->assertNotNull($gameState['rando_id']);
        $this->assertCount(4, $gameState['players']); // 3 real + 1 Rando

        // Find Rando player
        $randoPlayer = null;
        foreach ($gameState['players'] as $player) {
            if ( ! empty($player['is_rando'])) {
                $randoPlayer = $player;
                break;
            }
        }

        $this->assertNotNull($randoPlayer);
        $this->assertEquals('Rando Cardrissian', $randoPlayer['name']);
        $this->assertEmpty($randoPlayer['hand']); // Rando has no hand
    }

    public function testAdvanceToNextRound(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $gameState = GameService::startGame($gameId, $creatorId);
        $czarId = $gameState['current_czar_id'];
        $firstBlackCard = $gameState['current_prompt_card'];

        // Get how many cards the prompt card requires
        $promptCardId = $this->getCardId($gameState['current_prompt_card']);
        $requiredCards = CardService::getPromptCardChoices($promptCardId);

        // Get full game state from database to access all player hands
        $game = Game::find($gameId);
        $players = $game['player_data']['players'];

        // Submit cards from non-czar players
        foreach ($players as $player) {
            if ($player['id'] !== $czarId && ! empty($player['hand'])) {
                $cardsToSubmit = $this->getCardIds(array_slice($player['hand'], 0, $requiredCards));
                RoundService::submitCards($gameId, $player['id'], $cardsToSubmit);
            }
        }

        // Pick winner
        $game = Game::find($gameId);
        $winningPlayerId = $game['player_data']['submissions'][0]['player_id'];
        $gameState = RoundService::pickWinner($gameId, $czarId, $winningPlayerId);

        // Advance to next round
        $result = RoundService::advanceToNextRound($gameId);

        $this->assertEquals(2, $result['current_round']);
        $this->assertNotEquals($firstBlackCard, $result['current_prompt_card']);
        $this->assertEmpty($result['submissions']);

        // Verify players' hands were refilled (may have bonus cards if new prompt card requires 3+ choices)
        foreach ($result['players'] as $player) {
            if ($player['id'] !== $result['current_czar_id'] && empty($player['is_rando'])) {
                $this->assertGreaterThanOrEqual(10, count($player['hand']), 'Player should have at least 10 cards');
            }
        }
    }

    public function testEndGame(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $winnerId = $createResult['player_id'];

        $result = RoundService::endGame($gameId, $winnerId);

        $this->assertEquals(GameState::FINISHED->value, $result['state']);
        $this->assertEquals($winnerId, $result['winner_id']);
        $this->assertArrayHasKey('finished_at', $result);
        $this->assertEquals(GameEndReason::MAX_SCORE_REACHED->value, $result['end_reason'] ?? null);
    }

    public function testInsufficientWhiteCardsThrowsException(): void
    {
        $pile = [1, 2, 3];

        $this->expectException(InsufficientCardsException::class);

        CardService::drawResponseCards($pile, 10);
    }

    public function testInsufficientBlackCardsThrowsException(): void
    {
        $pile = [];

        $this->expectException(InsufficientCardsException::class);

        CardService::drawPromptCard($pile);
    }

    public function testMaxPlayersLimit(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];

        // Add 19 more players (total 20, which is the max)
        // Use letters only for player names (validator requirement)
        $names = ['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank', 'Grace', 'Henry',
                  'Iris', 'Jack', 'Kate', 'Leo', 'Mary', 'Nick', 'Olivia', 'Paul',
                  'Quinn', 'Rose', 'Sam'];
        foreach ($names as $name) {
            GameService::joinGame($gameId, $name);
        }

        $game = Game::find($gameId);
        $this->assertCount(20, $game['player_data']['players']);

        // Try to add 21st player
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Game is full');

        GameService::joinGame($gameId, 'Tom');
    }

    public function testCannotJoinGameInProgress(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        // Start the game
        GameService::startGame($gameId, $creatorId);

        // Try to join normally - should return game_started=true, not throw exception
        $result = GameService::joinGame($gameId, 'Late Player');

        $this->assertTrue($result['game_started']);
        $this->assertArrayHasKey('player_names', $result);
        $this->assertCount(3, $result['player_names']);
    }

    // ========== Error Handling Tests (merged from ErrorHandlingTest) ==========

    public function testValidationErrorForMissingPlayerName(): void
    {
        $this->expectException(ValidationException::class);
        GameService::createGame('', [TEST_TAG_ID], []);
    }

    public function testValidationErrorForEmptyTagArray(): void
    {
        $this->expectException(ValidationException::class);
        GameService::createGame('Player', [], []);
    }

    public function testCannotSubmitWrongNumberOfCards(): void
    {
        $createResult = GameService::createGame('Player One', [TEST_TAG_ID], []);
        $gameId = $createResult['game_id'];

        GameService::joinGame($gameId, 'Player Two');
        $player3Result = GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $createResult['player_id']);

        $game = Game::find($gameId);
        $blackCard = $game['player_data']['current_black_card'];
        $blackCardData = Card::findById($blackCard);
        $requiredCards = $blackCardData['choices'] ?? 1;

        // If we need 1 card, try submitting 2 (or vice versa)
        $wrongNumberOfCards = $requiredCards === 1 ? 2 : 1;

        $czarId = $game['player_data']['current_czar_id'];
        $playerId = $player3Result['player_id'];
        if ($playerId === $czarId) {
            $playerId = $createResult['player_id'];
        }

        $playerData = null;
        foreach ($game['player_data']['players'] as $p) {
            if ($p['id'] === $playerId) {
                $playerData = $p;
                break;
            }
        }

        $cardsToSubmit = array_slice($playerData['hand'], 0, $wrongNumberOfCards);

        $this->expectException(ValidationException::class);
        RoundService::submitCards($gameId, $playerId, $cardsToSubmit);
    }

    public function testCannotSubmitCardsNotInHand(): void
    {
        $createResult = GameService::createGame('Player One', [TEST_TAG_ID], []);
        $gameId = $createResult['game_id'];

        GameService::joinGame($gameId, 'Player Two');
        $player3Result = GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $createResult['player_id']);

        $game = Game::find($gameId);
        $czarId = $game['player_data']['current_czar_id'];
        $playerId = $player3Result['player_id'];
        if ($playerId === $czarId) {
            $playerId = $createResult['player_id'];
        }

        // Use card IDs that definitely aren't in the player's hand
        $fakeCards = [99999];

        $this->expectException(GameException::class);
        RoundService::submitCards($gameId, $playerId, $fakeCards);
    }

    public function testCannotSubmitCardsTwiceInSameRound(): void
    {
        $createResult = GameService::createGame('Player One', [TEST_TAG_ID], []);
        $gameId = $createResult['game_id'];

        GameService::joinGame($gameId, 'Player Two');
        $player3Result = GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $createResult['player_id']);

        $game = Game::find($gameId);
        $czarId = $game['player_data']['current_czar_id'];
        $playerId = $player3Result['player_id'];
        if ($playerId === $czarId) {
            $playerId = $createResult['player_id'];
        }

        $playerData = null;
        foreach ($game['player_data']['players'] as $p) {
            if ($p['id'] === $playerId) {
                $playerData = $p;
                break;
            }
        }

        $blackCard = $game['player_data']['current_black_card'];
        $blackCardData = Card::findById($blackCard);
        $choicesRequired = $blackCardData['choices'] ?? 1;

        $cardsToSubmit = array_slice($playerData['hand'], 0, $choicesRequired);

        // Submit once (should succeed)
        RoundService::submitCards($gameId, $playerId, $cardsToSubmit);

        // Try to submit again
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('already submitted');

        $game = Game::find($gameId);
        foreach ($game['player_data']['players'] as $p) {
            if ($p['id'] === $playerId) {
                $playerData = $p;
                break;
            }
        }
        $differentCards = array_slice($playerData['hand'], 0, $choicesRequired);
        RoundService::submitCards($gameId, $playerId, $differentCards);
    }

    public function testCzarCannotPickWinnerBeforeAllSubmissions(): void
    {
        $createResult = GameService::createGame('Player One', [TEST_TAG_ID], []);
        $gameId = $createResult['game_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $createResult['player_id']);

        $game = Game::find($gameId);
        $czarId = $game['player_data']['current_czar_id'];

        // Get a non-czar player ID
        $winnerId = null;
        foreach ($game['player_data']['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $winnerId = $player['id'];
                break;
            }
        }

        // Try to pick winner without submissions
        $this->expectException(GameException::class);
        RoundService::pickWinner($gameId, $czarId, $winnerId);
    }

    public function testCzarCannotPickInvalidPlayer(): void
    {
        $createResult = GameService::createGame('Player One', [TEST_TAG_ID], []);
        $gameId = $createResult['game_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $createResult['player_id']);

        $game = Game::find($gameId);
        $czarId = $game['player_data']['current_czar_id'];

        // Submit for all non-czar players
        foreach ($game['player_data']['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $blackCard = $game['player_data']['current_black_card'];
                $blackCardData = Card::findById($blackCard);
                $choicesRequired = $blackCardData['choices'] ?? 1;

                $cardsToSubmit = array_slice($player['hand'], 0, $choicesRequired);
                RoundService::submitCards($gameId, $player['id'], $cardsToSubmit);
            }
        }

        // Try to pick invalid player
        $this->expectException(PlayerNotFoundException::class);
        RoundService::pickWinner($gameId, $czarId, 'INVALID-PLAYER-ID');
    }

    public function testGameNotFoundError(): void
    {
        $this->expectException(GameNotFoundException::class);
        $fakeGame = Game::find('ZZZZ');
        if ($fakeGame === null) {
            throw new GameNotFoundException('ZZZZ');
        }
    }

    public function testCannotStartAlreadyStartedGame(): void
    {
        $createResult = GameService::createGame('Player One', [TEST_TAG_ID], []);
        $gameId = $createResult['game_id'];
        $playerId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        // Start once
        GameService::startGame($gameId, $playerId);

        // Try to start again
        $this->expectException(GameException::class);
        GameService::startGame($gameId, $playerId);
    }

    public function testInvalidGameSettingsAreHandled(): void
    {
        $invalidSettings = [
            'max_score' => -5, // Negative score
            'hand_size' => 100, // Unreasonably large
        ];

        $result = GameService::createGame('Player', [TEST_TAG_ID], $invalidSettings);

        // Settings should be sanitized/defaulted
        $game = Game::find($result['game_id']);
        $settings = $game['player_data']['settings'];

        // Max score should be positive
        $this->assertGreaterThan(0, $settings['max_score']);

        // Hand size should be reasonable
        $this->assertLessThanOrEqual(50, $settings['hand_size']);
    }
}
