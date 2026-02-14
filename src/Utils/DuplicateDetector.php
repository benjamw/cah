<?php

namespace CAH\Utils;

use CAH\Models\Card;

class DuplicateDetector
{
    /**
     * Find potential duplicate cards in the database
     * 
     * @param Card $cardModel
     * @param string $type Card type (prompt/response)
     * @param string $copy Card text
     * @param string|null $extra Extra info
     * @return array|null Existing card if found, null otherwise
     */
    public static function findDuplicate(Card $cardModel, string $type, string $copy, ?string $extra = null): ?array
    {
        // Strategy 1: Exact match (type + copy + extra)
        $allCards = $cardModel->getAll(10000, 0, $type);
        
        foreach ($allCards as $card) {
            // Exact match
            if ($card['type'] === $type && 
                $card['copy'] === $copy && 
                $card['extra'] === $extra) {
                return $card;
            }
        }
        
        return null;
    }
    
    /**
     * Find similar cards (fuzzy matching)
     * 
     * @param Card $cardModel
     * @param string $type Card type
     * @param string $copy Card text
     * @param float $threshold Similarity threshold (0-1)
     * @return array Array of similar cards with similarity scores
     */
    public static function findSimilar(Card $cardModel, string $type, string $copy, float $threshold = 0.90): array
    {
        $similar = [];
        $allCards = $cardModel->getAll(10000, 0, $type);
        
        foreach ($allCards as $card) {
            $similarity = self::calculateSimilarity($copy, $card['copy']);
            
            if ($similarity >= $threshold && $similarity < 1.0) {
                $similar[] = [
                    'card' => $card,
                    'similarity' => $similarity
                ];
            }
        }
        
        // Sort by similarity (highest first)
        usort($similar, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        
        return $similar;
    }
    
    /**
     * Calculate text similarity between two strings
     * 
     * @param string $str1
     * @param string $str2
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
        return 1.0 - ($distance / $maxLen);
    }
    
    /**
     * Normalize text for comparison
     * 
     * @param string $text
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
        $text = trim((string) $text);
        
        return $text;
    }
    
    /**
     * Generate a hash for quick duplicate detection
     * 
     * @param string $type
     * @param string $copy
     * @param string|null $extra
     * @return string Hash of the card content
     */
    public static function generateHash(string $type, string $copy, ?string $extra = null): string
    {
        $content = self::normalize($copy);
        $normalized_extra = $extra ? self::normalize($extra) : '';
        
        return md5($type . '|' . $content . '|' . $normalized_extra);
    }
}
