<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Models\Card;
use CAH\Enums\CardType;

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
     * Counts continuous underscore sections (____) in the card copy
     *
     * @param string $cardCopy Black card copy
     * @return int Number of response cards needed (1-3)
     */
    public static function parsePromptCardChoices(string $cardCopy): int
    {
        preg_match_all('/_{2,}/', $cardCopy, $matches);
        $underscoreSections = count($matches[0]);

        if ($underscoreSections === 0) {
            return 1;
        }

        return $underscoreSections;
    }

    /**
     * Import a single card
     *
     * @param CardType $type Card type enum
     * @param string $copy Card copy
     * @param int|null $choices For prompt cards, number of response cards needed (auto-parsed if null)
     * @param bool $active Whether card is active
     * @return int|null Card ID or null on failure
     */
    public static function importCard(CardType $type, string $copy, ?int $choices = null, bool $active = true): ?int
    {
        if ($type === CardType::PROMPT && $choices === null) {
            $choices = self::parsePromptCardChoices($copy);
        }

        return Card::create($type, $copy, $choices, $active);
    }

    /**
     * Import multiple cards from array
     *
     * @param array<int, array<string, mixed>> $cards Array of card data
     * @return array{imported: int, failed: int, errors: array<int, string>}
     */
    public static function importBatch(array $cards): array
    {
        $imported = 0;
        $failed = 0;
        $errors = [];

        foreach ($cards as $index => $cardData) {
            try {
                $type = $cardData['type'] ?? null;
                $copy = $cardData['copy'] ?? null;
                $choices = $cardData['choices'] ?? null;
                $active = $cardData['active'] ?? true;

                if ( ! $type || ! $copy) {
                    $errors[] = "Card at index {$index}: Missing type or text";
                    $failed++;
                    continue;
                }

                $cardId = self::importCard($type, $copy, $choices, $active);

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
     * @return array{imported: int, failed: int, errors: array<int, string>}
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
     * @return array{updated: int, errors: array<int, string>}
     */
    public static function fixPromptCardChoices(): array
    {
        $promptCards = Card::getActiveByType(CardType::PROMPT);
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
     * @param CardType $cardType Card type enum
     * @return array{imported: int, failed: int, errors: array<int, string>, warnings: array<int, string>}
     */
    public static function importFromCsv(string $csvContent, CardType $cardType): array
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
                if ($tag !== '' && $tag !== '0') {
                    $tagValues[] = $tag;
                }
            }

            try {
                $cardId = self::importCard($cardType, $cardText);

                if ($cardId) {
                    // Process tags if any
                    self::processTagsForCard(
                        $cardId,
                        $tagValues,
                        $tagsByName,
                        $tagsById,
                        $index,
                        $warnings
                    );
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

    /**
     * Process tags for an imported card
     *
     * @param int $cardId Card ID to add tags to
     * @param array<string> $tagValues Tag values (IDs or names)
     * @param array<string, array<string, mixed>> &$tagsByName Tags indexed by lowercase name
     * @param array<int, array<string, mixed>> &$tagsById Tags indexed by ID
     * @param int $rowIndex Current row index for warnings
     * @param array<int, string> &$warnings Warnings array to append to
     */
    private static function processTagsForCard(
        int $cardId,
        array $tagValues,
        array &$tagsByName,
        array &$tagsById,
        int $rowIndex,
        array &$warnings
    ): void {
        foreach ($tagValues as $tagValue) {
            $tagId = self::resolveTagId($tagValue, $tagsByName, $tagsById, $rowIndex, $warnings);

            if ($tagId !== null) {
                \CAH\Models\Tag::addToCard($cardId, $tagId);
            }
        }
    }

    /**
     * Resolve a tag value to a tag ID
     *
     * @param string $tagValue Tag value (ID or name)
     * @param array<string, array<string, mixed>> &$tagsByName Tags indexed by lowercase name
     * @param array<int, array<string, mixed>> &$tagsById Tags indexed by ID
     * @param int $rowIndex Current row index for warnings
     * @param array<int, string> &$warnings Warnings array to append to
     * @return int|null Tag ID or null if not found/created
     */
    private static function resolveTagId(
        string $tagValue,
        array &$tagsByName,
        array &$tagsById,
        int $rowIndex,
        array &$warnings
    ): ?int {
        // Check if tag value is numeric (tag ID)
        if (is_numeric($tagValue)) {
            $numericTagId = (int) $tagValue;

            if (isset($tagsById[$numericTagId])) {
                return $numericTagId;
            }

            $warnings[] = "Row {$rowIndex}: Tag ID {$numericTagId} does not exist, skipping";
            return null;
        }

        // Tag value is a string (tag name)
        $tagNameLower = strtolower($tagValue);

        if (isset($tagsByName[$tagNameLower])) {
            return $tagsByName[$tagNameLower]['tag_id'];
        }

        // Create new tag
        $tagId = \CAH\Models\Tag::create($tagValue, null, true);

        // Add to our lookup arrays for future rows
        $newTag = \CAH\Models\Tag::find($tagId);
        if ($newTag) {
            $tagsByName[strtolower((string) $newTag['name'])] = $newTag;
            $tagsById[$tagId] = $newTag;
        }

        $warnings[] = "Row {$rowIndex}: Created new tag '{$tagValue}' (ID: {$tagId})";
        return $tagId;
    }
}
