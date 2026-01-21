<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Models\Card;

/**
 * Card Import Service
 *
 * Utility for importing cards from external sources (JSON, CSV, etc.)
 * Handles parsing black card text to determine choices count
 */
class CardImportService
{
    /**
     * Parse a black card to determine how many white cards are needed
     *
     * Counts continuous underscore sections (____) in the card text
     *
     * @param string $cardText Black card text
     * @return int Number of white cards needed (1-3)
     */
    public static function parseBlackCardChoices(string $cardText): int
    {
        preg_match_all('/_{2,}/', $cardText, $matches);
        $underscoreSections = count($matches[0]);

        if ($underscoreSections === 0) {
            return 1;
        }

        return $underscoreSections;
    }

    /**
     * Import a single card
     *
     * @param string $type 'white' or 'black'
     * @param string $text Card text
     * @param int|null $choices For black cards, number of white cards needed (auto-parsed if null)
     * @param bool $active Whether card is active
     * @return int|null Card ID or null on failure
     */
    public static function importCard(string $type, string $text, ?int $choices = null, bool $active = true): ?int
    {
        if ($type === 'black' && $choices === null) {
            $choices = self::parseBlackCardChoices($text);
        }

        return Card::create($type, $text, $choices, $active);
    }

    /**
     * Import multiple cards from array
     *
     * @param array $cards Array of card data
     * @return array ['imported' => count, 'failed' => count, 'errors' => [...]]
     */
    public static function importBatch(array $cards): array
    {
        $imported = 0;
        $failed = 0;
        $errors = [];

        foreach ($cards as $index => $cardData) {
            try {
                $type = $cardData['type'] ?? null;
                $text = $cardData['text'] ?? null;
                $choices = $cardData['choices'] ?? null;
                $active = $cardData['active'] ?? true;

                if ( ! $type || ! $text) {
                    $errors[] = "Card at index {$index}: Missing type or text";
                    $failed++;
                    continue;
                }

                $cardId = self::importCard($type, $text, $choices, $active);

                if ($cardId) {
                    $imported++;
                } else {
                    $errors[] = "Card at index {$index}: Failed to create";
                    $failed++;
                }
            } catch (\Exception $e) {
                $errors[] = "Card at index {$index}: " . $e->getMessage();
                $failed++;
            }
        }

        return [
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Import cards from JSON file
     *
     * @param string $filePath Path to JSON file
     * @return array Import results
     */
    public static function importFromJson(string $filePath): array
    {
        if ( ! file_exists($filePath)) {
            return [
                'imported' => 0,
                'failed' => 0,
                'errors' => ["File not found: {$filePath}"],
            ];
        }

        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'imported' => 0,
                'failed' => 0,
                'errors' => ["Invalid JSON: " . json_last_error_msg()],
            ];
        }

        return self::importBatch($data);
    }

    /**
     * Validate and fix choices for existing black cards
     *
     * Useful for updating cards that were imported without choices
     *
     * @return array ['updated' => count, 'errors' => [...]]
     */
    public static function fixBlackCardChoices(): array
    {
        $blackCards = Card::getActiveByType('black');
        $updated = 0;
        $errors = [];

        foreach ($blackCards as $card) {
            try {
                $parsedChoices = self::parseBlackCardChoices($card['value']);

                if ($card['choices'] === null || $card['choices'] != $parsedChoices) {
                    Card::update($card['card_id'], [
                        'choices' => $parsedChoices,
                    ]);
                    $updated++;
                }
            } catch (\Exception $e) {
                $errors[] = "Card {$card['card_id']}: " . $e->getMessage();
            }
        }

        return [
            'updated' => $updated,
            'errors' => $errors,
        ];
    }
}
