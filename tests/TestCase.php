<?php

declare(strict_types=1);

namespace CAH\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use CAH\Database\Database;

/**
 * Base Test Case
 *
 * Provides common functionality for all tests including database setup/teardown
 */
abstract class TestCase extends BaseTestCase
{
    // Database is initialized in tests/bootstrap.php

    /**
     * Clean up database after each test
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupDatabase();
    }

    /**
     * Clean up test data from database
     */
    protected function cleanupDatabase(): void
    {
        try {
            // Clean up in reverse order of dependencies
            // Delete ALL games (test games have random 4-char IDs, not just TEST%)
            Database::execute('DELETE FROM games');
            Database::execute('DELETE FROM rate_limits WHERE ip_address LIKE "127.0.0.%"');
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Create a test game with minimal setup
     *
     * @param array $overrides Override default player data
     * @return array ['game_id' => string, 'player_data' => array]
     */
    protected function createTestGame(array $overrides = []): array
    {
        $gameId = 'TEST';
        $playerId = 'test-player-' . uniqid();

        $playerData = array_merge([
            'settings' => [
                'rando_enabled' => false,
                'unlimited_renew' => false,
                'max_score' => 8,
                'hand_size' => 10,
            ],
            'state' => 'waiting',
            'creator_id' => $playerId,
            'players' => [
                [
                    'id' => $playerId,
                    'name' => 'Test Player',
                    'score' => 0,
                    'hand' => [],
                    'connected' => true,
                    'is_creator' => true,
                ]
            ],
            'player_order' => [],
            'order_locked' => false,
            'current_czar_id' => null,
            'current_black_card' => null,
            'current_round' => 0,
            'submissions' => [],
            'round_history' => [],
            'rando_id' => null,
        ], $overrides);

        $drawPile = [
            'white' => range(1, 100), // Mock white card IDs
            'black' => range(1001, 1050), // Mock black card IDs
        ];

        \CAH\Models\Game::create($gameId, [], $drawPile, $playerData);

        return [
            'game_id' => $gameId,
            'player_data' => $playerData,
            'player_id' => $playerId,
        ];
    }

    /**
     * Assert that an exception is thrown with a specific message
     *
     * @param string $exceptionClass
     * @param callable $callback
     * @param string|null $expectedMessage
     */
    protected function assertThrowsException(string $exceptionClass, callable $callback, ?string $expectedMessage = null): void
    {
        try {
            $callback();
            $this->fail("Expected exception {$exceptionClass} was not thrown");
        } catch (\Exception $e) {
            $this->assertInstanceOf($exceptionClass, $e);
            if ($expectedMessage !== null) {
                $this->assertStringContainsString($expectedMessage, $e->getMessage());
            }
        }
    }
}
