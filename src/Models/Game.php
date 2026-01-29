<?php

declare(strict_types=1);

namespace CAH\Models;

use CAH\Database\Database;
use CAH\Exceptions\JsonEncodingException;

/**
 * Game Model
 *
 * Handles database operations for games and JSON state management
 */
class Game
{
    /**
     * Safely encode data to JSON with error checking
     *
     * @param mixed $data Data to encode
     * @return string JSON string
     * @throws JsonEncodingException If encoding fails
     */
    private static function safeJsonEncode(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new JsonEncodingException('Failed to encode JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Find a game by ID
     *
     * @param string $gameId 4-character game code
     * @param bool $includeHistory Whether to include round_history (default: false for performance)
     * @return array<string, mixed>|null Game data with decoded JSON fields
     */
    public static function find(string $gameId, bool $includeHistory = false): ?array
    {
        // Only select round_history if explicitly requested
        $columns = $includeHistory
            ? '*'
            : 'game_id, tags, draw_pile, discard_pile, player_data, state, created_at, updated_at';

        $sql = "
            SELECT {$columns}
            FROM games
            WHERE game_id = ?
        ";
        $result = Database::fetchOne($sql, [$gameId]);

        if ( ! $result) {
            return null;
        }

        return self::decodeJsonFields($result);
    }

    /**
     * Create a new game
     *
     * @param string $gameId 4-character game code
     * @param array<int> $tags Array of tag IDs
     * @param array<string, array<int>> $drawPile Array of card IDs by type
     * @param array<string, mixed> $playerData Initial player data structure
     * @return bool Success
     */
    public static function create(string $gameId, array $tags, array $drawPile, array $playerData): bool
    {
        $sql = "
            INSERT INTO games (game_id, tags, draw_pile, discard_pile, player_data, round_history, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ";

        $params = [
            $gameId,
            self::safeJsonEncode($tags),
            self::safeJsonEncode($drawPile),
            self::safeJsonEncode([]), // Empty discard pile
            self::safeJsonEncode($playerData),
            self::safeJsonEncode([]), // Empty round history
        ];

        return Database::execute($sql, $params) > 0;
    }

    /**
     * Update game state
     *
     * @param string $gameId
     * @param array<string, mixed> $data Associative array of fields to update
     * @return int Number of affected rows
     */
    public static function update(string $gameId, array $data): int
    {
        $allowedFields = ['tags', 'draw_pile', 'discard_pile', 'player_data', 'round_history'];
        $fields = [];
        $values = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $fields[] = "{$field} = ?";
                $values[] = is_array($value) ? self::safeJsonEncode($value) : $value;
            }
        }

        if (empty($fields)) {
            return 0;
        }

        $values[] = $gameId;
        $sql = "
            UPDATE games
            SET " . implode(', ', $fields) . "
            WHERE game_id = ?
        ";

        return Database::execute($sql, $values);
    }

    /**
     * Delete a game
     *
     * @param string $gameId
     * @return int Number of affected rows
     */
    public static function delete(string $gameId): int
    {
        $sql = "
            DELETE
            FROM games
            WHERE game_id = ?
        ";
        return Database::execute($sql, [$gameId]);
    }

    /**
     * Get games older than specified days
     *
     * @param int $days Number of days
     * @return array<int, string> Array of game IDs
     */
    public static function getOlderThan(int $days): array
    {
        $sql = "
            SELECT game_id
            FROM games
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";

        return array_column(Database::fetchAll($sql, [$days]), 'game_id');
    }

    /**
     * Delete games older than specified days
     *
     * @param int $days Number of days
     * @return int Number of games deleted
     */
    public static function deleteOlderThan(int $days): int
    {
        $sql = "
            DELETE
            FROM games
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        return Database::execute($sql, [$days]);
    }

    /**
     * Check if a game exists
     *
     * @param string $gameId
     * @return bool
     */
    public static function exists(string $gameId): bool
    {
        $sql = "
            SELECT COUNT(*) AS `count`
            FROM games
            WHERE game_id = ?
        ";
        $result = Database::fetchOne($sql, [$gameId]);

        return $result && $result['count'] > 0;
    }

    /**
     * Update only the player_data field
     *
     * @param string $gameId
     * @param array<string, mixed> $playerData
     * @return int Number of affected rows
     */
    public static function updatePlayerData(string $gameId, array $playerData): int
    {
        $json = self::safeJsonEncode($playerData);

        // Monitor JSON size and log warning if large
        self::monitorJsonSize($gameId, 'player_data', $json);

        $sql = "
            UPDATE games
            SET player_data = ?
            WHERE game_id = ?
        ";
        return Database::execute($sql, [$json, $gameId]);
    }

    /**
     * Update draw and discard piles
     *
     * @param string $gameId
     * @param array<string, array<int>> $drawPile
     * @param array<int> $discardPile
     * @return int Number of affected rows
     */
    public static function updatePiles(string $gameId, array $drawPile, array $discardPile): int
    {
        $sql = "
            UPDATE games
            SET draw_pile = ?,
                discard_pile = ?
            WHERE game_id = ?
        ";
        return Database::execute($sql, [
            self::safeJsonEncode($drawPile),
            self::safeJsonEncode($discardPile),
            $gameId
        ]);
    }

    /**
     * Get the draw pile for a game
     *
     * @param string $gameId
     * @return array<string, array<int>>|null Array of card IDs or null if game not found
     */
    public static function getDrawPile(string $gameId): ?array
    {
        $sql = "
            SELECT draw_pile
            FROM games
            WHERE game_id = ?
        ";
        $result = Database::fetchOne($sql, [$gameId]);

        if ( ! $result) {
            return null;
        }

        return json_decode((string) $result['draw_pile'], true);
    }

    /**
     * Get the player data for a game
     *
     * @param string $gameId
     * @return array<string, mixed>|null Player data or null if game not found
     */
    public static function getPlayerData(string $gameId): ?array
    {
        $sql = "
            SELECT player_data
            FROM games
            WHERE game_id = ?
        ";
        $result = Database::fetchOne($sql, [$gameId]);

        if ( ! $result) {
            return null;
        }

        return json_decode((string) $result['player_data'], true);
    }

    /**
     * Get count of active games
     *
     * @return int
     */
    public static function getActiveCount(): int
    {
        $sql = "
            SELECT COUNT(*) AS `count`
            FROM games
        ";
        $result = Database::fetchOne($sql);

        return (int) ( $result['count'] ?? 0 );
    }

    /**
     * Decode JSON fields in game data
     *
     * @param array<string, mixed> $gameData Raw game data from database
     * @return array<string, mixed> Game data with decoded JSON fields
     */
    private static function decodeJsonFields(array $gameData): array
    {
        $jsonFields = ['tags', 'draw_pile', 'discard_pile', 'player_data', 'round_history'];

        foreach ($jsonFields as $field) {
            if (isset($gameData[$field])) {
                $gameData[$field] = json_decode($gameData[$field], true);
            }
        }

        return $gameData;
    }

    /**
     * Append a round to the round_history
     *
     * @param string $gameId
     * @param array<string, mixed> $roundData Round data to append
     * @return int Number of affected rows
     */
    public static function appendRoundHistory(string $gameId, array $roundData): int
    {
        $sql = "
            UPDATE games
            SET round_history = JSON_ARRAY_APPEND(
                COALESCE(round_history, JSON_ARRAY()),
                '$',
                ?
            )
            WHERE game_id = ?
        ";

        return Database::execute($sql, [
            self::safeJsonEncode($roundData),
            $gameId
        ]);
    }

    /**
     * Get round history for a game
     *
     * @param string $gameId
     * @return array<int, array<string, mixed>>|null Round history or null if game not found
     */
    public static function getRoundHistory(string $gameId): ?array
    {
        $sql = "
            SELECT round_history
            FROM games
            WHERE game_id = ?
        ";
        $result = Database::fetchOne($sql, [$gameId]);

        if ( ! $result) {
            return null;
        }

        return json_decode((string) $result['round_history'], true) ?? [];
    }

    /**
     * Monitor JSON size and log warning if large
     *
     * @param string $gameId
     * @param string $fieldName
     * @param string $json
     * @return void
     */
    private static function monitorJsonSize(string $gameId, string $fieldName, string $json): void
    {
        $size = strlen($json);
        $thresholdKB = 100; // 100KB threshold

        if ($size > ( $thresholdKB * 1024 )) {
            $sizeKB = round($size / 1024, 2);
            \CAH\Utils\Logger::warning("Large JSON detected in game {$fieldName}", [
                'game_id' => $gameId,
                'field' => $fieldName,
                'size_kb' => $sizeKB,
                'threshold_kb' => $thresholdKB,
            ]);
        }
    }
}
