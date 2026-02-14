<?php

declare(strict_types=1);

namespace CAH\Utils;

use CAH\Enums\CardType;
use CAH\Models\Card;

class DuplicateDetector
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getCardsByType(string $type): array
    {
        $cardType = CardType::tryFrom($type);
        if ($cardType === null) {
            return [];
        }

        $result = Card::listWithFilters(
            $cardType,
            null,
            false,
            null,
            null,
            false,
            null,
            null,
            true,
            0,
            0
        );

        return $result['cards'];
    }

    /**
     * Find potential duplicate cards in the database
     *
     * @param Card $cardModel Unused, kept for backward compatibility
     * @param string $type Card type (prompt/response)
     * @param string $copy Card text
     * @param string|null $extra Extra info
     * @return array<string, mixed>|null Existing card if found, null otherwise
     */
    public static function findDuplicate(Card $cardModel, string $type, string $copy, ?string $extra = null): ?array
    {
        // Strategy 1: Exact match (type + copy + extra)
        $allCards = self::getCardsByType($type);

        foreach ($allCards as $card) {
            // Exact match
            if (
                ( $card['type'] ?? null ) === $type &&
                ( $card['copy'] ?? null ) === $copy &&
                ( $card['special'] ?? null ) === $extra
            ) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Find similar cards (fuzzy matching)
     *
     * @param Card $cardModel Unused, kept for backward compatibility
     * @param string $type Card type
     * @param string $copy Card text
     * @param float $threshold Similarity threshold (0-1)
     * @return array<int, array{card: array<string, mixed>, similarity: float}>
     */
    public static function findSimilar(Card $cardModel, string $type, string $copy, float $threshold = 0.90): array
    {
        /** @var array<int, array{card: array<string, mixed>, similarity: float}> $similar */
        $similar = [];
        $allCards = self::getCardsByType($type);

        foreach ($allCards as $card) {
            if ( ! isset($card['copy'])) {
                continue;
            }
            if ( ! is_string($card['copy'])) {
                continue;
            }
            $similarity = self::calculateSimilarity($copy, $card['copy']);

            if ($similarity >= $threshold && $similarity < 1.0) {
                $similar[] = [
                    'card' => $card,
                    'similarity' => $similarity,
                ];
            }
        }

        // Sort by similarity (highest first)
        usort(
            $similar,
            static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']
        );

        return $similar;
    }

    /**
     * Calculate text similarity between two strings
     *
     * @return float Similarity score (0-1)
     */
    private static function calculateSimilarity(string $str1, string $str2): float
    {
        // Normalize strings
        $str1 = self::normalize($str1);
        $str2 = self::normalize($str2);

        if ($str1 === $str2) {
            return 1.0;
        }

        // Use Levenshtein distance for similarity
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return 1.0 - ( $distance / $maxLen );
    }

    /**
     * Normalize text for comparison
     *
     * @return string Normalized text
     */
    private static function normalize(string $text): string
    {
        // Remove markdown formatting
        $text = preg_replace('/\*\*\*(.*?)\*\*\*/', '$1', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', (string) $text);
        $text = preg_replace('/\*(.*?)\*/', '$1', (string) $text);
        $text = preg_replace('/~~(.*?)~~/', '$1', (string) $text);

        // Convert to lowercase
        $text = strtolower((string) $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    /**
     * Generate a hash for quick duplicate detection
     *
     * @return string Hash of the card content
     */
    public static function generateHash(string $type, string $copy, ?string $extra = null): string
    {
        $content = self::normalize($copy);
        $normalized_extra = $extra ? self::normalize($extra) : '';

        return md5($type . '|' . $content . '|' . $normalized_extra);
    }
}
