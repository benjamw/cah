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
 * @phpstan-type DrawPile array{white: array<int>, black: array<int>}
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
        $whiteCardIds = Card::getActiveCardsByTypeAndTags('white', $tagIds);
        $blackCardIds = Card::getActiveCardsByTypeAndTags('black', $tagIds);

        shuffle($whiteCardIds);
        shuffle($blackCardIds);

        return [
            'white' => $whiteCardIds,
            'black' => $blackCardIds,
        ];
    }

    /**
     * Draw cards from the white card pile
     *
     * @param array $whitePile Array of white card IDs
     * @param int $count Number of cards to draw
     * @return array ['cards' => [...drawn card IDs...], 'remaining_pile' => [...remaining IDs...]]
     * @throws InsufficientCardsException If not enough cards in pile
     */
    public static function drawWhiteCards(array $whitePile, int $count): array
    {
        $available = count($whitePile);
        if ($available < $count) {
            throw new InsufficientCardsException('white', $count, $available);
        }

        $drawn = array_splice($whitePile, 0, $count);

        return [
            'cards' => $drawn,
            'remaining_pile' => $whitePile,
        ];
    }

    /**
     * Draw a single black card from the pile
     *
     * @param array $blackPile Array of black card IDs
     * @return array ['card' => card ID, 'remaining_pile' => [...remaining IDs...]]
     * @throws InsufficientCardsException If pile is empty
     */
    public static function drawBlackCard(array $blackPile): array
    {
        if (empty($blackPile)) {
            throw new InsufficientCardsException('black', 1, 0);
        }

        $card = array_shift($blackPile);

        return [
            'card' => $card,
            'remaining_pile' => $blackPile,
        ];
    }

    /**
     * Get the number of white cards needed for a black card
     *
     * @param int $blackCardId
     * @return int Number of white cards needed (defaults to 1 if not found)
     */
    public static function getBlackCardChoices(int $blackCardId): int
    {
        $card = Card::getById($blackCardId);

        if ( ! $card || $card['choices'] === null) {
            return 1; // Default
        }

        return (int) $card['choices'];
    }

    /**
     * Calculate how many bonus cards to deal for a black card
     *
     * For black cards requiring 3+ choices, deal (n-1) extra cards to all players
     * Examples:
     *   - 1 choice: 0 bonus cards
     *   - 2 choices: 0 bonus cards
     *   - 3 choices: 2 bonus cards
     *   - 4 choices: 3 bonus cards
     *
     * @param int $choices Number of white cards required
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
     * Deal bonus cards to all players for multi-choice black cards
     *
     * @param array $players Array of player data (passed by reference)
     * @param array $whitePile White card draw pile
     * @param int $bonusCount Number of bonus cards per player
     * @return array Updated white pile after dealing
     */
    public static function dealBonusCards(array &$players, array $whitePile, int $bonusCount): array
    {
        if ($bonusCount <= 0) {
            return $whitePile;
        }

        foreach ($players as &$player) {
            // Draw bonus cards and add to player's hand
            $result = self::drawWhiteCards($whitePile, $bonusCount);
            $player['hand'] = array_merge($player['hand'], $result['cards']);
            $whitePile = $result['remaining_pile'];
        }

        return $whitePile;
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
     * @param array $whitePile White card pile
     * @param int|null $threshold Warning threshold (uses config default if null)
     * @return bool True if pile is low
     */
    public static function isDrawPileLow(array $whitePile, ?int $threshold = null): bool
    {
        $threshold ??= ConfigService::getGameValue('draw_pile_warning_threshold', 10);
        return count($whitePile) < $threshold;
    }

    /**
     * Get warning message if draw pile is low
     *
     * @param array $whitePile White card pile
     * @param int|null $threshold Warning threshold (uses config default if null)
     * @return string|null Warning message or null
     */
    public static function getDrawPileWarning(array $whitePile, ?int $threshold = null): ?string
    {
        $threshold ??= ConfigService::getGameValue('draw_pile_warning_threshold', 10);
        $count = count($whitePile);

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
