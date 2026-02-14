<?php

declare(strict_types=1);

namespace CAH\Models;

use CAH\Database\Database;
use CAH\Enums\CardType;

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
     * @return array<string, mixed>|null Card data or null if not found
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
     * @param array<int> $cardIds Array of card IDs
     * @return array<int, array<string, mixed>> Array of card data
     */
    public static function getByIds(array $cardIds): array
    {
        if ($cardIds === []) {
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
     * @param CardType $cardType Card type enum
     * @param array<int> $tagIds Array of tag IDs to filter by
     * @return array<int> Array of card IDs
     */
    public static function getActiveCardsByTypeAndTags(CardType $cardType, array $tagIds): array
    {
        if ($tagIds === []) {
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
            return array_column(Database::fetchAll($sql, [$cardType->value]), 'card_id');
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

        $params = array_merge([$cardType->value], $tagIds);
        return array_column(Database::fetchAll($sql, $params), 'card_id');
    }

    /**
     * Get all active cards by type
     * Excludes cards that are ONLY in inactive packs
     *
     * @param CardType $cardType Card type enum
     * @return array<int, array<string, mixed>> Array of card data
     */
    public static function getActiveByType(CardType $cardType): array
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
        return Database::fetchAll($sql, [$cardType->value]);
    }

    /**
     * Create a new card
     *
     * @param CardType $cardType Card type enum
     * @param string $copy Card text (supports markdown)
     * @param int|null $choices Number of response cards needed (prompt cards only)
     * @param bool $active Whether card is active
     * @param string|null $notes Optional notes (e.g. explanation, footnotes from import)
     * @param string|null $special Optional special (e.g. "Pick 2", "Draw 2, Pick 3")
     * @return int The new card ID
     */
    public static function create(
        CardType $cardType,
        string $copy,
        ?int $choices = null,
        bool $active = true,
        ?string $notes = null,
        ?string $special = null
    ): int {
        $sql = "
            INSERT INTO cards (type, copy, choices, active, notes, special)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        Database::execute($sql, [$cardType->value, $copy, $choices, $active ? 1 : 0, $notes, $special]);

        return (int) Database::lastInsertId();
    }

    /**
     * Update a card
     *
     * @param array<string, mixed> $data Associative array of fields to update
     * @return int Number of affected rows
     */
    public static function update(int $cardId, array $data): int
    {
        $allowedFields = ['type', 'copy', 'choices', 'active', 'notes', 'special'];
        $fields = [];
        $values = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $fields[] = "{$field} = ?";
                $values[] = $value;
            }
        }

        if ($fields === []) {
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
     * Build tag-related joins and conditions for card queries
     *
     * @param ?int $tagId Tag ID to filter by
     * @param bool $noTags Whether to filter for cards with no tags
     * @param ?int $excludeTagId Tag ID to exclude
     * @param array<mixed> &$params Parameters array to append to
     * @return array{joins: array<string>, conditions: array<string>}
     */
    private static function buildTagFilters(
        ?int $tagId,
        bool $noTags,
        ?int $excludeTagId,
        array &$params
    ): array {
        $joins = [];
        $conditions = [];

        if ($tagId !== null) {
            $joins[] = "INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $conditions[] = "ct.tag_id = ?";
            $params[] = $tagId;
        } elseif ($noTags) {
            $joins[] = "LEFT JOIN cards_to_tags ct ON c.card_id = ct.card_id";
            $conditions[] = "ct.card_id IS NULL";
        } elseif ($excludeTagId !== null) {
            $joins[] = "LEFT JOIN cards_to_tags ct_exclude " .
                "ON c.card_id = ct_exclude.card_id AND ct_exclude.tag_id = ?";
            $conditions[] = "ct_exclude.card_id IS NULL";
            $params[] = $excludeTagId;
        }

        return ['joins' => $joins, 'conditions' => $conditions];
    }

    /**
     * Build pack-related joins and conditions for card queries
     *
     * @param ?int $packId Pack ID to filter by
     * @param bool $noPacks Whether to filter for cards with no packs
     * @param ?bool $packActive Pack active status to filter by
     * @param array<mixed> &$params Parameters array to append to
     * @return array{joins: array<string>, conditions: array<string>}
     */
    private static function buildPackFilters(
        ?int $packId,
        bool $noPacks,
        ?bool $packActive,
        array &$params
    ): array {
        $joins = [];
        $conditions = [];

        if ($packId !== null) {
            $joins[] = "INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id";
            $conditions[] = "cp.pack_id = ?";
            $params[] = $packId;
        } elseif ($noPacks) {
            $joins[] = "LEFT JOIN cards_to_packs cp ON c.card_id = cp.card_id";
            $conditions[] = "cp.card_id IS NULL";
        } elseif ($packActive !== null) {
            $joins[] = "INNER JOIN cards_to_packs cp ON c.card_id = cp.card_id";
            $joins[] = "INNER JOIN packs p ON cp.pack_id = p.pack_id";
            $conditions[] = "p.active = ?";
            $params[] = $packActive;
        }

        return ['joins' => $joins, 'conditions' => $conditions];
    }

    /**
     * Build search-related conditions for card queries
     *
     * @param ?string $searchQuery Search query string
     * @param array<mixed> &$params Parameters array to append to
     * @return array<string> Search conditions
     */
    private static function buildSearchConditions(?string $searchQuery, array &$params): array
    {
        if ($searchQuery === null) {
            return [];
        }

        $searchWords = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($searchWords)) {
            return [];
        }

        $searchConditions = [];
        foreach ($searchWords as $word) {
            $searchConditions[] = "c.copy LIKE ?";
            $params[] = '%' . $word . '%';
        }

        return ["(" . implode(" OR ", $searchConditions) . ")"];
    }

    /**
     * List cards with filtering, pagination, and total count
     *
     * This method encapsulates the complex query logic for the admin card list
     *
     * @param CardType|null $cardType Filter by card type enum, null for all
     * @param int|null $tagId Filter by tag ID, null for no tag filter
     * @param bool $noTags If true, only return cards with no tags
     * @param ?int $excludeTagId Tag ID to exclude
     * @param ?int $packId Pack ID to filter by
     * @param bool $noPacks If true, only return cards with no packs
     * @param ?bool $packActive Pack active status to filter by
     * @param ?string $searchQuery Search query string
     * @param bool $active Filter by active status
     * @param int $limit Number of cards per page (0 for no limit)
     * @param int $offset Offset for pagination
     * @return array{cards: array<int, array<string, mixed>>, total: int}
     */
    public static function listWithFilters(
        ?CardType $cardType,
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
            $searchWords = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY);
            $relevanceConditions = [];

            foreach ($searchWords as $word) {
                $relevanceConditions[] = "IF(c.copy LIKE ?, 1, 0)";
                $params[] = '%' . $word . '%';
            }

            if ($relevanceConditions !== []) {
                $selectFields = "c.*, (" . implode(" + ", $relevanceConditions) . ") as relevance";
                $orderBy = "relevance DESC, c.card_id ASC";
            }
        }

        $sql = "SELECT DISTINCT {$selectFields} FROM cards c";

        // Build filters using helper methods
        $tagFilters = self::buildTagFilters($tagId, $noTags, $excludeTagId, $params);
        $packFilters = self::buildPackFilters($packId, $noPacks, $packActive, $params);

        $joins = array_merge($tagFilters['joins'], $packFilters['joins']);
        $conditions = array_merge($tagFilters['conditions'], $packFilters['conditions']);

        // Add joins to SQL
        if ($joins !== []) {
            $sql .= " " . implode(" ", $joins);
        }

        // Add card type filter
        if ($cardType instanceof \CAH\Enums\CardType) {
            $conditions[] = "c.type = ?";
            $params[] = $cardType->value;
        }

        // Add active filter
        $conditions[] = "c.active = ?";
        $params[] = $active;

        // Add search conditions
        $searchConditions = self::buildSearchConditions($searchQuery, $params);
        $conditions = array_merge($conditions, $searchConditions);

        // Add WHERE clause
        $sql .= " WHERE " . implode(' AND ', $conditions);
        $sql .= " ORDER BY {$orderBy}";

        // Only add LIMIT if limit > 0 (0 means no limit)
        if ($limit > 0) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $cards = Database::fetchAll($sql, $params);

        // Build count query using same helper methods
        $countParams = [];
        $countTagFilters = self::buildTagFilters($tagId, $noTags, $excludeTagId, $countParams);
        $countPackFilters = self::buildPackFilters($packId, $noPacks, $packActive, $countParams);

        $countJoins = array_merge($countTagFilters['joins'], $countPackFilters['joins']);
        $countConditions = array_merge(
            $countTagFilters['conditions'],
            $countPackFilters['conditions']
        );

        $countSql = "SELECT COUNT(DISTINCT c.card_id) as total FROM cards c";
        if ($countJoins !== []) {
            $countSql .= " " . implode(" ", $countJoins);
        }

        if ($cardType instanceof \CAH\Enums\CardType) {
            $countConditions[] = "c.type = ?";
            $countParams[] = $cardType->value;
        }

        $countConditions[] = "c.active = ?";
        $countParams[] = $active;

        // Add search conditions to count query
        $countSearchConditions = self::buildSearchConditions($searchQuery, $countParams);
        $countConditions = array_merge($countConditions, $countSearchConditions);

        $countSql .= " WHERE " . implode(' AND ', $countConditions);

        $countResult = Database::fetchOne($countSql, $countParams);
        $total = (int) ( $countResult['total'] ?? 0 );

        return [
            'cards' => $cards,
            'total' => $total,
        ];
    }

    /**
     * Get a random active prompt card from active packs
     *
     * @param bool $activePacksOnly If true, only include cards from active packs
     * @return array<string, mixed>|null Random prompt card or null if none found
     */
    public static function getRandomPromptCard(bool $activePacksOnly = true): ?array
    {
        $sql = "
            SELECT DISTINCT c.*
            FROM cards c
            WHERE c.active = 1
                AND c.type = 'prompt'
        ";

        if ($activePacksOnly) {
            $sql .= "
                AND (
                    EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        INNER JOIN packs p ON cp.pack_id = p.pack_id
                        WHERE cp.card_id = c.card_id
                            AND p.active = 1
                    )
                    OR NOT EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        WHERE cp.card_id = c.card_id
                    )
                )
            ";
        }

        $sql .= " ORDER BY RAND() LIMIT 1";

        $result = Database::fetchOne($sql, []);
        return $result ?: null;
    }

    /**
     * Get random active response cards from active packs
     *
     * @param int $count Number of random cards to retrieve
     * @param bool $activePacksOnly If true, only include cards from active packs
     * @return array<array<string, mixed>> Array of random response cards
     */
    public static function getRandomResponseCards(int $count, bool $activePacksOnly = true): array
    {
        $sql = "
            SELECT DISTINCT c.*
            FROM cards c
            WHERE c.active = 1
                AND c.type = 'response'
        ";

        if ($activePacksOnly) {
            $sql .= "
                AND (
                    EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        INNER JOIN packs p ON cp.pack_id = p.pack_id
                        WHERE cp.card_id = c.card_id
                            AND p.active = 1
                    )
                    OR NOT EXISTS (
                        SELECT 1
                        FROM cards_to_packs cp
                        WHERE cp.card_id = c.card_id
                    )
                )
            ";
        }

        $sql .= " ORDER BY RAND() LIMIT ?";

        return Database::fetchAll($sql, [$count]);
    }
}
