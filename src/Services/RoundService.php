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
 * @phpstan-type RoundHistory array{round: int, prompt_card: int, czar_id: string,
 *                                  winner_id: string, winning_cards: array<int>}
 */
class RoundService
{
    /**
     * Submit response cards for the current round
     *
     * @param string $gameId Game code
     * @param string $playerId Player ID
     * @param array<int> $cardIds Array of response card IDs to submit
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

            $requiredCards = CardService::getPromptCardChoices($playerData['current_prompt_card']);

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

            // Find player index
            $playerIndex = array_search($playerId, array_column($playerData['players'], 'id'));
            if ($playerIndex === false) {
                throw new PlayerNotFoundException($playerId);
            }

            // Draw replacement cards FIRST (before removing from hand)
            // This prevents player losing cards if draw fails
            $responsePile = $game['draw_pile']['response'] ?? [];
            $cardsNeeded = count($cardIds);
            $result = CardService::drawResponseCards($responsePile, $cardsNeeded);

            // Now remove submitted cards from player's hand
            $playerData['players'][$playerIndex]['hand'] = array_values(
                array_filter(
                    $playerData['players'][$playerIndex]['hand'],
                    fn($cardId): bool => ! in_array($cardId, $cardIds, true)
                )
            );

            // Add new cards to player's hand
            $playerData['players'][$playerIndex]['hand'] = array_merge(
                $playerData['players'][$playerIndex]['hand'],
                $result['cards']
            );

            // Update the response pile in the draw pile
            $game['draw_pile']['response'] = $result['remaining_pile'];

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
     * @return array<string, mixed> Updated game state
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
                    // Add toast notification for round winner
                    GameService::addToast($playerData, "{$player['name']} won the round!", 'trophy');
                    break;
                }
            }

            // Save round to round_history (separate column for performance)
            $roundData = [
                'round' => $playerData['current_round'],
                'prompt_card' => $playerData['current_prompt_card'],
                'czar_id' => $czarId,
                'winner_id' => $winningPlayerId,
                'winning_cards' => $winningSubmission['cards'],
                'all_submissions' => $playerData['submissions'],
                'timestamp' => ( new \DateTime() )->format('Y-m-d H:i:s'),
            ];

            Game::appendRoundHistory($gameId, $roundData);
            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Check if a player has won the game
     *
     * @param array<string, mixed> $playerData Game player data
     * @return array<string, mixed>|null Winning player or null
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
     * - Draws new prompt card
     * - Clears submissions
     * - Increments round counter
     *
     * @param string $gameId Game code
     * @return array<string, mixed> Updated game state
     */
    public static function advanceToNextRound(string $gameId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];
            $responsePile = $game['draw_pile']['response'];
            $promptPile = $game['draw_pile']['prompt'];
            $discardPile = $game['discard_pile'] ?? [];

            $submittedCards = self::collectSubmittedCards($playerData['submissions']);
            $discardPile = CardService::discardCards($discardPile, $submittedCards);

            $responsePile = self::replenishPlayerHands($playerData, $responsePile);

            // Check if we've run out of prompt cards - game is over
            if (empty($promptPile)) {
                return self::endGameNoPromptCards($gameId, $playerData, $responsePile, $promptPile, $discardPile);
            }

            $promptResult = CardService::drawPromptCard($promptPile);
            $newBlackCard = $promptResult['card'];
            $promptPile = $promptResult['remaining_pile'];

            $choices = CardService::getPromptCardChoices($newBlackCard);
            $bonusCards = CardService::calculateBonusCards($choices);

            if ($bonusCards > 0) {
                $responsePile = self::dealBonusCardsToPlayers($playerData, $responsePile, $bonusCards);
            }

            $playerData['current_prompt_card'] = $newBlackCard;
            $playerData['current_round']++;
            $playerData['submissions'] = [];
            unset($playerData['forced_early_review']);

            if ($playerData['settings']['rando_enabled'] && ! empty($playerData['rando_id'])) {
                $responsePile = self::submitRandoCards($playerData, $responsePile, $choices);
            }

            Game::update($gameId, [
                'draw_pile' => ['response' => $responsePile, 'prompt' => $promptPile],
                'discard_pile' => $discardPile,
                'player_data' => $playerData,
            ]);

