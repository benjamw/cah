<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Services\GameService;
use CAH\Models\Game;
use CAH\Exceptions\UnauthorizedException;
use CAH\Exceptions\InvalidGameStateException;
use CAH\Exceptions\ValidationException;

/**
 * Reshuffle Integration Tests
 */
class ReshuffleTest extends TestCase
{
    public function testReshuffleDiscardPile(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        // Start the game
        GameService::startGame($gameId, $creatorId);

        // Manually add cards to discard pile
        $game = Game::find($gameId);
        $discardPile = [1, 2, 3, 4, 5];
        $originalDrawPileSize = count($game['draw_pile']['white']);

        Game::update($gameId, ['discard_pile' => $discardPile]);

        // Reshuffle
        $result = GameService::reshuffleDiscardPile($gameId, $creatorId);

        $this->assertTrue($result['success']);
        $this->assertEquals(5, $result['cards_reshuffled']);
        $this->assertEquals($originalDrawPileSize + 5, $result['new_draw_pile_size']);

        // Verify discard pile is empty
        $game = Game::find($gameId);
        $this->assertEmpty($game['discard_pile']);
        $this->assertCount($originalDrawPileSize + 5, $game['draw_pile']['white']);
    }

    public function testOnlyCreatorCanReshuffle(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];

        $player2 = GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $createResult['player_id']);

        // Add cards to discard pile
        Game::update($gameId, ['discard_pile' => [1, 2, 3]]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Only the game creator can reshuffle');

        GameService::reshuffleDiscardPile($gameId, $player2['player_id']);
    }

    public function testCannotReshuffleBeforeGameStarts(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $this->expectException(InvalidGameStateException::class);
        $this->expectExceptionMessage('Can only reshuffle during an active game');

        GameService::reshuffleDiscardPile($gameId, $creatorId);
    }

    public function testCannotReshuffleEmptyDiscardPile(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $creatorId);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Discard pile is empty');

        GameService::reshuffleDiscardPile($gameId, $creatorId);
    }

    public function testReshuffleAppendsToBottomOfDrawPile(): void
    {
        $createResult = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $createResult['game_id'];
        $creatorId = $createResult['player_id'];

        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        GameService::startGame($gameId, $creatorId);

        // Get current draw pile
        $game = Game::find($gameId);
        $originalDrawPile = $game['draw_pile']['white'];
        $topCards = array_slice($originalDrawPile, 0, 5);

        // Add cards to discard pile
        $discardPile = [1001, 1002, 1003];
        Game::update($gameId, ['discard_pile' => $discardPile]);

        // Reshuffle
        GameService::reshuffleDiscardPile($gameId, $creatorId);

        // Verify top cards of draw pile are unchanged
        $game = Game::find($gameId);
        $newTopCards = array_slice($game['draw_pile']['white'], 0, 5);

        $this->assertEquals($topCards, $newTopCards, 'Top cards should remain in same order');

        // Verify discard pile cards are now at the bottom
        $bottomCards = array_slice($game['draw_pile']['white'], -3);
        foreach ($discardPile as $cardId) {
            $this->assertContains($cardId, $bottomCards, 'Discard pile cards should be at bottom');
        }
    }
}
