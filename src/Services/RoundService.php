<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Enums\GameState;
use CAH\Enums\GameEndReason;
use CAH\Models\Game;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\PlayerNotFoundException;
use CAH\Exceptions\InvalidGameStateException;
use CAH\Exceptions\UnauthorizedException;
use CAH\Exceptions\ValidationException;

/**
 * Round Service
 *
 * Handles round flow logic including submissions, czar picking winner,
 * drawing replacement cards, and advancing to next round
 *
 * @phpstan-type Submission array{player_id: string, cards: array<int>}
 * @phpstan-type RoundHistory array{round: int, black_card: int, czar_id: string, winner_id: string, winning_cards: array<int>}
 */
class RoundService
{
    /**
     * Submit white cards for the current round
     *
     * @param string $gameId Game code
     * @param string $playerId Player ID
     * @param array<int> $cardIds Array of white card IDs to submit
     * @return array{game_state: array<string, mixed>}
     * @throws GameNotFoundException
     * @throws InvalidGameStateException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public static function submitCards(string $gameId, string $playerId, array $cardIds): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $playerId, $cardIds) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['state'] !== GameState::PLAYING->value) {
                throw new InvalidGameStateException('Game is not in playing state');
            }

            if ($playerData['current_czar_id'] === $playerId) {
                throw new UnauthorizedException('The Card Czar cannot submit cards');
            }

            $player = GameService::findPlayer($playerData, $playerId);
            if ( ! $player) {
                throw new PlayerNotFoundException($playerId);
            }

            $requiredCards = CardService::getBlackCardChoices($playerData['current_black_card']);

            if (count($cardIds) !== $requiredCards) {
                throw new ValidationException("Must submit exactly {$requiredCards} card(s)");
            }

            foreach ($cardIds as $cardId) {
                if ( ! in_array($cardId, $player['hand'], true)) {
                    throw new ValidationException('You do not have one or more of the submitted cards');
                }
            }

            foreach ($playerData['submissions'] as $submission) {
                if ($submission['player_id'] === $playerId) {
                    throw new InvalidGameStateException('You have already submitted cards for this round');
                }
            }

            // Remove submitted cards from player's hand
            $playerIndex = array_search($playerId, array_column($playerData['players'], 'id'));
            if ($playerIndex !== false) {
                $playerData['players'][$playerIndex]['hand'] = array_values(
                    array_filter(
                        $playerData['players'][$playerIndex]['hand'],
                        fn($cardId) => !in_array($cardId, $cardIds, true)
                    )
                );

                // Draw replacement cards immediately
                $whitePile = $game['draw_pile']['white'] ?? [];
                $cardsNeeded = count($cardIds);
                $result = CardService::drawWhiteCards($whitePile, $cardsNeeded);
                
                // Add new cards to player's hand
                $playerData['players'][$playerIndex]['hand'] = array_merge(
                    $playerData['players'][$playerIndex]['hand'],
                    $result['cards']
                );
                
                // Update the white pile in the draw pile
                $game['draw_pile']['white'] = $result['remaining_pile'];
            }

            $playerData['submissions'][] = [
                'player_id' => $playerId,
                'cards' => $cardIds,
            ];

            // Update both player data and draw pile
            Game::update($gameId, [
                'player_data' => $playerData,
                'draw_pile' => $game['draw_pile']
            ]);

            return $playerData;
        });
    }

    /**
     * Czar picks the winning submission
     *
     * @param string $gameId Game code
     * @param string $czarId Czar's player ID
     * @param string $winningPlayerId Winning player's ID
     * @return array Updated game state
     */
    public static function pickWinner(string $gameId, string $czarId, string $winningPlayerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $czarId, $winningPlayerId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['current_czar_id'] !== $czarId) {
                throw new UnauthorizedException('Only the Card Czar can pick a winner');
            }

            $winningSubmission = null;
            foreach ($playerData['submissions'] as $submission) {
                if ($submission['player_id'] === $winningPlayerId) {
                    $winningSubmission = $submission;
                    break;
                }
            }

            if ( ! $winningSubmission) {
                throw new ValidationException('Winning submission not found');
            }

            foreach ($playerData['players'] as &$player) {
                if ($player['id'] === $winningPlayerId) {
                    $player['score']++;
                    break;
                }
            }

            // Save round to round_history (separate column for performance)
            $roundData = [
                'round' => $playerData['current_round'],
                'black_card' => $playerData['current_black_card'],
                'czar_id' => $czarId,
                'winner_id' => $winningPlayerId,
                'winning_cards' => $winningSubmission['cards'],
                'all_submissions' => $playerData['submissions'],
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            ];

            Game::appendRoundHistory($gameId, $roundData);
            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Check if a player has won the game
     *
     * @param array $playerData Game player data
     * @return array|null Winning player or null
     */
    public static function checkForWinner(array $playerData): ?array
    {
        $maxScore = $playerData['settings']['max_score'];

        foreach ($playerData['players'] as $player) {
            if ($player['score'] >= $maxScore) {
                return $player;
            }
        }

        return null;
    }

    /**
     * Advance to the next round
     *
     * This should be called after czar picks winner and selects next czar
     * - Removes submitted cards from players' hands
     * - Draws replacement cards for all players
     * - Discards used cards
     * - Draws new black card
     * - Clears submissions
     * - Increments round counter
     *
     * @param string $gameId Game code
     * @return array Updated game state
     */
    public static function advanceToNextRound(string $gameId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];
            $whitePile = $game['draw_pile']['white'];
            $blackPile = $game['draw_pile']['black'];
            $discardPile = $game['discard_pile'] ?? [];

