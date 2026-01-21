<?php

declare(strict_types=1);

namespace CAH\Models;

use CAH\Database\Database;

/**
 * Card Model
 *
 * Handles database operations for cards (white and black)
 */
class Card
{
    /**
     * Get a card by ID
     *
     * @param int $cardId
     * @return array|null Card data or null if not found
     */
    public static function getById(int $cardId): ?array
    {
        $sql = "
            SELECT *
            FROM cards
            WHERE card_id = ?
        ";
        $result = Database::fetchOne($sql, [$cardId]);

        return $result ?: null;
    }

    /**
     * Get multiple cards by IDs
     *
     * @param array $cardIds Array of card IDs
     * @return array Array of card data
     */
    public static function getByIds(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $sql = "
            SELECT *
            FROM cards
            WHERE card_id IN ({$placeholders})
        ";

        return Database::fetchAll($sql, $cardIds);
    }

    /**
     * Get active cards by type and tags
     *
     * A card is included only if ALL of the card's tags are in the selected tags list
     * Untagged cards are always included
     * Example: Player selects [1, 2]
     *   - Card with no tags -> Included (untagged cards always included)
     *   - Card with tags [1] -> Included (all card tags are in selection)
     *   - Card with tags [2] -> Included (all card tags are in selection)
     *   - Card with tags [1, 2] -> Included (all card tags are in selection)
     *   - Card with tags [1, 3] -> Excluded (tag 3 not in selection)
     *   - Card with tags [3] -> Excluded (tag 3 not in selection)
     *
     * @param string $cardType 'white' or 'black'
     * @param array $tagIds Array of tag IDs to filter by
     * @return array Array of card IDs
     */
    public static function getActiveCardsByTypeAndTags(string $cardType, array $tagIds): array
    {
        if (empty($tagIds)) {
            $sql = "
                SELECT card_id
                FROM cards
                WHERE active = 1
                    AND card_type = ?
            ";
            return array_column(Database::fetchAll($sql, [$cardType]), 'card_id');
        }

        // Get cards where ALL of the card's tags are in the selected tags list
        // Strategy: Find cards that have tags NOT in the selected list, then exclude them
        // Untagged cards are always included
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));

        $sql = "
            SELECT DISTINCT c.card_id
            FROM cards c
            WHERE c.active = 1
                AND c.card_type = ?
                AND c.card_id NOT IN (
                    -- Exclude cards that have any tag NOT in the selected list
                    SELECT DISTINCT ct.card_id
                    FROM cards_to_tags ct
                    WHERE ct.tag_id NOT IN ({$placeholders})
                )
        ";

        $params = array_merge([$cardType], $tagIds);
        return array_column(Database::fetchAll($sql, $params), 'card_id');
    }

    /**
     * Get all active cards by type
     *
     * @param string $cardType 'white' or 'black'
     * @return array Array of card data
     */
    public static function getActiveByType(string $cardType): array
    {
        $sql = "
            SELECT *
            FROM cards
            WHERE active = 1
                AND card_type = ?
        ";
        return Database::fetchAll($sql, [$cardType]);
    }

    /**
     * Create a new card
     *
     * @param string $cardType 'white' or 'black'
     * @param string $value Card text (supports markdown)
     * @param int|null $choices Number of white cards needed (black cards only)
     * @param bool $active Whether card is active
     * @return int The new card ID
     */
    public static function create(string $cardType, string $value, ?int $choices = null, bool $active = true): int
    {
        $sql = "
            INSERT INTO cards (card_type, value, choices, active)
            VALUES (?, ?, ?, ?)
        ";

        Database::execute($sql, [$cardType, $value, $choices, $active ? 1 : 0]);

        return (int) Database::lastInsertId();
    }

    /**
     * Update a card
     *
     * @param int $cardId
     * @param array $data Associative array of fields to update
     * @return int Number of affected rows
     */
    public static function update(int $cardId, array $data): int
    {
        $allowedFields = ['card_type', 'value', 'choices', 'active'];
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

        $values[] = $cardId;
        $sql = "
            UPDATE cards
            SET " . implode(', ', $fields) . "
            WHERE card_id = ?
        ";

        return Database::execute($sql, $values);
    }

    /**
     * Delete a card (soft delete by setting active = 0)
     *
     * @param int $cardId
     * @return int Number of affected rows
     */
    public static function softDelete(int $cardId): int
    {
        $sql = "
            UPDATE cards
            SET active = 0
            WHERE card_id = ?
        ";
        return Database::execute($sql, [$cardId]);
    }

    /**
     * Get total count of active cards by type
     *
     * @param string $cardType 'white' or 'black'
     * @return int
     */
    public static function countActiveByType(string $cardType): int
    {
        $sql = "
            SELECT COUNT(*) AS `count`
            FROM cards
            WHERE active = 1
                AND card_type = ?
        ";
        $result = Database::fetchOne($sql, [$cardType]);

        return (int) ($result['count'] ?? 0);
    }
}
