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
    /**
     * List cards with filtering, pagination, and total count
     *
     * This method encapsulates the complex query logic for the admin card list
     *
     * @param string|null $cardType Filter by card type ('white' or 'black'), null for all
     * @param int|null $tagId Filter by tag ID, null for no tag filter
     * @param bool $noTags If true, only return cards with no tags
     * @param bool $active Filter by active status
     * @param int $limit Number of cards per page (0 for no limit)
     * @param int $offset Offset for pagination
     * @return array{cards: array, total: int} Array with 'cards' and 'total' count
     */
    public static function listWithFilters(
        ?string $cardType,
        ?int $tagId,
        bool $noTags,
        bool $active,
        int $limit,
        int $offset
    ): array {
        // Build main query
        $sql = "SELECT c.* FROM cards c";
        $params = [];
        $conditions = [];

        // Join with tags if filtering by tag
        if ($tagId !== null) {
            $sql .= " INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $conditions[] = "ct.tag_id = ?";
            $params[] = $tagId;
        } elseif ($noTags) {
            // Filter for cards with no tags using LEFT JOIN
            $sql .= " LEFT JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $conditions[] = "ct.card_id IS NULL";
        }

        // Add filters
        if ($cardType !== null) {
            $conditions[] = "c.card_type = ?";
            $params[] = $cardType;
        }

        $conditions[] = "c.active = ?";
        $params[] = $active;

        // Add WHERE clause (conditions array will always have at least one item)
        $sql .= " WHERE " . implode(' AND ', $conditions);

        // Add ordering and pagination
        $sql .= " ORDER BY c.card_id ASC";

        // Only add LIMIT if limit > 0 (0 means no limit)
        if ($limit > 0) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $cards = Database::fetchAll($sql, $params);

        // Build count query (same filters but no LIMIT/OFFSET)
        $countSql = "SELECT COUNT(DISTINCT c.card_id) as total FROM cards c";
        $countParams = [];
        $countConditions = [];

        if ($tagId !== null) {
            $countSql .= " INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $countConditions[] = "ct.tag_id = ?";
            $countParams[] = $tagId;
        } elseif ($noTags) {
            $countSql .= " LEFT JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $countConditions[] = "ct.card_id IS NULL";
        }

        if ($cardType !== null) {
            $countConditions[] = "c.card_type = ?";
            $countParams[] = $cardType;
        }

        $countConditions[] = "c.active = ?";
        $countParams[] = $active;

        // Add WHERE clause (countConditions array will always have at least one item)
        $countSql .= " WHERE " . implode(' AND ', $countConditions);

        $countResult = Database::fetchOne($countSql, $countParams);
        $total = (int) ( $countResult['total'] ?? 0 );

        return [
            'cards' => $cards,
            'total' => $total,
        ];
    }
}
