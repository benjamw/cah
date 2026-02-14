<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Enums\CardType;

/**
 * Player Helper Service
 *
 * Utility methods for player-related operations
 */
class PlayerHelper
{
    /**
     * Find a player by ID
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId Player ID to find
     * @return array<string, mixed>|null Player array or null if not found
     */
    public static function findPlayer(array $playerData, string $playerId): ?array
    {
        foreach ($playerData['players'] as $player) {
            if ($player['id'] === $playerId) {
                return $player;
            }
        }
        return null;
    }

    /**
     * Check if a player is the game creator
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId Player ID to check
     */
    public static function isCreator(array $playerData, string $playerId): bool
    {
        return $playerData['creator_id'] === $playerId;
    }

    /**
     * Check if a player is the current czar
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId Player ID to check
     */
    public static function isCzar(array $playerData, string $playerId): bool
    {
        if (empty($playerData['current_czar_id'])) {
            return false;
        }

        return $playerData['current_czar_id'] === $playerId;
    }

    /**
     * Filter player hands to hide other players' cards
     *
     * Returns a copy of player data with hands filtered so each player
     * can only see their own hand (except Rando Cardrissian)
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId The player ID requesting the data
     * @return array<string, mixed> Player data with filtered hands
     */
    public static function filterHands(array $playerData, string $playerId): array
    {
        $filtered = $playerData;

        foreach ($filtered['players'] as &$player) {
            // Show full hand if:
            // 1. It's the requesting player's own hand
            // 2. It's Rando Cardrissian (is_rando = true)
            $isOwnHand = $player['id'] === $playerId;
            $isRando = ! empty($player['is_rando']);

            if ( ! $isOwnHand && ! $isRando) {
                // For other players, just show card count
                $player['hand'] = array_map(fn($card): array => [
                    'card_id' => null,  // Hide card ID
                    'copy' => '*** HIDDEN ***',  // Hide card text
                    'type' => CardType::RESPONSE->value
                ], $player['hand']);
            }
        }

        return $filtered;
    }

    /**
     * Generate a unique player ID (UUID v4 format)
     *
     * @return string UUID v4 formatted player ID
     */
    public static function generatePlayerId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
