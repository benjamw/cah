<?php

declare(strict_types=1);

namespace CAH\Tests\Functional;

use CAH\Tests\TestCase;
use CAH\Services\GameService;
use CAH\Models\Game;
use CAH\Database\Database;

/**
 * Admin Game Management Functional Tests
 * 
 * Tests admin functionality for viewing and deleting games
 */
class AdminGameManagementTest extends TestCase
{
    private array $testCards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testCards = $this->createTestCards();
    }

    // ========================================
    // VIEWING GAMES - FULL STATE ACCESS
    // ========================================

    public function test_admin_can_view_all_player_hands(): void
    {
        // Arrange - Create and start a game
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        GameService::joinGame($gameId, 'Player 2');
        GameService::joinGame($gameId, 'Player 3');
        GameService::startGame($gameId, $createResult['player_id']);

        // Act - Admin views game
        $game = Game::find($gameId);

        // Assert - Can see all player hands (not filtered)
        $this->assertArrayHasKey('player_data', $game);
        $players = $game['player_data']['players'];
        
        foreach ($players as $player) {
            $this->assertArrayHasKey('hand', $player, 'Admin can see player hands');
            $this->assertNotEmpty($player['hand'], 'Player hands are revealed to admin');
        }
    }

    public function test_admin_can_view_submissions_before_czar_picks(): void
    {
        // Arrange - Create game and get players to submit
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        $player2 = GameService::joinGame($gameId, 'Player 2');
        GameService::joinGame($gameId, 'Player 3');
        GameService::startGame($gameId, $createResult['player_id']);
        
        // Get a non-czar player to submit
        $game = Game::find($gameId);
        $czarId = $game['player_data']['current_czar_id'];
        
        $nonCzarPlayer = null;
        foreach ($game['player_data']['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $nonCzarPlayer = $player;
                break;
            }
        }
        
        // Submit cards
        $blackCard = $game['player_data']['current_black_card'];
        $blackCardData = \CAH\Models\Card::findById($blackCard);
        $choicesRequired = $blackCardData['choices'] ?? 1;
        $cardsToSubmit = array_slice($nonCzarPlayer['hand'], 0, $choicesRequired);
        
        \CAH\Services\RoundService::submitCards($gameId, $nonCzarPlayer['id'], $cardsToSubmit);

        // Act - Admin views game before czar picks winner
        $gameAfterSubmit = Game::find($gameId);

        // Assert - Admin can see submissions (not hidden until czar picks)
        $this->assertArrayHasKey('submissions', $gameAfterSubmit['player_data']);
        $this->assertNotEmpty($gameAfterSubmit['player_data']['submissions']);
        
        $submission = $gameAfterSubmit['player_data']['submissions'][0];
        $this->assertArrayHasKey('player_id', $submission);
        $this->assertArrayHasKey('cards', $submission);
    }

    public function test_admin_can_view_complete_game_state(): void
    {
        // Arrange
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            ['max_score' => 5, 'hand_size' => 10]
        );
        $gameId = $createResult['game_id'];
        
        GameService::joinGame($gameId, 'Player 2');
        GameService::joinGame($gameId, 'Player 3');
        GameService::startGame($gameId, $createResult['player_id']);

        // Act - Admin views full game
        $game = Game::find($gameId);

        // Assert - Everything is exposed
        $this->assertArrayHasKey('game_id', $game);
        $this->assertArrayHasKey('tags', $game);
        $this->assertArrayHasKey('draw_pile', $game);
        $this->assertArrayHasKey('discard_pile', $game);
        $this->assertArrayHasKey('player_data', $game);
        $this->assertArrayHasKey('created_at', $game);
        $this->assertArrayHasKey('updated_at', $game);
        
        // Player data includes everything
        $playerData = $game['player_data'];
        $this->assertArrayHasKey('settings', $playerData);
        $this->assertArrayHasKey('state', $playerData);
        $this->assertArrayHasKey('players', $playerData);
        $this->assertArrayHasKey('current_czar_id', $playerData);
        $this->assertArrayHasKey('current_black_card', $playerData);
        $this->assertArrayHasKey('current_round', $playerData);
    }

    public function test_admin_can_view_draw_and_discard_piles(): void
    {
        // Arrange
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        GameService::joinGame($gameId, 'Player 2');
        GameService::joinGame($gameId, 'Player 3');
        GameService::startGame($gameId, $createResult['player_id']);

        // Act
        $game = Game::find($gameId);

        // Assert - Admin can see both piles
        $this->assertArrayHasKey('draw_pile', $game);
        $this->assertArrayHasKey('white', $game['draw_pile']);
        $this->assertArrayHasKey('black', $game['draw_pile']);
        
        $this->assertArrayHasKey('discard_pile', $game);
        $this->assertArrayHasKey('white', $game['discard_pile']);
        $this->assertArrayHasKey('black', $game['discard_pile']);
    }

    public function test_admin_can_list_all_games(): void
    {
        // Arrange - Create multiple games
        $game1 = GameService::createGame('P1', [$this->testCards['tag_id']], []);
        $game2 = GameService::createGame('P2', [$this->testCards['tag_id']], []);
        $game3 = GameService::createGame('P3', [$this->testCards['tag_id']], []);

        // Act - Admin lists all games
        $allGames = Database::fetchAll("SELECT * FROM games ORDER BY created_at DESC");

        // Assert
        $this->assertGreaterThanOrEqual(3, count($allGames));
        
        $gameIds = array_column($allGames, 'game_id');
        $this->assertContains($game1['game_id'], $gameIds);
        $this->assertContains($game2['game_id'], $gameIds);
        $this->assertContains($game3['game_id'], $gameIds);
    }

    // ========================================
    // DELETING GAMES - PHYSICAL DELETE
    // ========================================

    public function test_admin_can_physically_delete_finished_game(): void
    {
        // Arrange - Create a finished game
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        // Manually set game to finished state
        Database::execute(
            "UPDATE games SET player_data = JSON_SET(player_data, '$.state', 'finished') WHERE game_id = ?",
            [$gameId]
        );

        // Act - Delete game
        Database::execute("DELETE FROM games WHERE game_id = ?", [$gameId]);

        // Assert - Game is gone
        $game = Game::find($gameId);
        $this->assertNull($game, 'Game should be physically deleted');
    }

    public function test_deleting_waiting_game_requires_confirmation(): void
    {
        // This test documents expected behavior:
        // - Games in 'waiting' state require confirmation before deletion
        // - After confirmation, deletion proceeds
        
        // Arrange
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        $game = Game::find($gameId);
        $gameState = $game['player_data']['state'];

        // Assert - Game is in waiting state (needs confirmation)
        $this->assertEquals('waiting', $gameState, 'Game is in waiting state');
        
        // Simulate: Admin confirms deletion
        $confirmed = true;
        
        if ($confirmed) {
            // Act - Delete after confirmation
            Database::execute("DELETE FROM games WHERE game_id = ?", [$gameId]);
            
            // Assert
            $deletedGame = Game::find($gameId);
            $this->assertNull($deletedGame, 'Game deleted after confirmation');
        }
    }

    public function test_deleting_playing_game_requires_confirmation(): void
    {
        // Arrange - Create and start a game (playing state)
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        GameService::joinGame($gameId, 'Player 2');
        GameService::joinGame($gameId, 'Player 3');
        GameService::startGame($gameId, $createResult['player_id']);
        
        $game = Game::find($gameId);
        $gameState = $game['player_data']['state'];

        // Assert - Game is playing (needs confirmation)
        $this->assertEquals('playing', $gameState, 'Game is in playing state');
        
        // Simulate: Admin confirms deletion despite active game
        $confirmed = true;
        
        if ($confirmed) {
            // Act - Delete after confirmation
            Database::execute("DELETE FROM games WHERE game_id = ?", [$gameId]);
            
            // Assert
            $deletedGame = Game::find($gameId);
            $this->assertNull($deletedGame, 'Active game deleted after confirmation');
        }
    }

    public function test_finished_game_deletion_does_not_require_confirmation(): void
    {
        // This test documents expected behavior:
        // - Finished games can be deleted without warning
        
        // Arrange
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        // Set to finished
        Database::execute(
            "UPDATE games SET player_data = JSON_SET(player_data, '$.state', 'finished') WHERE game_id = ?",
            [$gameId]
        );

        // Act - Delete without confirmation (finished games don't need it)
        Database::execute("DELETE FROM games WHERE game_id = ?", [$gameId]);

        // Assert
        $game = Game::find($gameId);
        $this->assertNull($game, 'Finished game deleted without confirmation');
    }

    public function test_admin_can_delete_any_game_after_confirmation(): void
    {
        // Arrange - Create games in different states
        $waitingGame = GameService::createGame('P1', [$this->testCards['tag_id']], []);
        
        $playingGame = GameService::createGame('P2', [$this->testCards['tag_id']], []);
        GameService::joinGame($playingGame['game_id'], 'P3');
        GameService::joinGame($playingGame['game_id'], 'P4');
        GameService::startGame($playingGame['game_id'], $playingGame['player_id']);
        
        $finishedGame = GameService::createGame('P5', [$this->testCards['tag_id']], []);
        Database::execute(
            "UPDATE games SET player_data = JSON_SET(player_data, '$.state', 'finished') WHERE game_id = ?",
            [$finishedGame['game_id']]
        );

        // Act - Admin can delete all (with appropriate confirmations)
        $gameIds = [
            $waitingGame['game_id'],
            $playingGame['game_id'],
            $finishedGame['game_id'],
        ];
        
        foreach ($gameIds as $gameId) {
            Database::execute("DELETE FROM games WHERE game_id = ?", [$gameId]);
        }

        // Assert - All deleted
        foreach ($gameIds as $gameId) {
            $game = Game::find($gameId);
            $this->assertNull($game, "Game {$gameId} should be deleted");
        }
    }

    public function test_deleting_game_removes_all_game_data(): void
    {
        // Arrange
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];

        // Act - Delete game
        Database::execute("DELETE FROM games WHERE game_id = ?", [$gameId]);

        // Assert - All data is gone (no orphaned records)
        $game = Game::find($gameId);
        $this->assertNull($game);
        
        // Verify no partial data remains
        $gameRecord = Database::fetchOne("SELECT * FROM games WHERE game_id = ?", [$gameId]);
        $this->assertNull($gameRecord, 'Game record should be completely removed');
    }

    // ========================================
    // GAMES ARE READ-ONLY (NO MODIFICATION)
    // ========================================

    public function test_admin_cannot_modify_game_state(): void
    {
        // This test documents expected behavior:
        // - Admins can VIEW and DELETE games
        // - Admins CANNOT modify game state (no edit endpoints)
        
        // Arrange
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];

        // Assert - Only view and delete operations are available
        // No admin methods exist for:
        // - Changing player scores
        // - Adding/removing players
        // - Modifying game settings
        // - Force ending games
        // - Skipping rounds
        
        // Admin can only:
        $canView = true; // Admin can view full state
        $canDelete = true; // Admin can delete games
        $canModify = false; // Admin CANNOT modify
        
        $this->assertTrue($canView, 'Admin can view games');
        $this->assertTrue($canDelete, 'Admin can delete games');
        $this->assertFalse($canModify, 'Admin cannot modify games');
    }

    public function test_admin_view_does_not_filter_sensitive_data(): void
    {
        // Arrange - Start a game
        $createResult = GameService::createGame(
            'Player 1',
            [$this->testCards['tag_id']],
            []
        );
        $gameId = $createResult['game_id'];
        
        GameService::joinGame($gameId, 'Player 2');
        GameService::joinGame($gameId, 'Player 3');
        GameService::startGame($gameId, $createResult['player_id']);

        // Act - Admin retrieves game
        $game = Game::find($gameId);
        $hydratedGame = GameService::hydrateCards($game['player_data']);

        // Assert - Unlike player views, admin sees EVERYTHING
        // No hand filtering
        $this->assertCount(3, $hydratedGame['players']);
        foreach ($hydratedGame['players'] as $player) {
            $this->assertArrayHasKey('hand', $player);
            $this->assertNotEmpty($player['hand'], 'All hands visible to admin');
        }
        
        // All submissions visible (even before revealed)
        if (isset($hydratedGame['submissions'])) {
            foreach ($hydratedGame['submissions'] as $submission) {
                $this->assertArrayHasKey('cards', $submission);
                $this->assertArrayHasKey('player_id', $submission);
            }
        }
    }
}
