<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Models\Card;

/**
 * Card Import Service
 *
 * Utility for importing cards from external sources (JSON, CSV, etc.)
 * Handles parsing prompt card text to determine choices count
 */
class CardImportService
{
    /**
     * Parse a prompt card to determine how many response cards are needed
     *
     * Counts continuous underscore sections (____) in the card text
     *
     * @param string $cardText Black card text
     * @return int Number of response cards needed (1-3)
     */
    public static function parsePromptCardChoices(string $cardText): int
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
     * @param string $type 'response' or 'prompt'
     * @param string $text Card text
     * @param int|null $choices For prompt cards, number of response cards needed (auto-parsed if null)
     * @param bool $active Whether card is active
     * @return int|null Card ID or null on failure
     */
    public static function importCard(string $type, string $text, ?int $choices = null, bool $active = true): ?int
    {
        if ($type === 'prompt' && $choices === null) {
            $choices = self::parsePromptCardChoices($text);
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
     * Validate and fix choices for existing prompt cards
     *
     * Useful for updating cards that were imported without choices
     *
     * @return array ['updated' => count, 'errors' => [...]]
     */
    public static function fixPromptCardChoices(): array
    {
        $promptCards = Card::getActiveByType('prompt');
        $updated = 0;
        $errors = [];

        foreach ($promptCards as $card) {
            try {
                $parsedChoices = self::parsePromptCardChoices($card['copy']);

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

    /**
     * Import cards from CSV file with tag processing
     *
     * CSV format: Card Text, Tag1, Tag2, ..., Tag10
     * Tags can be tag IDs (numeric) or tag names (string, will be created if not exist)
     *
     * @param string $csvContent CSV file content
     * @param string $cardType 'response' or 'prompt'
     * @return array ['imported' => int, 'failed' => int, 'errors' => array, 'warnings' => array]
     */
    public static function importFromCsv(string $csvContent, string $cardType): array
    {
        // Use a temporary file for proper CSV parsing
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_import_');
        file_put_contents($tmpFile, $csvContent);

        $handle = fopen($tmpFile, 'r');
        if ( ! $handle) {
            unlink($tmpFile);
            throw new \Exception('Failed to open CSV file');
        }

        // Load all existing tags once for efficiency
        $allTags = \CAH\Models\Tag::getAll();
        $tagsByName = [];
        $tagsById = [];
        foreach ($allTags as $tag) {
            $tagsByName[strtolower((string) $tag['name'])] = $tag;
            $tagsById[$tag['tag_id']] = $tag;
        }

        $imported = 0;
        $failed = 0;
        $errors = [];
        $warnings = [];
        $index = 0;

        while (( $data = fgetcsv($handle) ) !== false) {
            if ($index === 0) {
                // Skip header row
                $index++;
                continue;
            }

            if (empty($data[0])) {
                $index++;
                continue;
            }

            $cardText = trim($data[0]);

            // Get tags from columns 1-10, trim and filter out empty values
            $tagColumns = array_slice($data, 1, 10);
            $tagValues = [];
            foreach ($tagColumns as $tag) {
                $tag = trim((string) $tag);
                if ( ! empty($tag)) {
                    $tagValues[] = $tag;
                }
            }

            try {
                $cardId = self::importCard($cardType, $cardText);

                if ($cardId) {
                    // Process tags if any
                    foreach ($tagValues as $tagValue) {
                        $tagId = null;

                        // Check if tag value is numeric (tag ID)
                        if (is_numeric($tagValue)) {
                            $numericTagId = (int) $tagValue;

                            // Verify tag ID exists
                            if (isset($tagsById[$numericTagId])) {
                                $tagId = $numericTagId;
                            } else {
                                $warnings[] = "Row {$index}: Tag ID {$numericTagId} does not exist, skipping";
                                continue;
                            }
                        } else {
                            // Tag value is a string (tag name)
                            $tagNameLower = strtolower($tagValue);

                            // Check if tag name exists
                            if (isset($tagsByName[$tagNameLower])) {
                                $tagId = $tagsByName[$tagNameLower]['tag_id'];
                            } else {
                                // Create new tag
                                $tagId = \CAH\Models\Tag::create($tagValue, null, true);

                                // Add to our lookup arrays for future rows
                                $newTag = \CAH\Models\Tag::find($tagId);
                                if ($newTag) {
                                    $tagsByName[strtolower((string) $newTag['name'])] = $newTag;
                                    $tagsById[$tagId] = $newTag;
                                }

                                $warnings[] = "Row {$index}: Created new tag '{$tagValue}' (ID: {$tagId})";
                            }
                        }

                        // Add tag to card
                        if ($tagId !== null) {
                            \CAH\Models\Tag::addToCard($cardId, $tagId);
                        }
                    }

                    $imported++;
                } else {
                    $errors[] = "Row {$index}: Failed to import card";
                    $failed++;
                }
            } catch (\Exception $e) {
                $errors[] = "Row {$index}: " . $e->getMessage();
                $failed++;
            }

            $index++;
        }

        // Clean up
        fclose($handle);
        unlink($tmpFile);

        return [
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
