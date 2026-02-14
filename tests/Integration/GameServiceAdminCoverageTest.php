<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Exceptions\ValidationException;
use CAH\Models\Game;
use CAH\Services\GameService;
use CAH\Tests\TestCase;

class GameServiceAdminCoverageTest extends TestCase
{
    /**
     * @return array{game_id: string, creator_id: string, player_ids: array<int, string>, player_data: array<string, mixed>}
     */
    private function createStartedGameWithFourPlayers(): array
    {
        $create = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $create['game_id'];
        $creatorId = $create['player_id'];

        $p2 = GameService::joinGame($gameId, 'Player Two');
        $p3 = GameService::joinGame($gameId, 'Player Three');
        $p4 = GameService::joinGame($gameId, 'Player Four');

        GameService::startGame($gameId, $creatorId);
        $game = Game::find($gameId);

        return [
            'game_id' => $gameId,
            'creator_id' => $creatorId,
            'player_ids' => [$creatorId, $p2['player_id'], $p3['player_id'], $p4['player_id']],
            'player_data' => $game['player_data'],
        ];
    }

    public function testForceEarlyReviewSetsFlagAndToast(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $czarId = $setup['player_data']['current_czar_id'];

        $result = GameService::forceEarlyReview($gameId, $czarId);

        $this->assertTrue($result['forced_early_review']);
        $this->assertNotEmpty($result['toasts']);
    }

    public function testForceEarlyReviewRejectsNonCzar(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $czarId = $setup['player_data']['current_czar_id'];

        $nonCzarId = null;
        foreach ($setup['player_data']['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $nonCzarId = $player['id'];
                break;
            }
        }

        $this->assertNotNull($nonCzarId);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only the czar can force early review');
        GameService::forceEarlyReview($gameId, $nonCzarId);
    }

    public function testRefreshPlayerHandReplacesCardsAndAddsToDiscardPile(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $czarId = $setup['player_data']['current_czar_id'];

        $targetPlayer = null;
        foreach ($setup['player_data']['players'] as $player) {
            if ($player['id'] !== $czarId && empty($player['is_rando'])) {
                $targetPlayer = $player;
                break;
            }
        }

        $this->assertNotNull($targetPlayer);
        $originalHand = $targetPlayer['hand'];

        $refreshed = GameService::refreshPlayerHand($gameId, $targetPlayer['id']);
        $updatedPlayer = GameService::findPlayer($refreshed, $targetPlayer['id']);

        $this->assertNotNull($updatedPlayer);
        $this->assertCount(count($originalHand), $updatedPlayer['hand']);
        $this->assertNotEquals($originalHand, $updatedPlayer['hand']);

        $game = Game::find($gameId);
        foreach ($originalHand as $cardId) {
            $this->assertContains($cardId, $game['discard_pile']);
        }
    }

    public function testTogglePauseOnCzarMovesToNextCzar(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];
        $currentCzarId = $setup['player_data']['current_czar_id'];

        $result = GameService::togglePlayerPause($gameId, $creatorId, $currentCzarId);

        $this->assertNotSame($currentCzarId, $result['current_czar_id']);
        $this->assertSame([], $result['submissions']);

        $czarPlayer = GameService::findPlayer($result, $currentCzarId);
        $this->assertNotNull($czarPlayer);
        $this->assertTrue((bool) ($czarPlayer['is_paused'] ?? false));
    }

    public function testVoteToSkipCzarSkipsAfterSecondVote(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $currentCzarId = $setup['player_data']['current_czar_id'];

        $voters = [];
        foreach ($setup['player_data']['players'] as $player) {
            if ($player['id'] !== $currentCzarId && empty($player['is_rando'])) {
                $voters[] = $player['id'];
            }
        }

        $firstVote = GameService::voteToSkipCzar($gameId, $voters[0]);
        $this->assertCount(1, $firstVote['skip_czar_votes']);
        $this->assertSame($currentCzarId, $firstVote['current_czar_id']);

        $secondVote = GameService::voteToSkipCzar($gameId, $voters[1]);
        $this->assertSame([], $secondVote['skip_czar_votes']);
        $this->assertNotSame($currentCzarId, $secondVote['current_czar_id']);
    }

    public function testVoteToSkipCzarToggleRemovesVote(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $currentCzarId = $setup['player_data']['current_czar_id'];

        $voterId = null;
        foreach ($setup['player_data']['players'] as $player) {
            if ($player['id'] !== $currentCzarId) {
                $voterId = $player['id'];
                break;
            }
        }

        $this->assertNotNull($voterId);
        $firstVote = GameService::voteToSkipCzar($gameId, $voterId);
        $this->assertCount(1, $firstVote['skip_czar_votes']);

        $secondVote = GameService::voteToSkipCzar($gameId, $voterId);
        $this->assertSame([], $secondVote['skip_czar_votes']);
        $this->assertSame($currentCzarId, $secondVote['current_czar_id']);
    }

    public function testTransferHostUpdatesCreatorAndIsCreatorFlags(): void
    {
        $create = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $create['game_id'];
        $creatorId = $create['player_id'];
        $playerTwo = GameService::joinGame($gameId, 'Player Two');

        $result = GameService::transferHost($gameId, $creatorId, $playerTwo['player_id'], false);

        $this->assertSame($playerTwo['player_id'], $result['creator_id']);
        $newHost = GameService::findPlayer($result, $playerTwo['player_id']);
        $oldHost = GameService::findPlayer($result, $creatorId);
        $this->assertTrue((bool) $newHost['is_creator']);
        $this->assertFalse((bool) $oldHost['is_creator']);
    }

    public function testTransferHostCanRemoveCurrentHost(): void
    {
        $create = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $create['game_id'];
        $creatorId = $create['player_id'];
        $playerTwo = GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $result = GameService::transferHost($gameId, $creatorId, $playerTwo['player_id'], true);

        $this->assertSame($playerTwo['player_id'], $result['creator_id']);
        $this->assertNull(GameService::findPlayer($result, $creatorId));
        $this->assertCount(2, $result['players']);
    }

    public function testTransferHostRejectsTransferToSelf(): void
    {
        $create = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $create['game_id'];
        $creatorId = $create['player_id'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot transfer host to yourself');
        GameService::transferHost($gameId, $creatorId, $creatorId, false);
    }
}
