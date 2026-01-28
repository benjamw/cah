<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Models\Card;
use CAH\Exceptions\InsufficientCardsException;

/**
 * Card Service
 *
 * Handles card-related operations including drawing, shuffling, and pile management
 *
 * @phpstan-type DrawPile array{response: array<int>, prompt: array<int>}
 * @phpstan-type DrawResult array{cards: array<int>, remaining_pile: array<int>}
 */
class CardService
{
    /**
     * Build a shuffled draw pile from selected tags
     *
     * @param array<int> $tagIds Array of tag IDs
     * @return DrawPile
     */
    public static function buildDrawPile(array $tagIds): array
    {
        $responseCardIds = Card::getActiveCardsByTypeAndTags('response', $tagIds);
        $promptCardIds = Card::getActiveCardsByTypeAndTags('prompt', $tagIds);

        shuffle($responseCardIds);
        shuffle($promptCardIds);

        return [
            'response' => $responseCardIds,
            'prompt' => $promptCardIds,
        ];
    }

    /**
     * Draw cards from the response card pile
     *
     * @param array $responsePile Array of response card IDs
     * @param int $count Number of cards to draw
     *
     * @return array ['cards' => [...drawn card IDs...], 'remaining_pile' => [...remaining IDs...]]
     * @throws InsufficientCardsException If not enough cards in pile
     */
    public static function drawResponseCards(array $responsePile, int $count): array
    {
        $available = count($responsePile);
        if ($available < $count) {
            throw new InsufficientCardsException('response', $count, $available);
        }

        $drawn = array_splice($responsePile, 0, $count);

        return [
            'cards' => $drawn,
            'remaining_pile' => $responsePile,
        ];
    }

    /**
     * Draw a single prompt card from the pile
     *
     * @param array $promptPile Array of prompt card IDs
     *
     * @return array ['card' => card ID, 'remaining_pile' => [...remaining IDs...]]
     * @throws InsufficientCardsException If pile is empty
     */
    public static function drawPromptCard(array $promptPile): array
    {
        if (empty($promptPile)) {
            throw new InsufficientCardsException('prompt', 1, 0);
        }

        $card = array_shift($promptPile);

        return [
            'card' => $card,
            'remaining_pile' => $promptPile,
        ];
    }

    /**
     * Get the number of response cards needed for a prompt card
     *
     * @param int $promptCardId
     *
     * @return int Number of response cards needed (defaults to 1 if not found)
     */
    public static function getPromptCardChoices(int $promptCardId): int
    {
        $card = Card::getById($promptCardId);

        if ( ! $card || $card['choices'] === null) {
            return 1; // Default
        }

        return (int) $card['choices'];
    }

    /**
     * Calculate how many bonus cards to deal for a prompt card
     *
     * For prompt cards requiring 3+ choices, deal (n-1) extra cards to all players
     * Examples:
     *   - 1 choice: 0 bonus cards
     *   - 2 choices: 0 bonus cards
     *   - 3 choices: 2 bonus cards
     *   - 4 choices: 3 bonus cards
     *
     * @param int $choices Number of response cards required
     * @return int Number of bonus cards to deal
     */
    public static function calculateBonusCards(int $choices): int
    {
        if ($choices >= 3) {
            return $choices - 1;
        }

        return 0;
    }

    /**
     * Deal bonus cards to all players for multi-choice prompt cards
     *
     * @param array $players Array of player data (passed by reference)
     * @param array $responsePile Response card draw pile
     * @param int $bonusCount Number of bonus cards per player
     *
     * @return array Updated response pile after dealing
     */
    public static function dealBonusCards(array &$players, array $responsePile, int $bonusCount): array
    {
        if ($bonusCount <= 0) {
            return $responsePile;
        }

        foreach ($players as &$player) {
            // Draw bonus cards and add to player's hand
            $result = self::drawResponseCards($responsePile, $bonusCount);
            $player['hand'] = array_merge($player['hand'], $result['cards']);
            $responsePile = $result['remaining_pile'];
        }

        return $responsePile;
    }

    /**
     * Return cards to the bottom of the draw pile
     *
     * @param array $pile Current pile
     * @param array $cards Cards to return
     * @return array Updated pile
     */
    public static function returnCardsToPile(array $pile, array $cards): array
    {
        return array_merge($pile, $cards);
    }

    /**
     * Move cards to discard pile
     *
     * @param array $discardPile Current discard pile
     * @param array $cards Cards to discard
     * @return array Updated discard pile
     */
    public static function discardCards(array $discardPile, array $cards): array
    {
        return array_merge($discardPile, $cards);
    }

    /**
     * Check if draw pile is running low
     *
     * @param array $responsePile Response card pile
     * @param int|null $threshold Warning threshold (uses config default if null)
     *
     * @return bool True if pile is low
     */
    public static function isDrawPileLow(array $responsePile, ?int $threshold = null): bool
    {
        $threshold ??= ConfigService::getGameValue('draw_pile_warning_threshold', 10);
        return count($responsePile) < $threshold;
    }

    /**
     * Get warning message if draw pile is low
     *
     * @param array $responsePile Response card pile
     * @param int|null $threshold Warning threshold (uses config default if null)
     *
     * @return string|null Warning message or null
     */
    public static function getDrawPileWarning(array $responsePile, ?int $threshold = null): ?string
    {
        $threshold ??= ConfigService::getGameValue('draw_pile_warning_threshold', 10);
        $count = count($responsePile);

        if ($count < $threshold) {
            return "Only {$count} cards remaining in draw pile!";
        }

        return null;
    }

    /**
     * Reshuffle discard pile back into draw pile
     *
     * Shuffles the discard pile and appends it to the bottom of the draw pile
     *
     * @param array $drawPile Current draw pile
     * @param array $discardPile Current discard pile
     * @return array ['draw_pile' => [...cards...], 'discard_pile' => []]
     */
    public static function reshuffleDiscardPile(array $drawPile, array $discardPile): array
    {
        // Shuffle the discard pile
        shuffle($discardPile);

        // Append shuffled discard pile to the bottom of draw pile
        $newDrawPile = array_merge($drawPile, $discardPile);

        return [
            'draw_pile' => $newDrawPile,
            'discard_pile' => [],
        ];
    }
}
