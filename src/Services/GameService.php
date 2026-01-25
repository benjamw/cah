<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Constants\GameDefaults;
use CAH\Constants\SessionKeys;
use CAH\Database\Database;
use CAH\Enums\GameState;
use CAH\Models\Game;
use CAH\Models\Card;
use CAH\Models\Tag;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\PlayerNotFoundException;
use CAH\Exceptions\InvalidGameStateException;
use CAH\Exceptions\UnauthorizedException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\GameCodeGenerationException;
use CAH\Utils\GameCodeGenerator;
use CAH\Utils\Validator;

/**
 * Game Service
 *
 * Handles game business logic including creation, joining, starting,
 * player management, and round flow
 *
 * @phpstan-type PlayerData array{
 *     id: string,
 *     name: string,
 *     score: int,
 *     hand: array<int>,
 *     is_creator: bool,
 *     is_rando?: bool
 * }
 * @phpstan-type GameSettings array{
 *     rando_enabled: bool,
 *     unlimited_renew: bool,
 *     max_score: int,
 *     hand_size: int
 * }
 * @phpstan-type GameStateData array{
 *     settings: GameSettings,
 *     state: string,
 *     creator_id: string,
 *     players: array<PlayerData>,
 *     player_order: array<string>,
 *     order_locked: bool,
 *     current_czar_id: string|null,
 *     current_black_card: int|null,
 *     current_round: int,
 *     submissions: array<array{player_id: string, cards: array<int>}>,
 *     winner_id?: string,
 *     finished_at?: string,
 *     end_reason?: string,
 *     rando_id?: string
 * }
 * Note: round_history is now stored in a separate database column for performance
 */
class GameService
{
    private static ?array $gameConfig = null;

    /**
     * Get game configuration
     *
     * @return array<string, mixed>
     */
    private static function getConfig(): array
    {
        if (self::$gameConfig === null) {
            self::$gameConfig = require __DIR__ . '/../../config/game.php';
        }
        return self::$gameConfig;
    }
    /**
     * Create a new game
     *
     * @param string $creatorName Creator's display name
     * @param array<int> $tagIds Selected tag IDs
     * @param array<string, mixed> $settings Optional game settings
     * @return array{game_id: string, player_id: string, player_name: string}
     * @throws ValidationException
     * @throws GameCodeGenerationException
     */
    public static function createGame(string $creatorName, array $tagIds, array $settings = []): array
    {
        $nameValidation = Validator::validatePlayerName($creatorName);
        if ( ! $nameValidation['valid']) {
            throw new ValidationException($nameValidation['error']);
        }
        $creatorName = $nameValidation['name'];

        // Validate game settings
        $settingsValidation = Validator::validateGameSettings($settings);
        if ( ! $settingsValidation['valid']) {
            throw new ValidationException($settingsValidation['error']);
        }

        // Validate that all tag IDs exist and are active
        if ( ! empty($tagIds)) {
            $validTags = Tag::findMany($tagIds);
            $validTagIds = array_column($validTags, 'tag_id');

            // Check if all provided tag IDs were found
            $invalidTagIds = array_diff($tagIds, $validTagIds);
            if ( ! empty($invalidTagIds)) {
                // remove the invalid tags from the list of tags
                $tagIds = array_diff($tagIds, $invalidTagIds);
            }

            // Check if all tags are active
            foreach ($validTags as $tag) {
                if ( ! $tag['active']) {
                    // remove the inactive tag from the list of tags
                    $tagIds = array_diff($tagIds, [$tag['tag_id']]);
                }
            }
        }

        $creatorId = self::generatePlayerId();
        $piles = CardService::buildDrawPile($tagIds);

        $config = self::getConfig();
        $playerData = [
            'settings' => array_merge([
                'rando_enabled' => false,
                'unlimited_renew' => false,
                'max_score' => $config['default_max_score'],
                'hand_size' => $config['hand_size'],
            ], $settings),
            'state' => GameState::WAITING->value,
            'creator_id' => $creatorId,
            'players' => [
                [
                    'id' => $creatorId,
                    'name' => $creatorName,
                    'score' => GameDefaults::INITIAL_SCORE,
                    'hand' => [],
                    'is_creator' => true,
                ]
            ],
            'player_order' => [],
            'order_locked' => false,
            'current_czar_id' => null,
            'current_black_card' => null,
            'current_round' => GameDefaults::INITIAL_ROUND,
            'submissions' => [],
            // Note: round_history moved to separate column for performance
        ];

        // Retry game creation with new codes if duplicate key collision occurs
        // Wrap in transaction to ensure data consistency
        $maxAttempts = GameDefaults::MAX_GAME_CODE_GENERATION_ATTEMPTS;
        $attempts = 0;
        $gameId = null;

        while ($attempts < $maxAttempts) {
            $gameId = GameCodeGenerator::generateCode();

            Database::beginTransaction();
            try {
                Game::create($gameId, $tagIds, $piles, $playerData);
                Database::commit();
                break; // Success, exit loop
            } catch (\PDOException $e) {
                Database::rollback();
                // Check if it's a duplicate key error (MySQL error code 23000)
                if ($e->getCode() === '23000' && $attempts < $maxAttempts - 1) {
                    $attempts++;
                    continue; // Retry with new code
                }
                // Re-throw if it's not a duplicate key error or we're out of attempts
                throw $e;
            }
        }

        if ($gameId === null) {
            throw new GameCodeGenerationException('Unable to generate unique game code after ' . $maxAttempts . ' attempts');
        }

        // Store session data for authentication
        // Regenerate session ID to prevent session fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[SessionKeys::PLAYER_ID] = $creatorId;
        $_SESSION[SessionKeys::GAME_ID] = $gameId;

        return [
            'game_id' => $gameId,
            'player_id' => $creatorId,
            'player_name' => $creatorName,
        ];
    }

