<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\GameService;
use CAH\Services\RoundService;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Enums\GameEndReason;
use CAH\Exceptions\InsufficientCardsException;
use CAH\Exceptions\ValidationException;

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
        $firstBlackCard = $gameState['current_black_card'];

        // Get how many cards the black card requires
        $blackCardId = $this->getCardId($gameState['current_black_card']);
        $requiredCards = CardService::getBlackCardChoices($blackCardId);

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
        $this->assertNotEquals($firstBlackCard, $result['current_black_card']);
        $this->assertEmpty($result['submissions']);

        // Verify players' hands were refilled (may have bonus cards if new black card requires 3+ choices)
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

        $this->assertEquals('finished', $result['state']);
        $this->assertEquals($winnerId, $result['winner_id']);
        $this->assertArrayHasKey('finished_at', $result);
        $this->assertEquals(GameEndReason::MAX_SCORE_REACHED->value, $result['end_reason'] ?? null);
    }

    public function testInsufficientWhiteCardsThrowsException(): void
    {
        $pile = [1, 2, 3];

        $this->expectException(InsufficientCardsException::class);

        CardService::drawWhiteCards($pile, 10);
    }

    public function testInsufficientBlackCardsThrowsException(): void
    {
        $pile = [];

        $this->expectException(InsufficientCardsException::class);

        CardService::drawBlackCard($pile);
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

    public function testDeleteGame(): void
    {
        // deleteGame() method doesn't exist in GameService
        // Games are cleaned up by the bootstrap after tests
        $this->markTestSkipped('deleteGame() method not implemented');
    }
}
