<?php

declare(strict_types=1);

namespace CAH\Models;

use CAH\Database\Database;

/**
 * Pack Model
 *
 * Handles database operations for card packs
 */
class Pack
{
    /**
     * Get a pack by ID
     *
     * @param int $packId
     * @return array|null Pack data or null if not found
     */
    public static function find(int $packId): ?array
    {
        $sql = "
            SELECT *
            FROM packs
            WHERE pack_id = ?
        ";
        $result = Database::fetchOne($sql, [$packId]);

        return $result ?: null;
    }

    /**
     * Get multiple packs by IDs
     *
     * @param array $packIds Array of pack IDs
     * @return array Array of pack data
     */
    public static function findMany(array $packIds): array
    {
        if (empty($packIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($packIds), '?'));
        $sql = "
            SELECT *
            FROM packs
            WHERE pack_id IN ({$placeholders})
        ";

        return Database::fetchAll($sql, $packIds);
    }

    /**
     * Get all packs
     *
     * @return array Array of pack data
     */
    public static function getAll(): array
    {
        $sql = "
            SELECT *
            FROM packs
            ORDER BY name ASC, version ASC
        ";
        return Database::fetchAll($sql);
    }

    /**
     * Get all active packs
     *
     * @return array Array of pack data
     */
    public static function getAllActive(): array
    {
        $sql = "
            SELECT *
            FROM packs
            WHERE active = 1
            ORDER BY name ASC, version ASC
        ";
        return Database::fetchAll($sql);
    }

    /**
     * Get all packs with card counts
     * Only counts active cards
     *
     * @param bool|null $activeOnly If true, only active packs. If false, only inactive. If null, all packs.
     * @return array Array of pack data with card counts
     */
    public static function getAllWithCounts(?bool $activeOnly = null): array
    {
        $whereClause = '';
        $params = [];

        if ($activeOnly === true) {
            $whereClause = 'WHERE p.active = 1';
        } elseif ($activeOnly === false) {
            $whereClause = 'WHERE p.active = 0';
        }

        $sql = "
            SELECT
                p.*,
                COUNT(DISTINCT CASE WHEN c.type = 'response' AND c.active = 1 THEN c.card_id END) as response_card_count,
                COUNT(DISTINCT CASE WHEN c.type = 'prompt' AND c.active = 1 THEN c.card_id END) as prompt_card_count,
                COUNT(DISTINCT CASE WHEN c.active = 1 THEN c.card_id END) as total_card_count
            FROM packs p
            LEFT JOIN cards_to_packs cp ON p.pack_id = cp.pack_id
            LEFT JOIN cards c ON cp.card_id = c.card_id
            {$whereClause}
            GROUP BY p.pack_id
            ORDER BY p.name ASC, p.version ASC
        ";
        return Database::fetchAll($sql, $params);
    }

    /**
     * Get card count for a specific pack
     *
     * @param int $packId
     * @param string|null $cardType Optional: 'response', 'prompt', or null for all
     * @return int Number of cards in this pack
     */
    public static function getCardCount(int $packId, ?string $cardType = null): int
    {
        if ($cardType !== null) {
            $sql = "
                SELECT COUNT(DISTINCT c.card_id) as count
                FROM cards c
                INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id
                WHERE cp.pack_id = ? AND c.type = ?
            ";
            $result = Database::fetchOne($sql, [$packId, $cardType]);
        } else {
            $sql = "
                SELECT COUNT(DISTINCT c.card_id) as count
                FROM cards c
                INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id
                WHERE cp.pack_id = ?
            ";
            $result = Database::fetchOne($sql, [$packId]);
        }

        return (int) ( $result['count'] ?? 0 );
    }

    /**
     * Create a new pack
     *
     * @param string $name Pack name
     * @param string|null $version Pack version
     * @param string|null $data JSON metadata
     * @param string|null $releaseDate Release date (Y-m-d H:i:s format)
     * @param bool $active Whether pack is active
     * @return int The new pack ID
     */
    public static function create(
        string $name,
        ?string $version = null,
        ?string $data = null,
        ?string $releaseDate = null,
        bool $active = true
    ): int {
        $sql = "
            INSERT INTO packs (name, version, data, release_date, active)
            VALUES (?, ?, ?, ?, ?)
        ";
        Database::execute($sql, [$name, $version, $data, $releaseDate, $active ? 1 : 0]);

        return (int) Database::lastInsertId();
    }