            $submittedCards = [];
            foreach ($playerData['submissions'] as $submission) {
                $submittedCards = array_merge($submittedCards, $submission['cards']);
            }

            // Only discard white cards - black cards are not recycled
            $discardPile = CardService::discardCards($discardPile, $submittedCards);

            $handSize = $playerData['settings']['hand_size'];

            foreach ($playerData['players'] as &$player) {
                // Skip czar and Rando (Rando has no hand)
                if ($player['id'] === $playerData['current_czar_id'] || ! empty($player['is_rando'])) {
                    continue;
                }

                $playerSubmission = null;
                foreach ($playerData['submissions'] as $submission) {
                    if ($submission['player_id'] === $player['id']) {
                        $playerSubmission = $submission;
                        break;
                    }
                }

                if ($playerSubmission) {
                    $player['hand'] = array_values(array_diff($player['hand'], $playerSubmission['cards']));

                    $cardsToDraw = $handSize - count($player['hand']);
                    if ($cardsToDraw > 0) {
                        $result = CardService::drawWhiteCards($whitePile, $cardsToDraw);
                        $player['hand'] = array_merge($player['hand'], $result['cards']);
                        $whitePile = $result['remaining_pile'];
                    }
                }
            }

            // Check if we've run out of black cards - game is over
            if (empty($blackPile)) {
                $highestScore = -1;
                $winnerId = null;
                foreach ($playerData['players'] as $player) {
                    if ($player['score'] > $highestScore) {
                        $highestScore = $player['score'];
                        $winnerId = $player['id'];
                    }
                }

                $playerData['state'] = GameState::FINISHED->value;
                $playerData['winner_id'] = $winnerId;
                $playerData['finished_at'] = (new \DateTime())->format('Y-m-d H:i:s');
                $playerData['end_reason'] = GameEndReason::NO_BLACK_CARDS_LEFT->value;

                Game::update($gameId, [
                    'draw_pile' => ['white' => $whitePile, 'black' => $blackPile],
                    'discard_pile' => $discardPile,
                    'player_data' => $playerData,
                ]);

                return $playerData;
            }

            $blackResult = CardService::drawBlackCard($blackPile);
            $newBlackCard = $blackResult['card'];
            $blackPile = $blackResult['remaining_pile'];

            $choices = CardService::getBlackCardChoices($newBlackCard);
            $bonusCards = CardService::calculateBonusCards($choices);

            if ($bonusCards > 0) {
                // Deal bonus cards to all players except Rando
                foreach ($playerData['players'] as &$player) {
                    if (empty($player['is_rando'])) {
                        $result = CardService::drawWhiteCards($whitePile, $bonusCards);
                        $player['hand'] = array_merge($player['hand'], $result['cards']);
                        $whitePile = $result['remaining_pile'];
                    }
                }
            }

            $playerData['current_black_card'] = $newBlackCard;
            $playerData['current_round']++;
            $playerData['submissions'] = [];

            // Auto-submit for Rando if enabled
            if ($playerData['settings']['rando_enabled'] && ! empty($playerData['rando_id'])) {
                $whitePile = self::submitRandoCards($playerData, $whitePile, $choices);
            }

            Game::update($gameId, [
                'draw_pile' => ['white' => $whitePile, 'black' => $blackPile],
                'discard_pile' => $discardPile,
                'player_data' => $playerData,
            ]);

            return $playerData;
        });
    }

    /**
     * Submit random cards for Rando Cardrissian
     *
     * Draws cards directly from the white pile (Rando has no hand).
     * Rando never becomes the czar, so this is called every round.
     *
     * @param array &$playerData Player data (modified in place)
     * @param array $whitePile Current white card pile
     * @param int $cardsNeeded Number of cards to submit
     * @return array Updated white pile
     */
    public static function submitRandoCards(array &$playerData, array $whitePile, int $cardsNeeded): array
    {
        if (empty($playerData['rando_id'])) {
            return $whitePile;
        }

        // Check if Rando already submitted (prevent duplicates)
        foreach ($playerData['submissions'] as $submission) {
            if ($submission['player_id'] === $playerData['rando_id']) {
                return $whitePile;
            }
        }

        // Draw cards from the pile for Rando's submission
        $result = CardService::drawWhiteCards($whitePile, $cardsNeeded);

        // Add Rando's submission
        $playerData['submissions'][] = [
            'player_id' => $playerData['rando_id'],
            'cards' => $result['cards'],
        ];

        return $result['remaining_pile'];
    }

    /**
     * End the game
     *
     * @param string $gameId Game code
     * @param string $winnerId Winning player's ID
     * @return array Final game state
     */
    public static function endGame(string $gameId, string $winnerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $winnerId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            $playerData['state'] = GameState::FINISHED->value;
            $playerData['winner_id'] = $winnerId;
            $playerData['finished_at'] = (new \DateTime())->format('Y-m-d H:i:s');
            $playerData['end_reason'] = GameEndReason::MAX_SCORE_REACHED->value;

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Check if all non-czar players have submitted
     *
     * @param array $playerData Game player data
     * @return bool True if all players have submitted
     */
    public static function allPlayersSubmitted(array $playerData): bool
    {
        $expectedSubmissions = count($playerData['players']) - 1; // Exclude czar
        return count($playerData['submissions']) >= $expectedSubmissions;
    }
}