            return $playerData;
        });
    }

    /**
     * Collect all submitted cards from submissions
     *
     * @param array<int, array<string, mixed>> $submissions Submissions array
     * @return array<int> All submitted card IDs
     */
    private static function collectSubmittedCards(array $submissions): array
    {
        $submittedCards = [];
        foreach ($submissions as $submission) {
            $submittedCards = array_merge($submittedCards, $submission['cards']);
        }
        return $submittedCards;
    }

    /**
     * Replenish player hands after submissions
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     * @param array<int> $responsePile Current response pile
     * @return array<int> Updated response pile
     */
    private static function replenishPlayerHands(array &$playerData, array $responsePile): array
    {
        $handSize = $playerData['settings']['hand_size'];

        foreach ($playerData['players'] as &$player) {
            if ($player['id'] === $playerData['current_czar_id'] || ! empty($player['is_rando'])) {
                continue;
            }

            $playerSubmission = self::findPlayerSubmission($playerData['submissions'], $player['id']);

            if ($playerSubmission) {
                $player['hand'] = array_values(array_diff($player['hand'], $playerSubmission['cards']));

                $cardsToDraw = $handSize - count($player['hand']);
                if ($cardsToDraw > 0) {
                    $result = CardService::drawResponseCards($responsePile, $cardsToDraw);
                    $player['hand'] = array_merge($player['hand'], $result['cards']);
                    $responsePile = $result['remaining_pile'];
                }
            }
        }

        return $responsePile;
    }

    /**
     * Find a player's submission
     *
     * @param array<int, array<string, mixed>> $submissions Submissions array
     * @param string $playerId Player ID to find
     * @return array<string, mixed>|null Submission or null if not found
     */
    private static function findPlayerSubmission(array $submissions, string $playerId): ?array
    {
        foreach ($submissions as $submission) {
            if ($submission['player_id'] === $playerId) {
                return $submission;
            }
        }
        return null;
    }

    /**
     * End game when no prompt cards are left
     *
     * @param string $gameId Game ID
     * @param array<string, mixed> &$playerData Player data
     * @param array<int> $responsePile Response pile
     * @param array<int> $promptPile Prompt pile
     * @param array<int> $discardPile Discard pile
     * @return array<string, mixed> Updated player data
     */
    private static function endGameNoPromptCards(
        string $gameId,
        array &$playerData,
        array $responsePile,
        array $promptPile,
        array $discardPile
    ): array {
        $winnerId = self::findHighestScoringPlayer($playerData['players']);

        $playerData['state'] = GameState::FINISHED->value;
        $playerData['winner_id'] = $winnerId;
        $playerData['finished_at'] = ( new \DateTime() )->format('Y-m-d H:i:s');
        $playerData['end_reason'] = GameEndReason::NO_BLACK_CARDS_LEFT->value;

        Game::update($gameId, [
            'draw_pile' => ['response' => $responsePile, 'prompt' => $promptPile],
            'discard_pile' => $discardPile,
            'player_data' => $playerData,
        ]);

        return $playerData;
    }

    /**
     * Find the player with the highest score
     *
     * @param array<int, array<string, mixed>> $players Players array
     * @return string|null Winner player ID or null
     */
    private static function findHighestScoringPlayer(array $players): ?string
    {
        $highestScore = -1;
        $winnerId = null;
        foreach ($players as $player) {
            if ($player['score'] > $highestScore) {
                $highestScore = $player['score'];
                $winnerId = $player['id'];
            }
        }
        return $winnerId;
    }

    /**
     * Deal bonus cards to all non-Rando players
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     * @param array<int> $responsePile Current response pile
     * @param int $bonusCards Number of bonus cards to deal
     * @return array<int> Updated response pile
     */
    private static function dealBonusCardsToPlayers(
        array &$playerData,
        array $responsePile,
        int $bonusCards
    ): array {
        foreach ($playerData['players'] as &$player) {
            if (empty($player['is_rando'])) {
                $result = CardService::drawResponseCards($responsePile, $bonusCards);
                $player['hand'] = array_merge($player['hand'], $result['cards']);
                $responsePile = $result['remaining_pile'];
            }
        }
        return $responsePile;
    }

    /**
     * Submit random cards for Rando Cardrissian
     *
     * Draws cards directly from the response pile (Rando has no hand).
     * Rando never becomes the czar, so this is called every round.
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     * @param array<int> $responsePile Current response card pile
     * @param int $cardsNeeded Number of cards to submit
     * @return array<int> Updated response pile
     */
    public static function submitRandoCards(array &$playerData, array $responsePile, int $cardsNeeded): array
    {
        if (empty($playerData['rando_id'])) {
            return $responsePile;
        }

        // Check if Rando already submitted (prevent duplicates)
        foreach ($playerData['submissions'] as $submission) {
            if ($submission['player_id'] === $playerData['rando_id']) {
                return $responsePile;
            }
        }

        // Draw cards from the pile for Rando's submission
        $result = CardService::drawResponseCards($responsePile, $cardsNeeded);

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
     * @return array<string, mixed> Final game state
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
            $playerData['finished_at'] = ( new \DateTime() )->format('Y-m-d H:i:s');
            $playerData['end_reason'] = GameEndReason::MAX_SCORE_REACHED->value;

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Check if all non-czar players have submitted
     *
     * @param array<string, mixed> $playerData Game player data
     * @return bool True if all players have submitted
     */
    public static function allPlayersSubmitted(array $playerData): bool
    {
        $expectedSubmissions = count($playerData['players']) - 1; // Exclude czar
        return count($playerData['submissions']) >= $expectedSubmissions;
    }
}