    /**
     * Generate a unique player ID
     *
     * @return string UUID v4
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



    /**
     * Join an existing game
     *
     * @param string $gameId Game code
     * @param string $playerName Player's display name
     * @return array{game_started: bool, player_id: string, player_name: string, game_state: GameStateData, player_names?: array<string>}
     * @throws ValidationException
     */
    public static function joinGame(string $gameId, string $playerName): array
    {
        $nameValidation = Validator::validatePlayerName($playerName);
        if ( ! $nameValidation['valid']) {
            throw new ValidationException($nameValidation['error']);
        }
        $playerName = $nameValidation['name'];

        return LockService::withGameLock($gameId, function () use ($gameId, $playerName) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['state'] !== GameState::WAITING->value) {
                // Game already started - return player names for late join
                $playerNames = [];
                foreach ($playerData['players'] as $player) {
                    // Exclude Rando from the list
                    if (empty($player['is_rando'])) {
                        $playerNames[] = $player['name'];
                    }
                }

                return [
                    'game_started' => true,
                    'player_names' => $playerNames,
                ];
            }

            $maxPlayers = self::getConfig()['max_players'];
            if (count($playerData['players']) >= $maxPlayers) {
                throw new ValidationException("Game is full (maximum {$maxPlayers} players)");
            }

            foreach ($playerData['players'] as $player) {
                if (strcasecmp((string) $player['name'], (string) $playerName) === 0) {
                    throw new ValidationException('A player with that name already exists');
                }
            }

            $playerId = self::generatePlayerId();

            $playerData['players'][] = [
                'id' => $playerId,
                'name' => $playerName,
                'score' => GameDefaults::INITIAL_SCORE,
                'hand' => [],
                'is_creator' => false,
            ];

            Game::updatePlayerData($gameId, $playerData);

            // Store session data for authentication
            // Regenerate session ID to prevent session fixation attacks
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION[SessionKeys::PLAYER_ID] = $playerId;
            $_SESSION[SessionKeys::GAME_ID] = $gameId;

            return [
                'game_started' => false,
                'player_id' => $playerId,
                'player_name' => $playerName,
                'game_state' => $playerData,
            ];
        });
    }

    /**
     * Start the game (creator only)
     *
     * @param string $gameId Game code
     * @param string $playerId Player ID (must be creator)
     * @return array Updated game state
     */
    public static function startGame(string $gameId, string $playerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $playerId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['creator_id'] !== $playerId) {
                throw new UnauthorizedException('Only the game creator can start the game');
            }

            if ($playerData['state'] !== GameState::WAITING->value) {
                throw new InvalidGameStateException('Game has already started');
            }

            $config = self::getConfig();
            $minPlayers = $config['min_players'];
            if (count($playerData['players']) < $minPlayers) {
                throw new ValidationException("Need at least {$minPlayers} players to start");
            }

            $whitePile = $game['draw_pile']['white'];
            $blackPile = $game['draw_pile']['black'];
            $handSize = $playerData['settings']['hand_size'];

            // Add Rando Cardrissian if enabled
            if ($playerData['settings']['rando_enabled']) {
                $randoName = $config['rando_cardrissian_name'];
                $randoId = self::generatePlayerId();
                $playerData['players'][] = [
                    'id' => $randoId,
                    'name' => $randoName,
                    'score' => GameDefaults::INITIAL_SCORE,
                    'hand' => [],
                    'is_creator' => false,
                    'is_rando' => true,
                ];
                $playerData['rando_id'] = $randoId;
            }

            // Deal hands to all players except Rando
            foreach ($playerData['players'] as &$player) {
                if ( ! empty($player['is_rando'])) {
                    continue;
                }
                $result = CardService::drawWhiteCards($whitePile, $handSize);
                $player['hand'] = $result['cards'];
                $whitePile = $result['remaining_pile'];
            }

            $blackResult = CardService::drawBlackCard($blackPile);
            $blackCardId = $blackResult['card'];
            $blackPile = $blackResult['remaining_pile'];

            $choices = CardService::getBlackCardChoices($blackCardId);
            $bonusCards = CardService::calculateBonusCards($choices);

            if ($bonusCards > 0) {
                // Deal bonus cards to all players except Rando
                $nonRandoPlayers = array_filter(
                    $playerData['players'],
                    fn($p): bool => empty($p['is_rando'])
                );
                $whitePile = CardService::dealBonusCards($nonRandoPlayers, $whitePile, $bonusCards);
                // Update player hands from filtered array
                foreach ($playerData['players'] as &$player) {
                    if (empty($player['is_rando'])) {
                        foreach ($nonRandoPlayers as $updated) {
                            if ($updated['id'] === $player['id']) {
                                $player['hand'] = $updated['hand'];
                                break;
                            }
                        }
                    }
                }
            }

            // Select first czar (exclude Rando)
            $eligiblePlayers = array_filter($playerData['players'], fn($p): bool => empty($p['is_rando']));
            $eligiblePlayers = array_values($eligiblePlayers);
            $randomIndex = random_int(0, count($eligiblePlayers) - 1);
            $firstCzar = $eligiblePlayers[$randomIndex]['id'];

            $playerData['state'] = GameState::PLAYING->value;
            $playerData['current_czar_id'] = $firstCzar;
            $playerData['current_czar_name'] = $eligiblePlayers[$randomIndex]['name'];
            $playerData['current_black_card'] = $blackCardId;
            $playerData['current_round'] = GameDefaults::FIRST_ROUND;
            $playerData['submissions'] = [];

            // Auto-submit for Rando (draws from pile)
            if ($playerData['settings']['rando_enabled']) {
                $whitePile = RoundService::submitRandoCards($playerData, $whitePile, $choices);
            }

            Game::update($gameId, [
                'draw_pile' => ['white' => $whitePile, 'black' => $blackPile],
                'player_data' => $playerData,
            ]);

            $playerData = GameService::hydrateCards($playerData);

            // filter out the _other_ player's hands and hydrate the current player's hand
            foreach ($playerData['players'] as & $player) {
                if ($player['id'] !== $playerId) {
                    $player['hand'] = [];
                }
            }

            return $playerData;
        });
    }

    /**
     * Remove a player from the game (creator only)
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID
     * @param string $targetPlayerId Player ID to remove
     * @return array Updated game state
     */
    public static function removePlayer(string $gameId, string $creatorId, string $targetPlayerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $creatorId, $targetPlayerId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['creator_id'] !== $creatorId) {
                throw new UnauthorizedException('Only the game creator can remove players');
            }

            if ($creatorId === $targetPlayerId) {
                throw new ValidationException('Cannot remove yourself from the game');
            }

            // Check minimum player count - must have more than minimum to remove
            $minPlayers = $playerData['settings']['min_players'] ?? 3;
            if (count($playerData['players']) <= $minPlayers) {
                throw new ValidationException("Cannot remove player: would leave fewer than {$minPlayers} players");
            }

            $playerIndex = null;
            $playerHand = [];

            foreach ($playerData['players'] as $index => $player) {
                if ($player['id'] === $targetPlayerId) {
                    $playerIndex = $index;
                    $playerHand = $player['hand'] ?? [];
                    break;
                }
            }

            if ($playerIndex === null) {
                throw new PlayerNotFoundException($targetPlayerId);
            }

            // Remove player from players array
            array_splice($playerData['players'], $playerIndex, 1);

            // Remove from player order
            $orderIndex = array_search($targetPlayerId, $playerData['player_order']);
            if ($orderIndex !== false) {
                array_splice($playerData['player_order'], $orderIndex, 1);
            }

            $whitePile = $game['draw_pile']['white'];
            $blackPile = $game['draw_pile']['black'];

            // Return player's hand to pile
            if ( ! empty($playerHand)) {
                $whitePile = CardService::returnCardsToPile($whitePile, $playerHand);
            }

            // Handle czar removal during active round
            $isPlayingState = $playerData['state'] === GameState::PLAYING->value;
            $isCzar = $playerData['current_czar_id'] === $targetPlayerId;
            $hasSubmissions = ! empty($playerData['submissions']);

            if ($isPlayingState && $isCzar && $hasSubmissions) {
                // Reset round state: return submitted cards to players' hands
                foreach ($playerData['submissions'] as $submission) {
                    $submittingPlayerId = $submission['player_id'];
                    $submittedCards = $submission['cards'];

                    // Find the player and return cards to their hand
                    foreach ($playerData['players'] as &$player) {
                        if ($player['id'] === $submittingPlayerId) {
                            // Skip Rando (has no hand)
                            if (empty($player['is_rando'])) {
                                $player['hand'] = array_merge($player['hand'], $submittedCards);
                            } else {
                                // Rando's cards go back to pile
                                $whitePile = CardService::returnCardsToPile($whitePile, $submittedCards);
                            }
                            break;
                        }
                    }
                }

                // Clear submissions
                $playerData['submissions'] = [];

                // Draw new black card
                $blackResult = CardService::drawBlackCard($blackPile);
                $playerData['current_black_card'] = $blackResult['card'];
                $blackPile = $blackResult['remaining_pile'];

                // Deal bonus cards if needed for new black card
                $choices = CardService::getBlackCardChoices($playerData['current_black_card']);
                $bonusCards = CardService::calculateBonusCards($choices);

                if ($bonusCards > 0) {
                    foreach ($playerData['players'] as &$player) {
                        if (empty($player['is_rando'])) {
                            $result = CardService::drawWhiteCards($whitePile, $bonusCards);
                            $player['hand'] = array_merge($player['hand'], $result['cards']);
                            $whitePile = $result['remaining_pile'];
                        }
                    }
                }
            }

            // Assign new czar if removed player was czar
            if ($isCzar) {
                $playerData['current_czar_id'] = self::getNextCzar($playerData);
            }

            // Check if too few players remain (need at least 3 to play)
            $minPlayers = 3;
            $eligiblePlayers = array_filter(
                $playerData['players'],
                fn($p): bool => empty($p['is_rando'])
            );
            
            if (count($eligiblePlayers) < $minPlayers && $playerData['state'] !== GameState::FINISHED->value) {
                // End the game due to too few players
                $playerData['state'] = GameState::FINISHED->value;
                $playerData['finished_at'] = (new \DateTime())->format('Y-m-d H:i:s');
                $playerData['end_reason'] = GameEndReason::TOO_FEW_PLAYERS->value;
                
                // Find player with highest score as winner
                $highestScore = -1;
                $winnerId = null;
                foreach ($playerData['players'] as $player) {
                    if ($player['score'] > $highestScore) {
                        $highestScore = $player['score'];
                        $winnerId = $player['id'];
                    }
                }
                if ($winnerId !== null) {
                    $playerData['winner_id'] = $winnerId;
                }
            }

            Game::update($gameId, [
                'draw_pile' => ['white' => $whitePile, 'black' => $blackPile],
                'player_data' => $playerData,
            ]);

            // Re-fetch the game and hydrate before returning
            $updatedGame = Game::find($gameId);
            return self::hydrateCards($updatedGame['player_data']);
        });
    }

    /**
     * Get the next czar in rotation (excludes Rando)
     *
     * @param array $playerData Game player data
     * @return string|null Next czar's player ID
     */
    public static function getNextCzar(array $playerData): ?string
    {
        // Filter out Rando from eligible czars
        $eligiblePlayers = array_filter(
            $playerData['players'],
            fn($p): bool => empty($p['is_rando'])
        );

        if (empty($eligiblePlayers)) {
            return null;
        }

        if ($playerData['order_locked'] && ! empty($playerData['player_order'])) {
            // Get list of current player IDs (excluding Rando)
            $currentPlayerIds = array_map(fn($p) => $p['id'], $eligiblePlayers);
            
            // Filter player_order to only include players still in game (and exclude Rando)
            $eligibleOrder = array_filter(
                $playerData['player_order'],
                fn($id): bool => in_array($id, $currentPlayerIds, true) && $id !== ($playerData['rando_id'] ?? null)
            );
            $eligibleOrder = array_values($eligibleOrder);

            if ( ! empty($eligibleOrder)) {
                $currentIndex = array_search($playerData['current_czar_id'], $eligibleOrder);
                if ($currentIndex !== false) {
                    $nextIndex = ($currentIndex + 1) % count($eligibleOrder);
                    return $eligibleOrder[$nextIndex];
                }
                // If current czar not found in order (shouldn't happen but handle it), return first
                return $eligibleOrder[0];
            }
        }

        // Fallback: return first eligible player
        $eligiblePlayers = array_values($eligiblePlayers);
        return $eligiblePlayers[0]['id'];
    }

    /**
     * Skip current czar (creator only)
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID
     * @return array Updated game state
     */
    public static function skipCzar(string $gameId, string $creatorId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $creatorId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['creator_id'] !== $creatorId) {
                throw new UnauthorizedException('Only the game creator can skip the czar');
            }

            $playerData['current_czar_id'] = self::getNextCzar($playerData);

            $playerData['submissions'] = [];

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Set the next czar by name (current czar selects player on their left)
     *
     * @param string $gameId Game code
     * @param string $currentCzarId Current czar's player ID
     * @param string $nextCzarId Next czar's player ID
     * @return array Updated game state
     */
    public static function setNextCzar(string $gameId, string $currentCzarId, string $nextCzarId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $currentCzarId, $nextCzarId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['current_czar_id'] !== $currentCzarId) {
                throw new UnauthorizedException('Only the current czar can select the next czar');
            }

            $nextCzarPlayer = null;
            foreach ($playerData['players'] as $player) {
                if ($player['id'] === $nextCzarId) {
                    $nextCzarPlayer = $player;
                    break;
                }
            }

            if ($nextCzarPlayer === null) {
                throw new PlayerNotFoundException($nextCzarId);
            }

            // Rando cannot be the czar
            if ( ! empty($nextCzarPlayer['is_rando'])) {
                throw new ValidationException('Rando Cardrissian cannot be the Card Czar');
            }

            // Update the current czar to the next czar
            $playerData['current_czar_id'] = $nextCzarId;
            $playerData['current_czar_name'] = $nextCzarPlayer['name'];

            if ( ! $playerData['order_locked']) {
                // Add current czar to order if not already there
                if ( ! in_array($currentCzarId, $playerData['player_order'], true)) {
                    $playerData['player_order'][] = $currentCzarId;
                }

                // Check if the next czar completes the circle (loops back to first)
                if (!empty($playerData['player_order']) && $nextCzarId === $playerData['player_order'][0]) {
                    // Check if anyone was skipped
                    $eligiblePlayerIds = array_map(
                        fn($p) => $p['id'],
                        array_filter($playerData['players'], fn($p): bool => empty($p['is_rando']))
                    );
                    
                    $skippedPlayers = array_diff($eligiblePlayerIds, $playerData['player_order']);
                    
                    if (!empty($skippedPlayers)) {
                        // Store skipped players info - order NOT locked yet, waiting for placement
                        $skippedNames = [];
                        foreach ($playerData['players'] as $player) {
                            if (in_array($player['id'], $skippedPlayers, true)) {
                                $skippedNames[] = $player['name'];
                            }
                        }
                        $playerData['skipped_players'] = [
                            'ids' => array_values($skippedPlayers),
                            'names' => $skippedNames,
                        ];
                        // Order will be locked after skipped players are placed
                    } else {
                        // No skipped players - lock the order
                        $playerData['order_locked'] = true;
                    }
                } elseif ( ! in_array($nextCzarId, $playerData['player_order'], true)) {
                    // Add next czar to order
                    $playerData['player_order'][] = $nextCzarId;
                }
            }

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Place a skipped player in the player order
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID (only creator can do this)
     * @param string $skippedPlayerId Skipped player's ID
     * @param string $beforePlayerId Player ID to insert before
     * @return array Updated game state
     */
    public static function placeSkippedPlayer(
        string $gameId,
        string $creatorId,
        string $skippedPlayerId,
        string $beforePlayerId
    ): array {
        return LockService::withGameLock($gameId, function () use ($gameId, $creatorId, $skippedPlayerId, $beforePlayerId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['creator_id'] !== $creatorId) {
                throw new UnauthorizedException('Only the game creator can place skipped players');
            }

            if (empty($playerData['skipped_players'])) {
                throw new ValidationException('No skipped players to place');
            }

            if (!in_array($skippedPlayerId, $playerData['skipped_players']['ids'], true)) {
                throw new ValidationException('Player is not in the skipped list');
            }

            // Find the insertion point
            $insertIndex = array_search($beforePlayerId, $playerData['player_order'], true);
            if ($insertIndex === false) {
                throw new PlayerNotFoundException($beforePlayerId);
            }

            // Insert the skipped player before the specified player
            array_splice($playerData['player_order'], $insertIndex, 0, [$skippedPlayerId]);

            // Remove from skipped list
            $skippedIndex = array_search($skippedPlayerId, $playerData['skipped_players']['ids'], true);
            if ($skippedIndex !== false) {
                array_splice($playerData['skipped_players']['ids'], $skippedIndex, 1);
                array_splice($playerData['skipped_players']['names'], $skippedIndex, 1);
            }

            // If no more skipped players, lock the order and clean up
            if (empty($playerData['skipped_players']['ids'])) {
                $playerData['order_locked'] = true;
                unset($playerData['skipped_players']);
            }

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Join game late (after it has started)
     *
     * @param string $gameId Game code
     * @param string $playerName Player's display name
     * @param string $playerName1 Name of first adjacent player
     * @param string $playerName2 Name of second adjacent player
     * @return array ['player_id' => string, 'player_name' => string, 'game_state' => array]
     */
    public static function joinGameLate(
        string $gameId,
        string $playerName,
        string $playerName1,
        string $playerName2
    ): array {
        $nameValidation = Validator::validatePlayerName($playerName);
        if ( ! $nameValidation['valid']) {
            throw new ValidationException($nameValidation['error']);
        }
        $playerName = $nameValidation['name'];

        return LockService::withGameLock($gameId, function () use ($gameId, $playerName, $playerName1, $playerName2) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            $maxPlayers = self::getConfig()['max_players'];
            if (count($playerData['players']) >= $maxPlayers) {
                throw new ValidationException("Game is full (maximum {$maxPlayers} players)");
            }

            foreach ($playerData['players'] as $player) {
                if (strcasecmp((string) $player['name'], (string) $playerName) === 0) {
                    throw new ValidationException('A player with that name already exists');
                }
            }

            $player1Id = null;
            $player2Id = null;

            foreach ($playerData['players'] as $player) {
                if (strcasecmp((string) $player['name'], $playerName1) === 0) {
                    $player1Id = $player['id'];
                }
                if (strcasecmp((string) $player['name'], $playerName2) === 0) {
                    $player2Id = $player['id'];
                }
            }

            if ( ! $player1Id || ! $player2Id) {
                throw new ValidationException('Could not find specified players');
            }

            $playerId = self::generatePlayerId();

            $whitePile = $game['draw_pile']['white'];
            $handSize = $playerData['settings']['hand_size'];
            $result = CardService::drawWhiteCards($whitePile, $handSize);

            $newPlayer = [
                'id' => $playerId,
                'name' => $playerName,
                'score' => GameDefaults::INITIAL_SCORE,
                'hand' => $result['cards'],
                'is_creator' => false,
            ];

            $playerData['players'][] = $newPlayer;

            if ( ! empty($playerData['player_order'])) {
                $index1 = array_search($player1Id, $playerData['player_order']);
                $index2 = array_search($player2Id, $playerData['player_order']);

                if ($index1 !== false && $index2 !== false) {
                    $orderCount = count($playerData['player_order']);

                    $isAdjacent = false;
                    $insertIndex = 0;

                    if (abs($index1 - $index2) === 1) {
                        $isAdjacent = true;
                        $insertIndex = max($index1, $index2);
                    } elseif (($index1 === 0 && $index2 === $orderCount - 1) ||
                              ($index2 === 0 && $index1 === $orderCount - 1)) {
                        $isAdjacent = true;
                        $insertIndex = $orderCount;
                    }

                    if ($isAdjacent) {
                        array_splice($playerData['player_order'], $insertIndex, 0, [$playerId]);
                    } else {
                        array_splice($playerData['player_order'], $index1 + 1, 0, [$playerId]);
                    }
                } elseif ($index1 !== false) {
                    array_splice($playerData['player_order'], $index1 + 1, 0, [$playerId]);
                } elseif ($index2 !== false) {
                    array_splice($playerData['player_order'], $index2 + 1, 0, [$playerId]);
                }
            }

            // Update database
            Game::update($gameId, [
                'draw_pile' => ['white' => $result['remaining_pile'], 'black' => $game['draw_pile']['black']],
                'player_data' => $playerData,
            ]);

            // Store session data for authentication
            // Regenerate session ID to prevent session fixation attacks
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION[SessionKeys::PLAYER_ID] = $playerId;
            $_SESSION[SessionKeys::GAME_ID] = $gameId;

            return [
                'player_id' => $playerId,
                'player_name' => $playerName,
                'game_state' => $playerData,
            ];
        });
    }

    /**
     * Find a player by ID
     *
     * @param array $playerData Game player data
     * @param string $playerId Player ID to find
     * @return array|null Player data or null
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
     * Check if player is the creator
     *
     * @param array $playerData Game player data
     * @param string $playerId Player ID to check
     * @return bool
     */
    public static function isCreator(array $playerData, string $playerId): bool
    {
        return $playerData['creator_id'] === $playerId;
    }

    /**
     * Check if player is the current czar
     *
     * @param array $playerData Game player data
     * @param string $playerId Player ID to check
     * @return bool
     */
    public static function isCzar(array $playerData, string $playerId): bool
    {
        return $playerData['current_czar_id'] === $playerId;
    }

    /**
     * Hydrate player data with full card information
     *
     * Replaces card IDs with full card objects for:
     * - Player hands
     * - Current black card
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
            if ( ! empty($player['hand'])) {
                $cardIds = array_merge($cardIds, $player['hand']);
            }
        }

        // Current black card
        if ( ! empty($playerData['current_black_card'])) {
            $cardIds[] = $playerData['current_black_card'];
        }

        // Submissions
        if ( ! empty($playerData['submissions'])) {
            foreach ($playerData['submissions'] as $submission) {
                if ( ! empty($submission['cards'])) {
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
        if ( ! empty($missingCardIds)) {
            \CAH\Utils\Logger::warning('Missing cards during hydration', [
                'missing_card_ids' => $missingCardIds,
                'game_state' => $playerData['state'] ?? 'unknown',
            ]);
        }

        // Hydrate player hands
        foreach ($playerData['players'] as &$player) {
            if ( ! empty($player['hand'])) {
                $player['hand'] = array_map(
                    fn($id) => $cardMap[$id] ?? ['card_id' => $id, 'value' => 'Unknown'],
                    $player['hand']
                );
            }
        }

        // Hydrate current black card
        if ( ! empty($playerData['current_black_card'])) {
            $blackCardId = $playerData['current_black_card'];
            $playerData['current_black_card'] = $cardMap[$blackCardId]
                ?? ['card_id' => $blackCardId, 'value' => 'Unknown'];
        }

        // Hydrate submissions
        if ( ! empty($playerData['submissions'])) {
            foreach ($playerData['submissions'] as &$submission) {
                if ( ! empty($submission['cards'])) {
                    $submission['cards'] = array_map(
                        fn($id) => $cardMap[$id] ?? ['card_id' => $id, 'value' => 'Unknown'],
                        $submission['cards']
                    );
                }
            }
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
        foreach ($playerData['players'] as & $player) {
            if ($player['id'] !== $playerId) {
                $player['hand'] = [];
            }
        }

        // Hide submissions from czar until all players have submitted
        $isCzar = $playerData['current_czar_id'] === $playerId;
        if ($isCzar && isset($playerData['submissions'])) {
            $expectedSubmissions = count($playerData['players']) - 1; // Exclude czar
            $actualSubmissions = count($playerData['submissions']);
            
            // Only show submissions if all players have submitted
            if ($actualSubmissions < $expectedSubmissions) {
                // Keep the array length but hide the content
                $playerData['submissions'] = array_map(
                    fn() => ['submitted' => true],
                    $playerData['submissions']
                );
            } else {
                // All players have submitted - shuffle submissions for anonymity
                shuffle($playerData['submissions']);
            }
        }

        return $playerData;
    }

    /**
     * Reshuffle discard pile back into draw pile (creator only)
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID
     * @return array Updated game state with reshuffle info
     */
    public static function reshuffleDiscardPile(string $gameId, string $creatorId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $creatorId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['creator_id'] !== $creatorId) {
                throw new UnauthorizedException('Only the game creator can reshuffle the discard pile');
            }

            if ($playerData['state'] !== GameState::PLAYING->value) {
                throw new InvalidGameStateException('Can only reshuffle during an active game');
            }

            $discardPile = $game['discard_pile'] ?? [];

            if (empty($discardPile)) {
                throw new ValidationException('Discard pile is empty, nothing to reshuffle');
            }

            // Reshuffle white cards
            $result = CardService::reshuffleDiscardPile($game['draw_pile']['white'], $discardPile);

            Game::update($gameId, [
                'draw_pile' => ['white' => $result['draw_pile'], 'black' => $game['draw_pile']['black']],
                'discard_pile' => $result['discard_pile'],
            ]);

            return [
                'success' => true,
                'cards_reshuffled' => count($discardPile),
                'new_draw_pile_size' => count($result['draw_pile']),
            ];
        });
    }

    /**
     * Transfer host/creator status to another player and optionally remove current host
     *
     * @param string $gameId Game code
     * @param string $currentHostId Current host's player ID
     * @param string $newHostId New host's player ID
     * @param bool $removeCurrentHost Whether to remove the current host after transfer
     * @return array Updated game state
     */
    public static function transferHost(string $gameId, string $currentHostId, string $newHostId, bool $removeCurrentHost = false): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $currentHostId, $newHostId, $removeCurrentHost) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['creator_id'] !== $currentHostId) {
                throw new UnauthorizedException('Only the game creator can transfer host');
            }

            if ($currentHostId === $newHostId) {
                throw new ValidationException('Cannot transfer host to yourself');
            }

            // Verify new host exists
            $newHostPlayer = self::findPlayer($playerData, $newHostId);
            if ( ! $newHostPlayer) {
                throw new PlayerNotFoundException($newHostId);
            }

            // Transfer creator status
            $playerData['creator_id'] = $newHostId;

            if ($removeCurrentHost) {
                // Remove the old host from the game
                $playerIndex = null;
                $playerHand = [];

                foreach ($playerData['players'] as $index => $player) {
                    if ($player['id'] === $currentHostId) {
                        $playerIndex = $index;
                        $playerHand = $player['hand'] ?? [];
                        break;
                    }
                }

                if ($playerIndex !== null) {
                    array_splice($playerData['players'], $playerIndex, 1);

                    // Remove from player_order if present
                    if ( ! empty($playerData['player_order'])) {
                        $playerData['player_order'] = array_values(array_filter(
                            $playerData['player_order'],
                            fn($id): bool => $id !== $currentHostId
                        ));
                    }

                    // Return cards to discard pile
                    if ( ! empty($playerHand)) {
                        $discardPile = $game['discard_pile'] ?? [];
                        $discardPile = array_merge($discardPile, $playerHand);
                        Game::update($gameId, ['discard_pile' => $discardPile]);
                    }

                    // Handle if removed player was czar
                    if ($playerData['current_czar_id'] === $currentHostId) {
                        $playerData['current_czar_id'] = self::getNextCzar($playerData);
                    }

                    // Remove submissions if player was in current round
                    $playerData['submissions'] = array_values(array_filter(
                        $playerData['submissions'],
                        fn($sub): bool => $sub['player_id'] !== $currentHostId
                    ));
                }
            }

            // Update is_creator flags AFTER removal
            foreach ($playerData['players'] as &$player) {
                $player['is_creator'] = ($player['id'] === $newHostId);
            }
            unset($player); // Break the reference

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Player leaves the game (removes themselves)
     *
     * @param string $gameId Game code
     * @param string $playerId Player ID who is leaving
     * @return array Updated game state
     */
    public static function leaveGame(string $gameId, string $playerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $playerId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            $player = self::findPlayer($playerData, $playerId);
            if ( ! $player) {
                throw new PlayerNotFoundException($playerId);
            }

            // Allow creator to leave if they're the last player or if there's only one player left
            $isCreator = $playerData['creator_id'] === $playerId;
            $playerCount = count($playerData['players']);
            
            // Only block creator from leaving if there are OTHER players who could become host
            if ($isCreator && $playerCount > 1) {
                throw new UnauthorizedException('Game creator must transfer host before leaving');
            }

            // Find and remove player
            $playerIndex = null;
            $playerHand = [];

            foreach ($playerData['players'] as $index => $p) {
                if ($p['id'] === $playerId) {
                    $playerIndex = $index;
                    $playerHand = $p['hand'] ?? [];
                    break;
                }
            }

            if ($playerIndex !== null) {
                array_splice($playerData['players'], $playerIndex, 1);

                // Remove from player_order if present
                if ( ! empty($playerData['player_order'])) {
                    $playerData['player_order'] = array_values(array_filter(
                        $playerData['player_order'],
                        fn($id): bool => $id !== $playerId
                    ));
                }

                // Return cards to discard pile
                if ( ! empty($playerHand)) {
                    $discardPile = $game['discard_pile'] ?? [];
                    $discardPile = array_merge($discardPile, $playerHand);
                    Game::update($gameId, ['discard_pile' => $discardPile]);
                }

                // Handle if player was czar during active round
                $isPlayingState = $playerData['state'] === GameState::PLAYING->value;
                $isCzar = $playerData['current_czar_id'] === $playerId;
                $hasSubmissions = ! empty($playerData['submissions']);

                if ($isPlayingState && $isCzar && $hasSubmissions) {
                    // Reset round: return submitted cards to players' hands
                    foreach ($playerData['submissions'] as $submission) {
                        $submittingPlayerId = $submission['player_id'];
                        $submittedCards = $submission['cards'];

                        foreach ($playerData['players'] as &$p) {
                            if ($p['id'] === $submittingPlayerId) {
                                $p['hand'] = array_merge($p['hand'], $submittedCards);
                                break;
                            }
                        }
                    }

                    // Clear submissions
                    $playerData['submissions'] = [];

                    // Draw new black card if there are still players
                    if (!empty($playerData['players'])) {
                        $blackPile = $game['draw_pile']['black'] ?? [];
                        if (!empty($blackPile)) {
                            $blackResult = CardService::drawBlackCard($blackPile);
                            $playerData['current_black_card'] = $blackResult['card'];
                            $game['draw_pile']['black'] = $blackResult['remaining_pile'];
                        }
                    }
                }

                // Select next czar if removed player was czar and there are still players
                if ($playerData['current_czar_id'] === $playerId && !empty($playerData['players'])) {
                    $playerData['current_czar_id'] = self::getNextCzar($playerData);
                }

                // Remove submissions from player
                $playerData['submissions'] = array_values(array_filter(
                    $playerData['submissions'],
                    fn($sub): bool => $sub['player_id'] !== $playerId
                ));
                
                // Check if too few players remain (need at least 3 to play)
                $minPlayers = 3;
                $eligiblePlayers = array_filter(
                    $playerData['players'],
                    fn($p): bool => empty($p['is_rando'])
                );
                
                if (count($eligiblePlayers) < $minPlayers && $playerData['state'] !== GameState::FINISHED->value) {
                    // End the game due to too few players
                    $playerData['state'] = GameState::FINISHED->value;
                    $playerData['finished_at'] = (new \DateTime())->format('Y-m-d H:i:s');
                    $playerData['end_reason'] = GameEndReason::TOO_FEW_PLAYERS->value;
                    
                    // Find player with highest score as winner
                    $highestScore = -1;
                    $winnerId = null;
                    foreach ($playerData['players'] as $p) {
                        if ($p['score'] > $highestScore) {
                            $highestScore = $p['score'];
                            $winnerId = $p['id'];
                        }
                    }
                    if ($winnerId !== null) {
                        $playerData['winner_id'] = $winnerId;
                    }
                }
            }

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }
}
