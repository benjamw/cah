<?php

declare(strict_types=1);

namespace CAH\Models;

use CAH\Database\Database;
use CAH\Enums\CardType;

/**
 * Tag Model
 *
 * Handles database operations for card tags/categories
 */
class Tag
{
    /**
     * Get a tag by ID
     *
     * @param int $tagId
     * @return array<string, mixed>|null Tag data or null if not found
     */
    public static function find(int $tagId): ?array
    {
        $sql = "
            SELECT *
            FROM tags
            WHERE tag_id = ?
        ";
        $result = Database::fetchOne($sql, [$tagId]);

        return $result ?: null;
    }

    /**
     * Get multiple tags by IDs
     *
     * @param array<int> $tagIds Array of tag IDs
     * @return array<int, array<string, mixed>> Array of tag data
     */
    public static function findMany(array $tagIds): array
    {
        if (empty($tagIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $sql = "
            SELECT *
            FROM tags
            WHERE tag_id IN ({$placeholders})
        ";

        return Database::fetchAll($sql, $tagIds);
    }

    /**
     * Get all active tags
     *
     * @return array<int, array<string, mixed>> Array of tag data
     */
    public static function getAllActive(): array
    {
        $sql = "
            SELECT *
            FROM tags
            WHERE active = 1
            ORDER BY name ASC
        ";
        return Database::fetchAll($sql);
    }

    /**
     * Get all active tags with card counts
     * Only counts cards that are in at least one active pack (or have no packs)
     *
     * @return array<int, array<string, mixed>> Array of tag data with card counts
     */
    public static function getAllActiveWithCounts(): array
    {
        $responseType = CardType::RESPONSE->value;
        $promptType = CardType::PROMPT->value;

        $sql = "
            SELECT
                t.*,
                COUNT(DISTINCT CASE
                    WHEN c.type = '{$responseType}' AND c.active = 1
                        AND (
                            EXISTS (
                                SELECT 1
                                FROM cards_to_packs cp
                                INNER JOIN packs p ON cp.pack_id = p.pack_id
                                WHERE cp.card_id = c.card_id AND p.active = 1
                            )
                            OR NOT EXISTS (
                                SELECT 1
                                FROM cards_to_packs cp
                                WHERE cp.card_id = c.card_id
                            )
                        )
                    THEN c.card_id
                END) as response_card_count,
                COUNT(DISTINCT CASE
                    WHEN c.type = '{$promptType}' AND c.active = 1
                        AND (
                            EXISTS (
                                SELECT 1
                                FROM cards_to_packs cp
                                INNER JOIN packs p ON cp.pack_id = p.pack_id
                                WHERE cp.card_id = c.card_id AND p.active = 1
                            )
                            OR NOT EXISTS (
                                SELECT 1
                                FROM cards_to_packs cp
                                WHERE cp.card_id = c.card_id
                            )
                        )
                    THEN c.card_id
                END) as prompt_card_count,
                COUNT(DISTINCT CASE
                    WHEN c.active = 1
                        AND (
                            EXISTS (
                                SELECT 1
                                FROM cards_to_packs cp
                                INNER JOIN packs p ON cp.pack_id = p.pack_id
                                WHERE cp.card_id = c.card_id AND p.active = 1
                            )
                            OR NOT EXISTS (
                                SELECT 1
                                FROM cards_to_packs cp
                                WHERE cp.card_id = c.card_id
                            )
                        )
                    THEN c.card_id
                END) as total_card_count
            FROM tags t
            LEFT JOIN cards_to_tags ct ON t.tag_id = ct.tag_id
            LEFT JOIN cards c ON ct.card_id = c.card_id
            WHERE t.active = 1
            GROUP BY t.tag_id
            ORDER BY t.name ASC
        ";
        return Database::fetchAll($sql);
    }

    /**
     * Get card count for a specific tag
     * Only counts cards that are in at least one active pack (or have no packs)
     *
     * @param int $tagId
     * @param CardType|null $cardType Optional card type enum, null for all
     * @return int Number of active cards with this tag
     */
    public static function getCardCount(int $tagId, ?CardType $cardType = null): int
    {
        if ($cardType !== null) {
            $sql = "
                SELECT COUNT(DISTINCT c.card_id) as count
                FROM cards c
                INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id
                WHERE ct.tag_id = ?
                    AND c.active = 1
                    AND c.type = ?
                    AND (
                        -- Include cards that have at least one active pack
                        EXISTS (
                            SELECT 1
                            FROM cards_to_packs cp
                            INNER JOIN packs p ON cp.pack_id = p.pack_id
                            WHERE cp.card_id = c.card_id AND p.active = 1
                        )
                        -- OR include cards that have no packs at all
                        OR NOT EXISTS (
                            SELECT 1
                            FROM cards_to_packs cp
                            WHERE cp.card_id = c.card_id
                        )
                    )
            ";
            $result = Database::fetchOne($sql, [$tagId, $cardType->value]);
        } else {
            $sql = "
                SELECT COUNT(DISTINCT c.card_id) as count
                FROM cards c
                INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id
                WHERE ct.tag_id = ?
                    AND c.active = 1
                    AND (
                        -- Include cards that have at least one active pack
                        EXISTS (
                            SELECT 1
                            FROM cards_to_packs cp
                            INNER JOIN packs p ON cp.pack_id = p.pack_id
                            WHERE cp.card_id = c.card_id AND p.active = 1
                        )
                        -- OR include cards that have no packs at all
                        OR NOT EXISTS (
                            SELECT 1
                            FROM cards_to_packs cp
                            WHERE cp.card_id = c.card_id
                        )
                    )
            ";
            $result = Database::fetchOne($sql, [$tagId]);
        }

        return (int) ( $result['count'] ?? 0 );
    }

    /**
     * Get all tags (including inactive)
     *
     * @return array<int, array<string, mixed>> Array of tag data
     */
    public static function getAll(): array
    {
        $sql = "
            SELECT *
            FROM tags
            ORDER BY name ASC
        ";
        return Database::fetchAll($sql);
    }

    /**
     * Create a new tag
     *
     * @param string $name Tag name
     * @param string|null $description Tag description
     * @param bool $active Whether tag is active
     * @return int The new tag ID
     */
    public static function create(string $name, ?string $description = null, bool $active = true): int
    {
        $sql = "
            INSERT INTO tags (name, description, active)
            VALUES (?, ?, ?)
        ";
        Database::execute($sql, [$name, $description, $active ? 1 : 0]);

        return (int) Database::lastInsertId();
    }

    /**
     * Update a tag
     *
     * @param int $tagId
     * @param array<string, mixed> $data Associative array of fields to update
     * @return int Number of affected rows
     */
    public static function update(int $tagId, array $data): int
    {
        $allowedFields = ['name', 'description', 'active'];
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

        $values[] = $tagId;
        $sql = "
            UPDATE tags
            SET " . implode(', ', $fields) . "
            WHERE tag_id = ?
        ";

        return Database::execute($sql, $values);
    }

    /**
     * Delete a tag (soft delete by setting active = 0)
     *
     * @param int $tagId
     * @return int Number of affected rows
     */
    public static function softDelete(int $tagId): int
    {
        $sql = "
            UPDATE tags
            SET active = 0
            WHERE tag_id = ?
        ";
        return Database::execute($sql, [$tagId]);
    }

    /**
     * Add a tag to a card
     *
     * @param int $cardId
     * @param int $tagId
     * @return bool True if added, false if already exists
     */
    public static function addToCard(int $cardId, int $tagId): bool
    {
        try {
            $sql = "
                INSERT INTO cards_to_tags (card_id, tag_id)
                VALUES (?, ?)
            ";
            Database::execute($sql, [$cardId, $tagId]);
            return true;
        } catch (\PDOException $e) {
            // Duplicate entry - tag already assigned to card
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Add multiple tags to a single card
     *
     * @param int $cardId
     * @param array<int> $tagIds Array of tag IDs
     * @return int Number of tags successfully added
     */
    public static function addMultipleTagsToCard(int $cardId, array $tagIds): int
    {
        if (empty($tagIds)) {
            return 0;
        }

        $added = 0;
        foreach ($tagIds as $tagId) {
            if (self::addToCard($cardId, $tagId)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Add a single tag to multiple cards (bulk operation)
     *
     * @param array<int> $cardIds Array of card IDs
     * @param int $tagId
     * @return int Number of cards successfully tagged
     */
    public static function addTagToMultipleCards(array $cardIds, int $tagId): int
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
            $params[] = $tagId;
        }

        $sql = "
            INSERT INTO cards_to_tags (card_id, tag_id)
            VALUES " . implode(', ', $values) . "
            ON DUPLICATE KEY UPDATE card_id = card_id
        ";

        Database::execute($sql, $params);

        // Return count of unique card-tag pairs that now exist
        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $countSql = "
            SELECT COUNT(*) as count
            FROM cards_to_tags
            WHERE tag_id = ? AND card_id IN ({$placeholders})
        ";

        $countParams = array_merge([$tagId], $cardIds);
        $result = Database::fetchOne($countSql, $countParams);

        return (int) ( $result['count'] ?? 0 );
    }

    /**
     * Remove a tag from a card
     *
     * @param int $cardId
     * @param int $tagId
     * @return int Number of affected rows
     */
    public static function removeFromCard(int $cardId, int $tagId): int
    {
        $sql = "
            DELETE
            FROM cards_to_tags
            WHERE card_id = ?
                AND tag_id = ?
        ";
        return Database::execute($sql, [$cardId, $tagId]);
    }

    /**
     * Get all tags for a card
     *
     * @param int $cardId
     * @return array<int, array<string, mixed>> Array of tag data
     */
    public static function getCardTags(int $cardId): array
    {
        $sql = "
            SELECT t.*
            FROM tags t
            INNER JOIN cards_to_tags ct ON t.tag_id = ct.tag_id
            WHERE ct.card_id = ?
            ORDER BY t.name ASC
        ";

        return Database::fetchAll($sql, [$cardId]);
    }

    /**
     * Get tags for multiple cards (batch fetch to avoid N+1 queries)
     *
     * @param array<int> $cardIds Array of card IDs
     * @return array<int, array<int, array<string, mixed>>> Associative array mapping card_id => array of tags
     */
    public static function getCardTagsForMultipleCards(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $sql = "
            SELECT ct.card_id, t.*
            FROM tags t
            INNER JOIN cards_to_tags ct ON t.tag_id = ct.tag_id
            WHERE ct.card_id IN ({$placeholders})
            ORDER BY ct.card_id, t.name ASC
        ";

        $results = Database::fetchAll($sql, $cardIds);

        // Group tags by card_id
        $tagsByCardId = [];
        foreach ($results as $row) {
            $cardId = (int) $row['card_id'];
            if ( ! isset($tagsByCardId[$cardId])) {
                $tagsByCardId[$cardId] = [];
            }
            // Remove card_id from the tag data
            unset($row['card_id']);
            $tagsByCardId[$cardId][] = $row;
        }

        // Ensure all requested card IDs have an entry (even if empty)
        foreach ($cardIds as $cardId) {
            if ( ! isset($tagsByCardId[$cardId])) {
                $tagsByCardId[$cardId] = [];
            }
        }

        return $tagsByCardId;
    }
}