    /**
     * Update a pack
     *
     * @param int $packId
     * @param array $data Associative array of fields to update
     * @return int Number of affected rows
     */
    public static function update(int $packId, array $data): int
    {
        $allowedFields = ['name', 'version', 'data', 'release_date', 'active'];
        $fields = [];
        $values = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $fields[] = "{$field} = ?";
                $values[] = $value;
            }
        }

        if (empty($fields)) {
            return 0;
        }

        $values[] = $packId;
        $sql = "
            UPDATE packs
            SET " . implode(', ', $fields) . "
            WHERE pack_id = ?
        ";

        return Database::execute($sql, $values);
    }

    /**
     * Toggle pack active status
     *
     * @param int $packId
     * @param bool $active
     * @return int Number of affected rows
     */
    public static function setActive(int $packId, bool $active): int
    {
        $sql = "
            UPDATE packs
            SET active = ?
            WHERE pack_id = ?
        ";
        return Database::execute($sql, [$active ? 1 : 0, $packId]);
    }

    /**
     * Delete a pack (hard delete)
     *
     * @param int $packId
     * @return int Number of affected rows
     */
    public static function delete(int $packId): int
    {
        $sql = "
            DELETE FROM packs
            WHERE pack_id = ?
        ";
        return Database::execute($sql, [$packId]);
    }

    /**
     * Add a pack to a card
     *
     * @param int $cardId
     * @param int $packId
     * @return bool True if added, false if already exists
     */
    public static function addToCard(int $cardId, int $packId): bool
    {
        try {
            $sql = "
                INSERT INTO cards_to_packs (card_id, pack_id)
                VALUES (?, ?)
            ";
            Database::execute($sql, [$cardId, $packId]);
            return true;
        } catch (\PDOException $e) {
            // Duplicate entry - pack already assigned to card
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Add multiple packs to a single card
     *
     * @param int $cardId
     * @param array $packIds Array of pack IDs
     * @return int Number of packs successfully added
     */
    public static function addMultiplePacksToCard(int $cardId, array $packIds): int
    {
        if (empty($packIds)) {
            return 0;
        }

        $added = 0;
        foreach ($packIds as $packId) {
            if (self::addToCard($cardId, $packId)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Add a single pack to multiple cards (bulk operation)
     *
     * @param array $cardIds Array of card IDs
     * @param int $packId
     * @return int Number of cards successfully added to pack
     */
    public static function addPackToMultipleCards(array $cardIds, int $packId): int
    {
        if (empty($cardIds)) {
            return 0;
        }

        // Build bulk insert query with ON DUPLICATE KEY UPDATE to ignore duplicates
        $values = [];
        $params = [];

        foreach ($cardIds as $cardId) {
            $values[] = "(?, ?)";
            $params[] = $cardId;
            $params[] = $packId;
        }

        $sql = "
            INSERT INTO cards_to_packs (card_id, pack_id)
            VALUES " . implode(', ', $values) . "
            ON DUPLICATE KEY UPDATE card_id = card_id
        ";

        Database::execute($sql, $params);

        // Return count of unique card-pack pairs that now exist
        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $countSql = "
            SELECT COUNT(*) as count
            FROM cards_to_packs
            WHERE pack_id = ? AND card_id IN ({$placeholders})
        ";

        $countParams = array_merge([$packId], $cardIds);
        $result = Database::fetchOne($countSql, $countParams);

        return (int) ( $result['count'] ?? 0 );
    }

    /**
     * Remove a pack from a card
     *
     * @param int $cardId
     * @param int $packId
     * @return int Number of affected rows
     */
    public static function removeFromCard(int $cardId, int $packId): int
    {
        $sql = "
            DELETE
            FROM cards_to_packs
            WHERE card_id = ?
                AND pack_id = ?
        ";
        return Database::execute($sql, [$cardId, $packId]);
    }

    /**
     * Get all packs for a card
     *
     * @param int $cardId
     * @return array Array of pack data
     */
    public static function getCardPacks(int $cardId): array
    {
        $sql = "
            SELECT p.*
            FROM packs p
            INNER JOIN cards_to_packs cp ON p.pack_id = cp.pack_id
            WHERE cp.card_id = ?
            ORDER BY p.name ASC, p.version ASC
        ";

        return Database::fetchAll($sql, [$cardId]);
    }

    /**
     * Get packs for multiple cards (batch fetch to avoid N+1 queries)
     *
     * @param array<int> $cardIds Array of card IDs
     * @return array<int, array> Associative array mapping card_id => array of packs
     */
    public static function getCardPacksForMultipleCards(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $sql = "
            SELECT cp.card_id, p.*
            FROM packs p
            INNER JOIN cards_to_packs cp ON p.pack_id = cp.pack_id
            WHERE cp.card_id IN ({$placeholders})
            ORDER BY cp.card_id, p.name ASC, p.version ASC
        ";

        $results = Database::fetchAll($sql, $cardIds);

        // Group packs by card_id
        $packsByCardId = [];
        foreach ($results as $row) {
            $cardId = (int) $row['card_id'];
            if ( ! isset($packsByCardId[$cardId])) {
                $packsByCardId[$cardId] = [];
            }
            // Remove card_id from the pack data
            unset($row['card_id']);
            $packsByCardId[$cardId][] = $row;
        }

        // Ensure all requested card IDs have an entry (even if empty)
        foreach ($cardIds as $cardId) {
            if ( ! isset($packsByCardId[$cardId])) {
                $packsByCardId[$cardId] = [];
            }
        }

        return $packsByCardId;
    }
}
