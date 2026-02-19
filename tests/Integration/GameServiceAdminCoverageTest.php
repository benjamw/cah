<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Database\Database;
use CAH\Exceptions\UnauthorizedException;
use CAH\Exceptions\ValidationException;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Services\GameService;
use CAH\Services\RoundService;
use CAH\Tests\TestCase;

class GameServiceAdminCoverageTest extends TestCase
{
    /**
     * @param string $prefix
     * @param int $responseCount
     * @param int $promptCount
     * @return int tag_id
     */
    private function createTagWithCards(string $prefix, int $responseCount, int $promptCount): int
    {
        Database::execute("INSERT INTO tags (name, active) VALUES (?, 1)", ["{$prefix}_tag"]);
        $tagId = (int) Database::lastInsertId();

        for ($i = 0; $i < $responseCount; $i++) {
            Database::execute(
                "INSERT INTO cards (type, copy, active) VALUES ('response', ?, 1)",
                ["{$prefix}_response_{$i}"]
            );
            $cardId = (int) Database::lastInsertId();
            Database::execute("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)", [$cardId, $tagId]);
        }

        for ($i = 0; $i < $promptCount; $i++) {
            Database::execute(
                "INSERT INTO cards (type, copy, choices, active) VALUES ('prompt', ?, 1, 1)",
                ["{$prefix}_prompt_{$i}"]
            );
            $cardId = (int) Database::lastInsertId();
            Database::execute("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)", [$cardId, $tagId]);
        }

        return $tagId;
    }

    private function cleanupTagCards(string $prefix): void
    {
        Database::execute(
            "DELETE FROM cards_to_tags WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE ?)",
            ["{$prefix}_%"]
        );
        Database::execute("DELETE FROM cards WHERE copy LIKE ?", ["{$prefix}_%"]);
        Database::execute("DELETE FROM tags WHERE name = ?", ["{$prefix}_tag"]);
    }

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

    public function testPlayerCanPauseAndUnpauseThemselvesWithoutCreatorRole(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];

        $selfPlayerId = null;
        foreach ($setup['player_data']['players'] as $player) {
            if ($player['id'] !== $creatorId && empty($player['is_rando'])) {
                $selfPlayerId = $player['id'];
                break;
            }
        }

        $this->assertNotNull($selfPlayerId);

        $paused = GameService::togglePlayerPause($gameId, $selfPlayerId, $selfPlayerId);
        $pausedPlayer = GameService::findPlayer($paused, $selfPlayerId);
        $this->assertNotNull($pausedPlayer);
        $this->assertTrue((bool) ($pausedPlayer['is_paused'] ?? false));

        $unpaused = GameService::togglePlayerPause($gameId, $selfPlayerId, $selfPlayerId);
        $unpausedPlayer = GameService::findPlayer($unpaused, $selfPlayerId);
        $this->assertNotNull($unpausedPlayer);
        $this->assertFalse((bool) ($unpausedPlayer['is_paused'] ?? false));
    }

    public function testNonCreatorCannotPauseAnotherPlayer(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];

        $actorId = null;
        $targetId = null;
        foreach ($setup['player_data']['players'] as $player) {
            if ($player['id'] === $creatorId || ! empty($player['is_rando'])) {
                continue;
            }
            if ($actorId === null) {
                $actorId = $player['id'];
                continue;
            }
            $targetId = $player['id'];
            break;
        }

        $this->assertNotNull($actorId);
        $this->assertNotNull($targetId);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Only the game creator can pause players');
        GameService::togglePlayerPause($gameId, $actorId, $targetId);
    }

    public function testCreatorCanPauseAnotherPlayer(): void
    {
        $setup = $this->createStartedGameWithFourPlayers();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];

        $targetId = null;
        foreach ($setup['player_data']['players'] as $player) {
            if ($player['id'] !== $creatorId && empty($player['is_rando'])) {
                $targetId = $player['id'];
                break;
            }
        }

        $this->assertNotNull($targetId);

        $result = GameService::togglePlayerPause($gameId, $creatorId, $targetId);
        $targetPlayer = GameService::findPlayer($result, $targetId);
        $this->assertNotNull($targetPlayer);
        $this->assertTrue((bool) ($targetPlayer['is_paused'] ?? false));
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

    public function testTransferRemovingCzarHostResetsRoundAndResubmitsRando(): void
    {
        $create = GameService::createGame('Creator', [TEST_TAG_ID], ['rando_enabled' => true]);
        $gameId = $create['game_id'];
        $creatorId = $create['player_id'];

        $playerTwo = GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');
        $started = GameService::startGame($gameId, $creatorId);

        // Ensure the creator is the current czar before transfer/removal.
        if ($started['current_czar_id'] !== $creatorId) {
            GameService::setNextCzar($gameId, $started['current_czar_id'], $creatorId);
        }

        // Add a human submission so the round reset path is exercised.
        $preTransfer = Game::find($gameId)['player_data'];
        $submitterId = null;
        foreach ($preTransfer['players'] as $player) {
            if (
                $player['id'] !== $creatorId &&
                empty($player['is_rando']) &&
                ! empty($player['hand'])
            ) {
                $submitterId = $player['id'];
                RoundService::submitCards($gameId, $submitterId, [$player['hand'][0]]);
                break;
            }
        }

        $this->assertNotNull($submitterId);

        $beforeTransferState = Game::find($gameId)['player_data'];
        $oldPromptCard = $beforeTransferState['current_prompt_card'];
        $randoId = $beforeTransferState['rando_id'];
        $this->assertNotNull($randoId);

        $result = GameService::transferHost($gameId, $creatorId, $playerTwo['player_id'], true);

        $this->assertNull(GameService::findPlayer($result, $creatorId));
        $this->assertNotSame($creatorId, $result['current_czar_id']);
        $this->assertNotSame($oldPromptCard, $result['current_prompt_card']);

        $randoSubmission = null;
        foreach ($result['submissions'] as $submission) {
            if ($submission['player_id'] === $randoId) {
                $randoSubmission = $submission;
                break;
            }
        }

        $this->assertNotNull($randoSubmission, 'Rando should auto-submit after host/czar transfer removal');
        $expectedChoices = CardService::getPromptCardChoices($result['current_prompt_card']);
        $this->assertCount($expectedChoices, $randoSubmission['cards']);
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

    public function testCreateGameRejectsZeroPromptOrResponsePool(): void
    {
        $prefix = 'CoverageNoPrompt';
        $tagId = $this->createTagWithCards($prefix, 2, 0);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Cannot create game: selected cards include');
            GameService::createGame('Creator', [$tagId]);
        } finally {
            $this->cleanupTagCards($prefix);
        }
    }

    public function testPreviewCardPoolReturnsWarningAndAvailabilityFlags(): void
    {
        $prefix = 'CoverageLowPool';
        $tagId = $this->createTagWithCards($prefix, 10, 3);

        try {
            $preview = GameService::previewCardPool([$tagId]);

            $this->assertSame(10, $preview['response_cards']);
            $this->assertSame(3, $preview['prompt_cards']);
            $this->assertTrue($preview['has_required_cards']);
            $this->assertTrue($preview['low_card_pool']);
            $this->assertSame(200, $preview['warning_thresholds']['response_cards']);
            $this->assertSame(25, $preview['warning_thresholds']['prompt_cards']);
        } finally {
            $this->cleanupTagCards($prefix);
        }
    }
}
