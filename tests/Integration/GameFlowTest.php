<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\GameService;
use CAH\Services\RoundService;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Enums\GameState;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\UnauthorizedException;

/**
 * Game Flow Integration Tests
 *
 * Tests the complete game flow from creation to completion
 */
class GameFlowTest extends TestCase
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
        if ($cards === []) {
            return [];
        }
        return is_array($cards[0]) ? array_column($cards, 'card_id') : $cards;
    }
    
    public function testCreateGameSuccessfully(): void
    {
        $result = GameService::createGame('Test Player', [TEST_TAG_ID], [
            'max_score' => 5,
            'hand_size' => 10,
        ]);

        $this->assertArrayHasKey('game_id', $result);
        $this->assertArrayHasKey('player_id', $result);
        $this->assertEquals(4, strlen($result['game_id']));

        // Verify game was created in database
        $game = Game::find($result['game_id']);
        $this->assertNotNull($game);
        $this->assertEquals(GameState::WAITING->value, $game['player_data']['state']);
        $this->assertCount(1, $game['player_data']['players']);
    }

    public function testCreateGameWithInvalidPlayerName(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Player name must be at least 3 characters');

        GameService::createGame('AB', [TEST_TAG_ID]);
    }

    public function testJoinGameSuccessfully(): void
    {
        // Create a game first
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];

        // Join the game
        $joinResult = GameService::joinGame($gameId, 'Player Two');

        $this->assertArrayHasKey('player_id', $joinResult);
        $this->assertArrayHasKey('game_state', $joinResult);
        $this->assertFalse($joinResult['game_started']);

        // Verify player was added
        $game = Game::find($gameId);
        $this->assertCount(2, $game['player_data']['players']);
    }

    public function testJoinNonExistentGame(): void
    {
        $this->expectException(GameNotFoundException::class);

        GameService::joinGame('XXXX', 'Test Player');
    }

    public function testStartGameSuccessfully(): void
    {
        // Create game and add players
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        // Start the game
        $result = GameService::startGame($gameId, $creatorId);

        $this->assertEquals(GameState::PLAYING->value, $result['state']);
        $this->assertNotNull($result['current_czar_id']);
        $this->assertNotNull($result['current_prompt_card']);
        $this->assertEquals(1, $result['current_round']);

        // Verify all players have cards in hand by fetching full game state from database
        // (startGame filters out other players' hands for security)
        $game = \CAH\Models\Game::find($gameId);
        foreach ($game['player_data']['players'] as $player) {
            if ($player['id'] !== $result['current_czar_id']) {
                $this->assertNotEmpty($player['hand'], "Player {$player['id']} should have cards in hand");
            }
        }
    }

    public function testStartGameWithInsufficientPlayers(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        // Only 1 player, need at least 3
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Need at least 3 players to start');

        GameService::startGame($gameId, $creatorId);
    }

    public function testStartGameByNonCreator(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];

        $joinResult = GameService::joinGame($gameId, 'Player Two');
        $nonCreatorId = $joinResult['player_id'];

        GameService::joinGame($gameId, 'Player Three');

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Only the game creator can start the game');

        GameService::startGame($gameId, $nonCreatorId);
    }

    public function testCompleteRoundFlow(): void
    {
        // Create and start a game
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $gameState = GameService::startGame($gameId, $creatorId);

        $czarId = $gameState['current_czar_id'];
        
        // Get full game state from database (startGame filters hands)
        $game = Game::find($gameId);
        $players = $game['player_data']['players'];

        // Get how many cards the prompt card requires
        $promptCardId = $this->getCardId($gameState['current_prompt_card']);
        $requiredCards = CardService::getPromptCardChoices($promptCardId);

        // Non-czar players submit cards
        foreach ($players as $player) {
            if ($player['id'] !== $czarId && ! empty($player['hand'])) {
                $cardsToSubmit = $this->getCardIds(array_slice($player['hand'], 0, $requiredCards));
                RoundService::submitCards($gameId, $player['id'], $cardsToSubmit);
            }
        }

        // Verify all submissions are recorded
        $game = Game::find($gameId);
        $this->assertCount(2, $game['player_data']['submissions']); // 2 non-czar players

        // Czar picks a winner
        $winningPlayerId = $game['player_data']['submissions'][0]['player_id'];
        $result = RoundService::pickWinner($gameId, $czarId, $winningPlayerId);

        // Verify winner got a point
        $winner = GameService::findPlayer($result, $winningPlayerId);
        $this->assertEquals(1, $winner['score']);

        // Verify round history was recorded (load with history)
        $gameAfterWin = Game::find($gameId, true);
        $this->assertNotNull($gameAfterWin['round_history']);
        $this->assertCount(1, $gameAfterWin['round_history']);
    }
}
