<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Models\Card;

/**
 * View and presentation helpers for game state.
 */
class GameViewService
{
    /**
     * Hydrate player data with full card information
     *
     * Replaces card IDs with full card objects for:
     * - Player hands
     * - Current prompt card
     * - Submissions
     *
     * @param array $playerData Game player data
     * @return array Player data with hydrated cards
     */
    public static function hydrateCards(array $playerData): array
    {
        // Collect all card IDs we need to fetch
        $cardIds = [];

        // Player hands
        foreach ($playerData['players'] as $player) {
            if (!empty($player['hand'])) {
                $cardIds = array_merge($cardIds, $player['hand']);
            }
        }

        // Current prompt card
        if (!empty($playerData['current_prompt_card'])) {
            $cardIds[] = $playerData['current_prompt_card'];
        }

        // Submissions
        if (!empty($playerData['submissions'])) {
            foreach ($playerData['submissions'] as $submission) {
                if (!empty($submission['cards'])) {
                    $cardIds = array_merge($cardIds, $submission['cards']);
                }
            }
        }

        // Fetch all cards in one query
        $cardIds = array_unique($cardIds);
        $cards = Card::getByIds($cardIds);

        // Index cards by ID for quick lookup
        $cardMap = [];
        foreach ($cards as $card) {
            $cardMap[$card['card_id']] = $card;
        }

        // Check for missing cards and log warnings
        $missingCardIds = array_diff($cardIds, array_keys($cardMap));
        if (!empty($missingCardIds)) {
            \CAH\Utils\Logger::warning('Missing cards during hydration', [
                'missing_card_ids' => $missingCardIds,
                'game_state' => $playerData['state'] ?? 'unknown',
            ]);
        }

        // Hydrate player hands
        foreach ($playerData['players'] as &$player) {
            if (!empty($player['hand'])) {
                $player['hand'] = array_map(
                    fn($id) => $cardMap[$id] ?? ['card_id' => $id, 'copy' => 'Unknown'],
                    $player['hand']
                );
            }
        }
        unset($player);

        // Hydrate current prompt card
        if (!empty($playerData['current_prompt_card'])) {
            $promptCardId = $playerData['current_prompt_card'];
            $playerData['current_prompt_card'] = $cardMap[$promptCardId]
                ?? ['card_id' => $promptCardId, 'copy' => 'Unknown'];
        }

        // Hydrate submissions
        if (!empty($playerData['submissions'])) {
            foreach ($playerData['submissions'] as &$submission) {
                if (!empty($submission['cards'])) {
                    $submission['cards'] = array_map(
                        fn($id) => $cardMap[$id] ?? ['card_id' => $id, 'copy' => 'Unknown'],
                        $submission['cards']
                    );
                }
            }
            unset($submission);
        }

        return $playerData;
    }

    /**
     * Filters out all other player's hands except the current player's
     * Also filters submissions until all players have submitted (Czar should not see partial submissions)
     *
     * @param array $playerData Game player data
     * @param string $playerId Game player UUID
     *
     * @return array Player data with filtered cards
     */
    public static function filterHands(array $playerData, string $playerId): array
    {
        foreach ($playerData['players'] as &$player) {
            if ($player['id'] !== $playerId) {
                $player['hand'] = [];
            }
        }
        unset($player);

        // Hide submissions from everyone until all players have submitted (or forced early review)
        if (isset($playerData['submissions'])) {
            $isCzar = $playerData['current_czar_id'] === $playerId;
            $forcedReview = !empty($playerData['forced_early_review']);

            // Count expected submissions (exclude czar, Rando auto-submits, exclude paused players)
            $activePlayers = array_filter(
                $playerData['players'],
                fn($p): bool => $p['id'] !== $playerData['current_czar_id'] && empty($p['is_paused'])
            );
            $expectedSubmissions = count($activePlayers);

            $actualSubmissions = count($playerData['submissions']);

            // Only show submissions if all players have submitted OR czar forced early review
            if ($actualSubmissions < $expectedSubmissions && !$forcedReview) {
                // For czar: show count but hide content
                if ($isCzar) {
                    $playerData['submissions'] = array_map(
                        fn(): array => ['submitted' => true],
                        $playerData['submissions']
                    );
                } else {
                    // For non-czar players: send placeholders so they can count submissions
                    $playerData['submissions'] = array_map(
                        fn(): array => ['submitted' => true],
                        $playerData['submissions']
                    );
                }
            } else {
                // All players have submitted (or forced) - shuffle submissions for anonymity (czar only sees them)
                if ($isCzar) {
                    shuffle($playerData['submissions']);
                } else {
                    // Non-czar players: send placeholders so they know all submitted
                    $playerData['submissions'] = array_map(
                        fn(): array => ['submitted' => true],
                        $playerData['submissions']
                    );
                }
            }
        }

        return $playerData;
    }

    /**
     * Add a toast notification to the game state
     *
     * @param array $playerData Game player data
     * @param string $message Toast message
     * @param string|null $icon Optional emoji/icon
     * @return array Updated player data
     */
    public static function addToast(array &$playerData, string $message, ?string $icon = null): array
    {
        if (!isset($playerData['toasts'])) {
            $playerData['toasts'] = [];
        }

        $toast = [
            'id' => bin2hex(random_bytes(8)), // Unique ID for tracking
            'message' => $message,
            'created_at' => time(),
        ];

        if ($icon) {
            $toast['icon'] = $icon;
        }

        $playerData['toasts'][] = $toast;

        // Clean up old toasts (30 seconds)
        $playerData = self::cleanExpiredToasts($playerData);

        return $playerData;
    }

    /**
     * Remove toasts older than 30 seconds
     *
     * @param array $playerData Game player data
     * @return array Updated player data
     */
    public static function cleanExpiredToasts(array $playerData): array
    {
        if (!isset($playerData['toasts']) || empty($playerData['toasts'])) {
            return $playerData;
        }

        $now = time();
        $maxAge = 30; // seconds

        $playerData['toasts'] = array_values(array_filter(
            $playerData['toasts'],
            fn($toast): bool => ($now - $toast['created_at']) < $maxAge
        ));

        return $playerData;
    }
}
