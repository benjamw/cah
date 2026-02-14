<?php

declare(strict_types=1);

namespace CAH\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use CAH\Database\Database;
use CAH\Enums\GameState;

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
        } catch (\Exception) {
            // Ignore cleanup errors
        }
    }

    /**
     * Re-seed base test data (300 response cards, 70 prompt cards, test_base tag)
     * Used when tests delete all cards (like CSV import tests)
     */
    protected function reseedBaseTestData(): void
    {
        $connection = Database::getConnection();
        
        // Reset auto-increment for cards and tags
        $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
        $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");
        
        // Insert test response cards
        $stmt = $connection->prepare("INSERT INTO cards (type, copy) VALUES ('response', ?)");
        for ($i = 1; $i <= 300; $i++) {
            $stmt->execute([sprintf('White Card %03d', $i)]);
        }
        
        // Insert test prompt cards
        $stmt = $connection->prepare("INSERT INTO cards (type, copy, choices) VALUES ('prompt', ?, ?)");
        for ($i = 1; $i <= 40; $i++) {
            $stmt->execute([sprintf('Black Card %03d with ____.', $i), 1]);
        }
        for ($i = 41; $i <= 55; $i++) {
            $stmt->execute([sprintf('Black Card %03d with ____ and ____.', $i), 2]);
        }
        for ($i = 56; $i <= 70; $i++) {
            $stmt->execute([sprintf('Black Card %03d with ____, ____, and ____.', $i), 3]);
        }
        
        // Insert test tag
        $connection->exec("INSERT INTO tags (name) VALUES ('test_base')");
        $tagId = $connection->lastInsertId();
        
        // Tag all cards
        $totalCards = 370; // 300 response + 70 prompt
        $stmt = $connection->prepare("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)");
        for ($i = 1; $i <= $totalCards; $i++) {
            $stmt->execute([$i, $tagId]);
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
            'state' => GameState::WAITING->value,
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
            'current_prompt_card' => null,
            'current_round' => 0,
            'submissions' => [],
            'round_history' => [],
            'rando_id' => null,
        ], $overrides);

        $drawPile = [
            'response' => range(1, 100), // Mock response card IDs
            'prompt' => range(1001, 1050), // Mock prompt card IDs
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
