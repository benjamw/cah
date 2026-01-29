<?php

declare(strict_types=1);

namespace CAH\Models;

use CAH\Database\Database;

/**
 * Card Model
 *
 * Handles database operations for cards (response and prompt)
 * Note: Database uses 'response' and 'prompt' types
 *       UI uses 'white' and 'black' labels externally
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
     * Cards are excluded if they are ONLY in inactive packs (cards in at least one active pack are included)
     * Example: Player selects [1, 2]
     *   - Card with no tags -> Included (untagged cards always included)
     *   - Card with tags [1] -> Included (all card tags are in selection)
     *   - Card with tags [2] -> Included (all card tags are in selection)
     *   - Card with tags [1, 2] -> Included (all card tags are in selection)
     *   - Card with tags [1, 3] -> Excluded (tag 3 not in selection)
     *   - Card with tags [3] -> Excluded (tag 3 not in selection)
     *
     * @param string $cardType 'response' or 'prompt'
     * @param array $tagIds Array of tag IDs to filter by
     * @return array Array of card IDs
     */
    public static function getActiveCardsByTypeAndTags(string $cardType, array $tagIds): array
    {
        if (empty($tagIds)) {
            $sql = "
                SELECT DISTINCT c.card_id
                FROM cards c
                WHERE c.active = 1
                    AND c.type = ?
                    AND (
                        -- Include cards that have at least one active pack
                        EXISTS (
                            SELECT 1
                            FROM cards_to_packs cp
                            INNER JOIN packs p ON cp.pack_id = p.pack_id
                            WHERE cp.card_id = c.card_id
                                AND p.active = 1
                        )
                        -- OR include cards that have no packs at all
                        OR NOT EXISTS (
                            SELECT 1
                            FROM cards_to_packs cp
                            WHERE cp.card_id = c.card_id
                        )
                    )
            ";
            return array_column(Database::fetchAll($sql, [$cardType]), 'card_id');
        }

        // Get cards where ALL of the card's tags are in the selected tags list
        // Strategy: Find cards that have tags NOT in the selected list, then exclude them
        // Untagged cards are always included
        // Also exclude cards that are ONLY in inactive packs
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));

        $sql = "
            SELECT DISTINCT c.card_id
            FROM cards c
            WHERE c.active = 1
                AND c.type = ?
                AND c.card_id NOT IN (
                    -- Exclude cards that have any tag NOT in the selected list
                    SELECT DISTINCT ct.card_id
                    FROM cards_to_tags ct
                    WHERE ct.tag_id NOT IN ({$placeholders})
                )
                AND (
                    -- Include cards that have at least one active pack
                    EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        INNER JOIN packs p ON cp.pack_id = p.pack_id
                        WHERE cp.card_id = c.card_id
                            AND p.active = 1
                    )
                    -- OR include cards that have no packs at all
                    OR NOT EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        WHERE cp.card_id = c.card_id
                    )
                )
        ";

        $params = array_merge([$cardType], $tagIds);
        return array_column(Database::fetchAll($sql, $params), 'card_id');
    }

    /**
     * Get all active cards by type
     * Excludes cards that are ONLY in inactive packs
     *
     * @param string $cardType 'response' or 'prompt'
     * @return array Array of card data
     */
    public static function getActiveByType(string $cardType): array
    {
        $sql = "
            SELECT DISTINCT c.*
            FROM cards c
            WHERE c.active = 1
                AND c.type = ?
                AND (
                    -- Include cards that have at least one active pack
                    EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        INNER JOIN packs p ON cp.pack_id = p.pack_id
                        WHERE cp.card_id = c.card_id
                            AND p.active = 1
                    )
                    -- OR include cards that have no packs at all
                    OR NOT EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        WHERE cp.card_id = c.card_id
                    )
                )
        ";
        return Database::fetchAll($sql, [$cardType]);
    }

    /**
     * Create a new card
     *
     * @param string $cardType 'response' or 'prompt'
     * @param string $copy Card text (supports markdown)
     * @param int|null $choices Number of response cards needed (prompt cards only)
     * @param bool $active Whether card is active
     * @return int The new card ID
     */
    public static function create(string $cardType, string $copy, ?int $choices = null, bool $active = true): int
    {
        $sql = "
            INSERT INTO cards (type, copy, choices, active)
            VALUES (?, ?, ?, ?)
        ";

        Database::execute($sql, [$cardType, $copy, $choices, $active ? 1 : 0]);

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
        $allowedFields = ['type', 'copy', 'choices', 'active'];
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
     * @param string $cardType 'response' or 'prompt'
     * @return int
     */
    /**
     * List cards with filtering, pagination, and total count
     *
     * This method encapsulates the complex query logic for the admin card list
     *
     * @param string|null $cardType Filter by card type ('response' or 'prompt'), null for all
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
        ?int $excludeTagId,
        ?int $packId,
        bool $noPacks,
        ?bool $packActive,
        ?string $searchQuery,
        bool $active,
        int $limit,
        int $offset
    ): array {
        // Build main query with relevance scoring if search is present
        $selectFields = "c.*";
        $orderBy = "c.card_id ASC";
        $params = [];
        
        // Add relevance scoring for search query
        if ($searchQuery !== null) {
            // Split search query into words
            $searchWords = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY);
            $relevanceConditions = [];
            
            foreach ($searchWords as $word) {
                $relevanceConditions[] = "IF(c.copy LIKE ?, 1, 0)";
                // Add params for relevance scoring FIRST
                $params[] = '%' . $word . '%';
            }
            
            if (!empty($relevanceConditions)) {
                $selectFields = "c.*, (" . implode(" + ", $relevanceConditions) . ") as relevance";
                $orderBy = "relevance DESC, c.card_id ASC";
            }
        }
        
        $sql = "SELECT DISTINCT {$selectFields} FROM cards c";
        $conditions = [];
        $joins = [];

        // Join with tags if filtering by tag
        if ($tagId !== null) {
            $joins[] = "INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $conditions[] = "ct.tag_id = ?";
            $params[] = $tagId;
        } elseif ($noTags) {
            // Filter for cards with no tags using LEFT JOIN
            $joins[] = "LEFT JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $conditions[] = "ct.card_id IS NULL";
        } elseif ($excludeTagId !== null) {
            // Filter for cards that DON'T have this tag
            $joins[] = "LEFT JOIN cards_to_tags ct_exclude ON c.card_id = ct_exclude.card_id AND ct_exclude.tag_id = ?";
            $conditions[] = "ct_exclude.card_id IS NULL";
            $params[] = $excludeTagId;
        }

        // Join with packs if filtering by pack or pack status
        if ($packId !== null || $noPacks || $packActive !== null) {
            if ($packId !== null) {
                $joins[] = "INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id";
                $conditions[] = "cp.pack_id = ?";
                $params[] = $packId;
            } elseif ($noPacks) {
                // Filter for cards with no packs using LEFT JOIN
                $joins[] = "LEFT JOIN cards_to_packs cp ON c.card_id = cp.card_id";
                $conditions[] = "cp.card_id IS NULL";
            } elseif ($packActive !== null) {
                // Filter by pack active status
                $joins[] = "INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id";
                $joins[] = "INNER JOIN packs p ON cp.pack_id = p.pack_id";
                $conditions[] = "p.active = ?";
                $params[] = $packActive;
            }
        }

        // Add joins to SQL
        if (!empty($joins)) {
            $sql .= " " . implode(" ", $joins);
        }

        // Add filters
        if ($cardType !== null) {
            $conditions[] = "c.type = ?";
            $params[] = $cardType;
        }

        $conditions[] = "c.active = ?";
        $params[] = $active;
        
        // Add search conditions
        if ($searchQuery !== null) {
            $searchWords = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($searchWords)) {
                $searchConditions = [];
                foreach ($searchWords as $word) {
                    $searchConditions[] = "c.copy LIKE ?";
                    $params[] = '%' . $word . '%';
                }
                // Match if ANY search word is found
                $conditions[] = "(" . implode(" OR ", $searchConditions) . ")";
            }
        }

        // Add WHERE clause (conditions array will always have at least one item)
        $sql .= " WHERE " . implode(' AND ', $conditions);

        // Add ordering and pagination
        $sql .= " ORDER BY {$orderBy}";

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
        $countJoins = [];

        if ($tagId !== null) {
            $countJoins[] = "INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $countConditions[] = "ct.tag_id = ?";
            $countParams[] = $tagId;
        } elseif ($noTags) {
            $countJoins[] = "LEFT JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $countConditions[] = "ct.card_id IS NULL";
        } elseif ($excludeTagId !== null) {
            $countJoins[] = "LEFT JOIN cards_to_tags ct_exclude ON c.card_id = ct_exclude.card_id AND ct_exclude.tag_id = ?";
            $countConditions[] = "ct_exclude.card_id IS NULL";
            $countParams[] = $excludeTagId;
        }

        if ($packId !== null || $noPacks || $packActive !== null) {
            if ($packId !== null) {
                $countJoins[] = "INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id";
                $countConditions[] = "cp.pack_id = ?";
                $countParams[] = $packId;
            } elseif ($noPacks) {
                $countJoins[] = "LEFT JOIN cards_to_packs cp ON c.card_id = cp.card_id";
                $countConditions[] = "cp.card_id IS NULL";
            } elseif ($packActive !== null) {
                $countJoins[] = "INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id";
                $countJoins[] = "INNER JOIN packs p ON cp.pack_id = p.pack_id";
                $countConditions[] = "p.active = ?";
                $countParams[] = $packActive;
            }
        }

        if (!empty($countJoins)) {
            $countSql .= " " . implode(" ", $countJoins);
        }

        if ($cardType !== null) {
            $countConditions[] = "c.type = ?";
            $countParams[] = $cardType;
        }

        $countConditions[] = "c.active = ?";
        $countParams[] = $active;
        
        // Add search conditions to count query
        if ($searchQuery !== null) {
            $searchWords = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($searchWords)) {
                $searchConditions = [];
                foreach ($searchWords as $word) {
                    $searchConditions[] = "c.copy LIKE ?";
                    $countParams[] = '%' . $word . '%';
                }
                $countConditions[] = "(" . implode(" OR ", $searchConditions) . ")";
            }
        }

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
