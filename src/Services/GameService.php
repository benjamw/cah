<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Constants\GameDefaults;
use CAH\Constants\SessionKeys;
use CAH\Database\Database;
use CAH\Enums\GameState;
use CAH\Enums\GameEndReason;
use CAH\Enums\CardType;
use CAH\Models\Game;
use CAH\Models\Card;
use CAH\Models\Tag;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\PlayerNotFoundException;
use CAH\Exceptions\InvalidGameStateException;
use CAH\Exceptions\UnauthorizedException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\GameCodeGenerationException;
use CAH\Exceptions\InsufficientCardsException;
use CAH\Utils\GameCodeGenerator;
use CAH\Utils\Logger;
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
 *     current_prompt_card: int|null,
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
        if ($tagIds !== []) {
            $validTags = Tag::findMany($tagIds);
            $validTagIds = array_column($validTags, 'tag_id');

            // Check if all provided tag IDs were found
            $invalidTagIds = array_diff($tagIds, $validTagIds);
            if ($invalidTagIds !== []) {
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

        $config = ConfigService::getGameConfig();
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
            'current_prompt_card' => null,
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
                Logger::info('Game created', [
                    'game_id' => $gameId,
                    'creator_id' => $creatorId,
                ]);
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

        if ($gameId === '') {
            throw new GameCodeGenerationException(
                'Unable to generate unique game code after ' . $maxAttempts . ' attempts'
            );
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
        return PlayerHelper::generatePlayerId();
    }



    /**
     * Join an existing game
     *
     * @param string $gameId Game code
     * @param string $playerName Player's display name
     * @return array{game_started: bool, player_id: string, player_name: string,
     *               game_state: GameStateData, player_names?: array<string>}
     * @throws ValidationException
     */
    public static function joinGame(string $gameId, string $playerName): array
    {
        $nameValidation = Validator::validatePlayerName($playerName);
        if ( ! $nameValidation['valid']) {
            throw new ValidationException($nameValidation['error']);
        }
        $playerName = $nameValidation['name'];

        return LockService::withGameLock($gameId, function () use ($gameId, $playerName): array {
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

            $maxPlayers = ConfigService::getGameValue('max_players', 10);
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
     * @return array<string, mixed> Updated game state
     */
    public static function startGame(string $gameId, string $playerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $playerId): array {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];
            self::validateStartGame($playerData, $playerId);

            $responsePile = $game['draw_pile']['response'];
            $promptPile = $game['draw_pile']['prompt'];
            $handSize = $playerData['settings']['hand_size'];

            self::validateCardAvailability($playerData, $responsePile, $promptPile, $handSize);

            if ($playerData['settings']['rando_enabled']) {
                self::addRandoCardrissian($playerData);
            }

            $responsePile = self::dealInitialHands($playerData, $responsePile, $handSize);

            $promptResult = CardService::drawPromptCard($promptPile);
            $promptCardId = $promptResult['card'];
            $promptPile = $promptResult['remaining_pile'];

            $choices = CardService::getPromptCardChoices($promptCardId);
            $bonusCards = CardService::calculateBonusCards($choices);

            if ($bonusCards > 0) {
                $responsePile = self::dealStartBonusCards($playerData, $responsePile, $bonusCards);
            }

            $czarData = self::selectFirstCzar($playerData['players']);

            $playerData['state'] = GameState::PLAYING->value;
            $playerData['current_czar_id'] = $czarData['id'];
            $playerData['current_czar_name'] = $czarData['name'];
            $playerData['current_prompt_card'] = $promptCardId;
            $playerData['current_round'] = GameDefaults::FIRST_ROUND;
            $playerData['submissions'] = [];

            if ($playerData['settings']['rando_enabled']) {
                $responsePile = RoundService::submitRandoCards($playerData, $responsePile, $choices);
            }

            Game::update($gameId, [
                'draw_pile' => ['response' => $responsePile, 'prompt' => $promptPile],
                'player_data' => $playerData,
            ]);

            Logger::info('Game started', [
                'game_id' => $gameId,
                'player_count' => count($playerData['players']),
            ]);

            return $playerData;
        });
    }

    /**
     * Validate that the game can be started
     *
     * @param array<string, mixed> $playerData Player data
     * @param string $playerId Player ID attempting to start
     */
    private static function validateStartGame(array $playerData, string $playerId): void
    {
        if ($playerData['creator_id'] !== $playerId) {
            throw new UnauthorizedException('Only the game creator can start the game');
        }

        if ($playerData['state'] !== GameState::WAITING->value) {
            throw new InvalidGameStateException('Game has already started');
        }

        $config = ConfigService::getGameConfig();
        $minPlayers = $config['min_players'];
        if (count($playerData['players']) < $minPlayers) {
            throw new ValidationException("Need at least {$minPlayers} players to start");
        }
    }

    /**
     * Validate that enough cards are available
     *
     * @param array<string, mixed> $playerData Player data
     * @param array<int> $responsePile Response pile
     * @param array<int> $promptPile Prompt pile
     * @param int $handSize Hand size
     */
    private static function validateCardAvailability(
        array $playerData,
        array $responsePile,
        array $promptPile,
        int $handSize
    ): void {
        $nonRandoPlayerCount = count($playerData['players']);
        $cardsNeededForHands = $nonRandoPlayerCount * $handSize;

        if (count($responsePile) < $cardsNeededForHands) {
            throw new InsufficientCardsException(
                CardType::RESPONSE->value,
                $cardsNeededForHands,
                count($responsePile)
            );
        }

        if ($promptPile === []) {
            throw new InsufficientCardsException(CardType::PROMPT->value, 1, 0);
        }
    }

    /**
     * Add Rando Cardrissian to the game
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     */
    private static function addRandoCardrissian(array &$playerData): void
    {
        $config = ConfigService::getGameConfig();
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

    /**
     * Deal initial hands to all non-Rando players
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     * @param array<int> $responsePile Response pile
     * @param int $handSize Hand size
     * @return array<int> Updated response pile
     */
    private static function dealInitialHands(array &$playerData, array $responsePile, int $handSize): array
    {
        foreach ($playerData['players'] as &$player) {
            if ( ! empty($player['is_rando'])) {
                continue;
            }
            $result = CardService::drawResponseCards($responsePile, $handSize);
            $player['hand'] = $result['cards'];
            $responsePile = $result['remaining_pile'];
        }
        return $responsePile;
    }

    /**
     * Deal bonus cards at game start
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     * @param array<int> $responsePile Response pile
     * @param int $bonusCards Number of bonus cards
     * @return array<int> Updated response pile
     */
    private static function dealStartBonusCards(array &$playerData, array $responsePile, int $bonusCards): array
    {
        $nonRandoPlayers = array_filter($playerData['players'], fn(array $p): bool => empty($p['is_rando']));
        $responsePile = CardService::dealBonusCards($nonRandoPlayers, $responsePile, $bonusCards);

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
        return $responsePile;
    }

    /**
     * Select the first czar randomly from eligible players
     *
     * @param array<int, array<string, mixed>> $players Players array
     * @return array{id: string, name: string} Czar ID and name
     */
    private static function selectFirstCzar(array $players): array
    {
        $eligiblePlayers = array_filter($players, fn(array $p): bool => empty($p['is_rando']));
        $eligiblePlayers = array_values($eligiblePlayers);
        $randomIndex = random_int(0, count($eligiblePlayers) - 1);

        return [
            'id' => $eligiblePlayers[$randomIndex]['id'],
            'name' => $eligiblePlayers[$randomIndex]['name'],
        ];
    }

    /**
     * Remove a player from the game (creator only)
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID
     * @param string $targetPlayerId Player ID to remove
     * @return array<string, mixed> Updated game state
     */
    public static function removePlayer(string $gameId, string $creatorId, string $targetPlayerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $creatorId, $targetPlayerId): array {
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

            // Check minimum player count before removal
            self::checkMinimumPlayersBeforeRemoval($playerData);

            // Find and extract player to remove
            [$playerIndex, $playerHand] = self::findPlayerToRemove($playerData, $targetPlayerId);

            // Remove player from players array and player order
            array_splice($playerData['players'], $playerIndex, 1);
            $orderIndex = array_search($targetPlayerId, $playerData['player_order']);
            if ($orderIndex !== false) {
                array_splice($playerData['player_order'], $orderIndex, 1);
            }

            $responsePile = $game['draw_pile']['response'];
            $promptPile = $game['draw_pile']['prompt'];

            // Return player's hand to pile
            if ( ! empty($playerHand)) {
                $responsePile = CardService::returnCardsToPile($responsePile, $playerHand);
            }

            // Handle czar removal during active round
            $isCzar = $playerData['current_czar_id'] === $targetPlayerId;
            if (self::shouldResetRound($playerData, $isCzar)) {
                [$playerData, $responsePile, $promptPile] = self::handleCzarRemovalDuringRound(
                    $playerData,
                    $responsePile,
                    $promptPile
                );
            }

            // Assign new czar if removed player was czar
            if ($isCzar) {
                $playerData['current_czar_id'] = self::getNextCzar($playerData);
            }

            // Check if too few players remain and end game if needed
            $playerData = self::checkAndHandleGameEnd($playerData);

            Game::update($gameId, [
                'draw_pile' => ['response' => $responsePile, 'prompt' => $promptPile],
                'player_data' => $playerData,
            ]);

            return $playerData;
        });
    }

    /**
     * Check if there are enough players to remove one
     *
     * @param array<string, mixed> $playerData
     * @throws ValidationException
     */
    private static function checkMinimumPlayersBeforeRemoval(array $playerData): void
    {
        $minPlayers = $playerData['settings']['min_players'] ?? 3;
        if (count($playerData['players']) <= $minPlayers) {
            throw new ValidationException("Cannot remove player: would leave fewer than {$minPlayers} players");
        }
    }

    /**
     * Find the player to remove and return their index and hand
     *
     * @param array<string, mixed> $playerData
     * @return array{0: int, 1: array<int>} [playerIndex, playerHand]
     * @throws PlayerNotFoundException
     */
    private static function findPlayerToRemove(array $playerData, string $targetPlayerId): array
    {
        foreach ($playerData['players'] as $index => $player) {
            if ($player['id'] === $targetPlayerId) {
                return [$index, $player['hand'] ?? []];
            }
        }

        throw new PlayerNotFoundException($targetPlayerId);
    }

    /**
     * Determine if the round should be reset due to czar removal
     *
     * @param array<string, mixed> $playerData
     */
    private static function shouldResetRound(array $playerData, bool $isCzar): bool
    {
        $isPlayingState = $playerData['state'] === GameState::PLAYING->value;
        $hasSubmissions = ! empty($playerData['submissions']);
        return $isPlayingState && $isCzar && $hasSubmissions;
    }

    /**
     * Handle czar removal during an active round - reset round state
     *
     * @param array<string, mixed> $playerData
     * @param array<int> $responsePile
     * @param array<int> $promptPile
     *
     * @return array{0: array<string, mixed>, 1: array<int>, 2: array<int>} [$playerData, $responsePile, $promptPile]
     */
    private static function handleCzarRemovalDuringRound(
        array $playerData,
        array $responsePile,
        array $promptPile
    ): array {
        // Return submitted cards to players' hands
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
                        $responsePile = CardService::returnCardsToPile($responsePile, $submittedCards);
                    }
                    break;
                }
            }
        }

        // Clear submissions
        $playerData['submissions'] = [];

        // Draw new prompt card
        $promptResult = CardService::drawPromptCard($promptPile);
        $playerData['current_prompt_card'] = $promptResult['card'];
        $promptPile = $promptResult['remaining_pile'];

        // Deal bonus cards if needed for new prompt card
        $choices = CardService::getPromptCardChoices($playerData['current_prompt_card']);
        $bonusCards = CardService::calculateBonusCards($choices);

        if ($bonusCards > 0) {
            foreach ($playerData['players'] as &$player) {
                if (empty($player['is_rando'])) {
                    $result = CardService::drawResponseCards($responsePile, $bonusCards);
                    $player['hand'] = array_merge($player['hand'], $result['cards']);
                    $responsePile = $result['remaining_pile'];
                }
            }
        }

        return [$playerData, $responsePile, $promptPile];
    }

    /**
     * Check if game should end due to too few players and handle game end
     *
     * @param array<string, mixed> $playerData
     * @return array<string, mixed> Updated playerData
     */
    private static function checkAndHandleGameEnd(array $playerData): array
    {
        $minPlayers = 3;
        $eligiblePlayers = array_filter(
            $playerData['players'],
            fn(array $p): bool => empty($p['is_rando'])
        );

        if (count($eligiblePlayers) < $minPlayers && $playerData['state'] === GameState::PLAYING->value) {
            // End the game due to too few players
            $playerData['state'] = GameState::FINISHED->value;
            $playerData['finished_at'] = ( new \DateTime() )->format('Y-m-d H:i:s');
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

        return $playerData;
    }

    /**
     * Find player IDs by their names
     *
     * @param array<string, mixed> $playerData
     * @return array{0: string|null, 1: string|null} [player1Id, player2Id]
     */
    private static function findPlayerIdsByNames(array $playerData, string $playerName1, string $playerName2): array
    {
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

        return [$player1Id, $player2Id];
    }

    /**
     * Insert new player into player order between two adjacent players
     *
     * @param array<int, string> $playerOrder
     * @return array<int, string> Updated player order
     */
    private static function insertIntoPlayerOrder(
        array $playerOrder,
        string $newPlayerId,
        string $player1Id,
        string $player2Id
    ): array {
        $index1 = array_search($player1Id, $playerOrder);
        $index2 = array_search($player2Id, $playerOrder);

        if ($index1 !== false && $index2 !== false) {
            $orderCount = count($playerOrder);
            $insertIndex = self::findAdjacentInsertIndex($index1, $index2, $orderCount);

            if ($insertIndex !== null) {
                array_splice($playerOrder, $insertIndex, 0, [$newPlayerId]);
            } else {
                // Players not adjacent, insert after first player
                array_splice($playerOrder, $index1 + 1, 0, [$newPlayerId]);
            }
        } elseif ($index1 !== false) {
            array_splice($playerOrder, $index1 + 1, 0, [$newPlayerId]);
        } elseif ($index2 !== false) {
            array_splice($playerOrder, $index2 + 1, 0, [$newPlayerId]);
        }

        return $playerOrder;
    }

    /**
     * Find the insertion index if two players are adjacent in the order
     *
     * @return int|null Insert index, or null if not adjacent
     */
    private static function findAdjacentInsertIndex(int $index1, int $index2, int $orderCount): ?int
    {
        // Check if indices are adjacent
        if (abs($index1 - $index2) === 1) {
            return max($index1, $index2);
        }

        // Check if they wrap around (first and last positions)
        if (
            ( $index1 === 0 && $index2 === $orderCount - 1 ) ||
            ( $index2 === 0 && $index1 === $orderCount - 1 )
        ) {
            return $orderCount;
        }

        return null; // Not adjacent
    }

    /**
     * Get the next czar in rotation (excludes Rando)
     *
     * @param array<string, mixed> $playerData Game player data
     * @return string|null Next czar's player ID
     */
    public static function getNextCzar(array $playerData): ?string
    {
        // Check if we just finished skipped players and should start from beginning
        if (isset($playerData['next_czar_after_skip'])) {
            return $playerData['next_czar_after_skip'];
        }

        // Filter out Rando and paused players from eligible czars
        $eligiblePlayers = array_filter(
            $playerData['players'],
            fn(array $p): bool => empty($p['is_rando']) && empty($p['is_paused'])
        );

        if ($eligiblePlayers === []) {
            return null;
        }

        if ($playerData['order_locked'] && ! empty($playerData['player_order'])) {
            // Get list of current player IDs (excluding Rando and paused players)
            $currentPlayerIds = array_map(fn(array $p) => $p['id'], $eligiblePlayers);

            // Filter player_order to only include players still in game (exclude Rando and paused)
            $eligibleOrder = array_filter(
                $playerData['player_order'],
                fn($id): bool => in_array($id, $currentPlayerIds, true) && $id !== ( $playerData['rando_id'] ?? null )
            );
            $eligibleOrder = array_values($eligibleOrder);

            if ($eligibleOrder !== []) {
                $currentIndex = array_search($playerData['current_czar_id'], $eligibleOrder);
                if ($currentIndex !== false) {
                    $nextIndex = ( $currentIndex + 1 ) % count($eligibleOrder);
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
     * Force early review (czar only) - allows picking winner before all submissions are in
     *
     * @param string $gameId Game code
     * @param string $playerId Player's ID (must be current czar)
     * @return array<string, mixed> Updated game state
     */
    public static function forceEarlyReview(string $gameId, string $playerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $playerId): array {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            // Verify player is the current czar
            if ($playerData['current_czar_id'] !== $playerId) {
                throw new ValidationException('Only the czar can force early review');
            }

            // Find the czar's name
            $czarName = null;
            foreach ($playerData['players'] as $player) {
                if ($player['id'] === $playerId) {
                    $czarName = $player['name'];
                    break;
                }
            }

            // Set flag to bypass submission count check
            $playerData['forced_early_review'] = true;

            // Add toast notification
            self::addToast($playerData, "{$czarName} started reviewing submissions early.", 'skip');

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Refresh player's hand - discard all cards and draw new ones
     *
     * @param string $gameId Game code
     * @param string $playerId Player's ID
     * @return array<string, mixed> Updated game state
     */
    public static function refreshPlayerHand(string $gameId, string $playerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $playerId): array {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            // Find the player
            $playerIndex = null;
            $playerName = null;
            foreach ($playerData['players'] as $index => $player) {
                if ($player['id'] === $playerId) {
                    $playerIndex = $index;
                    $playerName = $player['name'];
                    break;
                }
            }

            if ($playerIndex === null) {
                throw new PlayerNotFoundException($playerId);
            }

            // Rando cannot refresh hand
            if ( ! empty($playerData['players'][$playerIndex]['is_rando'])) {
                throw new ValidationException('Rando Cardrissian cannot refresh hand');
            }

            $currentHand = $playerData['players'][$playerIndex]['hand'];
            $handSize = count($currentHand);

            if ($handSize === 0) {
                throw new ValidationException('No cards to refresh');
            }

            $responsePile = $game['draw_pile']['response'];
            $discardPile = $game['discard_pile'] ?? [];

            // Add current hand to discard pile
            $discardPile = array_merge($discardPile, $currentHand);

            // Draw new cards
            $result = CardService::drawResponseCards($responsePile, $handSize);
            $playerData['players'][$playerIndex]['hand'] = $result['cards'];
            $responsePile = $result['remaining_pile'];

            // Update game state
            Game::update($gameId, [
                'draw_pile' => ['response' => $responsePile, 'prompt' => $game['draw_pile']['prompt']],
                'discard_pile' => $discardPile,
                'player_data' => $playerData,
            ]);

            // Add toast notification
            self::addToast($playerData, "{$playerName} refreshed their hand.", 'refresh');

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Toggle player pause status (creator only)
     * Paused players: submissions not required, skipped as czar
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID
     * @param string $targetPlayerId Player to pause/unpause
     * @return array<string, mixed> Updated game state
     */
    public static function togglePlayerPause(string $gameId, string $creatorId, string $targetPlayerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $creatorId, $targetPlayerId): array {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            if ($playerData['creator_id'] !== $creatorId) {
                throw new UnauthorizedException('Only the game creator can pause players');
            }

            // Find the target player
            $targetPlayer = null;
            $playerIndex = null;
            foreach ($playerData['players'] as $index => $player) {
                if ($player['id'] === $targetPlayerId) {
                    $targetPlayer = $player;
                    $playerIndex = $index;
                    break;
                }
            }

            if ( ! $targetPlayer) {
                throw new PlayerNotFoundException($targetPlayerId);
            }

            // Rando cannot be paused
            if ( ! empty($targetPlayer['is_rando'])) {
                throw new ValidationException('Rando Cardrissian cannot be paused');
            }

            // Toggle pause status
            $isPaused = ! empty($playerData['players'][$playerIndex]['is_paused']);
            $playerData['players'][$playerIndex]['is_paused'] = ! $isPaused;

            $playerName = $playerData['players'][$playerIndex]['name'];

            // If pausing the current czar, skip to next czar
            if ( ! $isPaused && $targetPlayerId === $playerData['current_czar_id']) {
                $playerData['current_czar_id'] = self::getNextCzar($playerData);

                // Update czar name
                foreach ($playerData['players'] as $player) {
                    if ($player['id'] === $playerData['current_czar_id']) {
                        $playerData['current_czar_name'] = $player['name'];
                        break;
                    }
                }

                // Clear submissions
                $playerData['submissions'] = [];

                self::addToast($playerData, "{$playerName} was paused. Moving to next czar.", 'pause');
            } else {
                // Add toast notification
                $status = $isPaused ? 'unpaused' : 'paused';
                $icon = $isPaused ? 'play' : 'pause';
                self::addToast($playerData, "{$playerName} has been {$status}.", $icon);
            }

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Vote to skip the current czar (requires 2+ votes)
     *
     * @param string $gameId Game code
     * @param string $voterId Voting player's ID
     * @return array<string, mixed> Updated game state with vote info
     */
    public static function voteToSkipCzar(string $gameId, string $voterId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $voterId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            // Can't vote to skip yourself
            if ($playerData['current_czar_id'] === $voterId) {
                throw new ValidationException('The czar cannot vote to skip themselves');
            }

            // Verify voter is in the game
            $voterExists = false;
            foreach ($playerData['players'] as $player) {
                if ($player['id'] === $voterId) {
                    $voterExists = true;
                    break;
                }
            }

            if ( ! $voterExists) {
                throw new PlayerNotFoundException($voterId);
            }

            // Initialize skip votes if not present
            if ( ! isset($playerData['skip_czar_votes'])) {
                $playerData['skip_czar_votes'] = [];
            }

            // Add or remove vote (toggle)
            if (in_array($voterId, $playerData['skip_czar_votes'], true)) {
                // Remove vote
                $playerData['skip_czar_votes'] = array_values(array_filter(
                    $playerData['skip_czar_votes'],
                    fn($id): bool => $id !== $voterId
                ));
            } else {
                // Add vote
                $playerData['skip_czar_votes'][] = $voterId;
            }

            $voteCount = count($playerData['skip_czar_votes']);
            $votesNeeded = 2;

            // If enough votes, skip the czar
            if ($voteCount >= $votesNeeded) {
                // Get czar name for toast
                $czarName = $playerData['current_czar_name'] ?? 'The czar';

                // Skip to next czar
                $playerData['current_czar_id'] = self::getNextCzar($playerData);

                // Update czar name
                foreach ($playerData['players'] as $player) {
                    if ($player['id'] === $playerData['current_czar_id']) {
                        $playerData['current_czar_name'] = $player['name'];
                        break;
                    }
                }

                // Clear submissions and votes
                $playerData['submissions'] = [];
                $playerData['skip_czar_votes'] = [];

                // Add toast notification
                self::addToast($playerData, "{$czarName} was skipped. Moving to next czar.", 'skip');
            }

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Skip current czar (creator only)
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID
     * @return array<string, mixed> Updated game state
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
     * @return array<string, mixed> Updated game state
     */
    public static function setNextCzar(string $gameId, string $currentCzarId, string $nextCzarId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $currentCzarId, $nextCzarId) {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];
            $nextCzarPlayer = self::validateNextCzar($playerData, $currentCzarId, $nextCzarId);

            $playerData['current_czar_id'] = $nextCzarId;
            $playerData['current_czar_name'] = $nextCzarPlayer['name'];
            unset($playerData['next_czar_after_skip']);

            if ( ! $playerData['order_locked']) {
                self::updatePlayerOrder($playerData, $currentCzarId, $nextCzarId);
            }

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Validate the next czar selection
     *
     * @param array<string, mixed> $playerData Player data
     * @param string $currentCzarId Current czar ID
     * @param string $nextCzarId Next czar ID
     * @return array<string, mixed> Next czar player data
     */
    private static function validateNextCzar(array $playerData, string $currentCzarId, string $nextCzarId): array
    {
        if ($playerData['current_czar_id'] !== $currentCzarId) {
            throw new UnauthorizedException('Only the current czar can select the next czar');
        }

        $nextCzarPlayer = self::findPlayerById($playerData['players'], $nextCzarId);

        if ($nextCzarPlayer === null) {
            throw new PlayerNotFoundException($nextCzarId);
        }

        if ( ! empty($nextCzarPlayer['is_rando'])) {
            throw new ValidationException('Rando Cardrissian cannot be the Card Czar');
        }

        return $nextCzarPlayer;
    }

    /**
     * Find a player by ID
     *
     * @param array<int, array<string, mixed>> $players Players array
     * @param string $playerId Player ID to find
     * @return array<string, mixed>|null Player data or null
     */
    private static function findPlayerById(array $players, string $playerId): ?array
    {
        foreach ($players as $player) {
            if ($player['id'] === $playerId) {
                return $player;
            }
        }
        return null;
    }

    /**
     * Update player order when setting next czar
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     * @param string $currentCzarId Current czar ID
     * @param string $nextCzarId Next czar ID
     */
    private static function updatePlayerOrder(array &$playerData, string $currentCzarId, string $nextCzarId): void
    {
        if ( ! in_array($currentCzarId, $playerData['player_order'], true)) {
            $playerData['player_order'][] = $currentCzarId;
        }

        $completesCircle = ! empty($playerData['player_order'])
            && $nextCzarId === $playerData['player_order'][0];

        if ($completesCircle) {
            self::handleOrderCompletion($playerData);
        } elseif ( ! in_array($nextCzarId, $playerData['player_order'], true)) {
            $playerData['player_order'][] = $nextCzarId;
        }
    }

    /**
     * Handle player order completion (check for skipped players)
     *
     * @param array<string, mixed> &$playerData Player data (modified in place)
     */
    private static function handleOrderCompletion(array &$playerData): void
    {
        $eligiblePlayerIds = array_map(
            fn(array $p) => $p['id'],
            array_filter($playerData['players'], fn(array $p): bool => empty($p['is_rando']))
        );

        $skippedPlayers = array_diff($eligiblePlayerIds, $playerData['player_order']);

        if ($skippedPlayers === []) {
            $playerData['order_locked'] = true;
            return;
        }

        $skippedNames = self::getPlayerNames($playerData['players'], $skippedPlayers);
        $playerData['skipped_players'] = [
            'ids' => array_values($skippedPlayers),
            'names' => $skippedNames,
        ];

        $names = implode(', ', $skippedNames);
        $verb = count($skippedNames) === 1 ? 'was' : 'were';
        self::addToast($playerData, "Player order almost complete! {$names} {$verb} skipped.", '');
    }

    /**
     * Get player names for a list of player IDs
     *
     * @param array<int, array<string, mixed>> $players Players array
     * @param array<string> $playerIds Player IDs to get names for
     * @return array<string> Player names
     */
    private static function getPlayerNames(array $players, array $playerIds): array
    {
        $names = [];
        foreach ($players as $player) {
            if (in_array($player['id'], $playerIds, true)) {
                $names[] = $player['name'];
            }
        }
        return $names;
    }

    /**
     * Place a skipped player in the player order
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID (only creator can do this)
     * @param string $skippedPlayerId Skipped player's ID
     * @param string $beforePlayerId Player ID to insert before
     * @return array<string, mixed> Updated game state
     */
    public static function placeSkippedPlayer(
        string $gameId,
        string $creatorId,
        string $skippedPlayerId,
        string $beforePlayerId
    ): array {
        $callback = function () use ($gameId, $creatorId, $skippedPlayerId, $beforePlayerId) {
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

            if ( ! in_array($skippedPlayerId, $playerData['skipped_players']['ids'], true)) {
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

            // Set the skipped player as current czar so they can take their turn immediately
            $playerData['current_czar_id'] = $skippedPlayerId;

            // Update current_czar_name for display
            foreach ($playerData['players'] as $player) {
                if ($player['id'] === $skippedPlayerId) {
                    $playerData['current_czar_name'] = $player['name'];
                    break;
                }
            }

            // If no more skipped players, lock the order and set next czar to first in order
            if (empty($playerData['skipped_players']['ids'])) {
                $playerData['order_locked'] = true;
                unset($playerData['skipped_players']);

                // Store that we should start from the beginning of the order after skipped players
                // This will be used when advancing to next round
                $playerData['next_czar_after_skip'] = $playerData['player_order'][0] ?? null;
            }

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        };

        return LockService::withGameLock($gameId, $callback);
    }

    /**
     * Join game late (after it has started)
     *
     * @param string $gameId Game code
     * @param string $playerName Player's display name
     * @param string $playerName1 Name of first adjacent player
     * @param string $playerName2 Name of second adjacent player
     * @return array{player_id: string, player_name: string, game_state: array<string, mixed>}
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

        return LockService::withGameLock($gameId, function () use (
            $gameId,
            $playerName,
            $playerName1,
            $playerName2
        ): array {
            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            $playerData = $game['player_data'];

            $maxPlayers = ConfigService::getGameValue('max_players', 10);
            if (count($playerData['players']) >= $maxPlayers) {
                throw new ValidationException("Game is full (maximum {$maxPlayers} players)");
            }

            foreach ($playerData['players'] as $player) {
                if (strcasecmp((string) $player['name'], (string) $playerName) === 0) {
                    throw new ValidationException('A player with that name already exists');
                }
            }

            // Find player IDs by names
            [$player1Id, $player2Id] = self::findPlayerIdsByNames($playerData, $playerName1, $playerName2);

            if ( ! $player1Id || ! $player2Id) {
                throw new ValidationException('Could not find specified players');
            }

            $playerId = self::generatePlayerId();

            $responsePile = $game['draw_pile']['response'];
            $handSize = $playerData['settings']['hand_size'];
            $result = CardService::drawResponseCards($responsePile, $handSize);

            $newPlayer = [
                'id' => $playerId,
                'name' => $playerName,
                'score' => GameDefaults::INITIAL_SCORE,
                'hand' => $result['cards'],
                'is_creator' => false,
            ];

            $playerData['players'][] = $newPlayer;

            // Insert into player order if order exists
            if ( ! empty($playerData['player_order'])) {
                $playerData['player_order'] = self::insertIntoPlayerOrder(
                    $playerData['player_order'],
                    $playerId,
                    $player1Id,
                    $player2Id
                );
            }

            // Update database
            Game::update($gameId, [
                'draw_pile' => ['response' => $result['remaining_pile'], 'prompt' => $game['draw_pile']['prompt']],
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
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId Player ID to find
     * @return array<string, mixed>|null Player data or null
     */
    public static function findPlayer(array $playerData, string $playerId): ?array
    {
        return PlayerHelper::findPlayer($playerData, $playerId);
    }

    /**
     * Check if player is the creator
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId Player ID to check
     */
    public static function isCreator(array $playerData, string $playerId): bool
    {
        return PlayerHelper::isCreator($playerData, $playerId);
    }

    /**
     * Check if player is the current czar
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId Player ID to check
     */
    public static function isCzar(array $playerData, string $playerId): bool
    {
        return PlayerHelper::isCzar($playerData, $playerId);
    }

    /**
     * Hydrate player data with full card information
     *
     * Replaces card IDs with full card objects for:
     * - Player hands
     * - Current prompt card
     * - Submissions
     *
     * @param array<string, mixed> $playerData Game player data
     * @return array<string, mixed> Player data with hydrated cards
     */
    public static function hydrateCards(array $playerData): array
    {
        return GameViewService::hydrateCards($playerData);
    }

    /**
     * Filters out all other player's hands except the current player's
     * Also filters submissions until all players have submitted (Czar should not see partial submissions)
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $playerId Game player UUID
     *
     * @return array<string, mixed> Player data with filtered cards
     */
    public static function filterHands(array $playerData, string $playerId): array
    {
        return GameViewService::filterHands($playerData, $playerId);
    }

    /**
     * Reshuffle discard pile back into draw pile (creator only)
     *
     * @param string $gameId Game code
     * @param string $creatorId Creator's player ID
     * @return array<string, mixed> Updated game state with reshuffle info
     */
    public static function reshuffleDiscardPile(string $gameId, string $creatorId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $creatorId): array {
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

            // Reshuffle response cards
            $result = CardService::reshuffleDiscardPile($game['draw_pile']['response'], $discardPile);

            Game::update($gameId, [
                'draw_pile' => ['response' => $result['draw_pile'], 'prompt' => $game['draw_pile']['prompt']],
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
     * @return array<string, mixed> Updated game state
     */
    public static function transferHost(
        string $gameId,
        string $currentHostId,
        string $newHostId,
        bool $removeCurrentHost = false
    ): array {
        $callback = function () use ($gameId, $currentHostId, $newHostId, $removeCurrentHost) {
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
                        fn(array $sub): bool => $sub['player_id'] !== $currentHostId
                    ));
                }
            }

            // Update is_creator flags AFTER removal
            foreach ($playerData['players'] as &$player) {
                $player['is_creator'] = ( $player['id'] === $newHostId );
            }
            unset($player); // Break the reference

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        };

        return LockService::withGameLock($gameId, $callback);
    }

    /**
     * Player leaves the game (removes themselves)
     *
     * @param string $gameId Game code
     * @param string $playerId Player ID who is leaving
     * @return array<string, mixed> Updated game state
     */
    public static function leaveGame(string $gameId, string $playerId): array
    {
        return LockService::withGameLock($gameId, function () use ($gameId, $playerId): array {
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

            // Find and extract player to remove
            [$playerIndex, $playerHand] = self::findPlayerToRemove($playerData, $playerId);

            // Remove player from players array and player order
            array_splice($playerData['players'], $playerIndex, 1);

            // Remove from player_order if present
            if ( ! empty($playerData['player_order'])) {
                $playerData['player_order'] = array_values(array_filter(
                    $playerData['player_order'],
                    fn($id): bool => $id !== $playerId
                ));
            }

            // Return cards to discard pile (different from removePlayer which returns to draw pile)
            if ( ! empty($playerHand)) {
                $discardPile = $game['discard_pile'] ?? [];
                $discardPile = array_merge($discardPile, $playerHand);
                Game::update($gameId, ['discard_pile' => $discardPile]);
            }

            $responsePile = $game['draw_pile']['response'];
            $promptPile = $game['draw_pile']['prompt'];

            // Handle czar removal during active round
            $isCzar = $playerData['current_czar_id'] === $playerId;
            if (self::shouldResetRound($playerData, $isCzar)) {
                [$playerData, $responsePile, $promptPile] = self::handleCzarRemovalDuringRound(
                    $playerData,
                    $responsePile,
                    $promptPile
                );
            }

            // Select next czar if removed player was czar and there are still players
            if ($isCzar && ! empty($playerData['players'])) {
                $playerData['current_czar_id'] = self::getNextCzar($playerData);
            }

            // Remove submissions from player
            $playerData['submissions'] = array_values(array_filter(
                $playerData['submissions'],
                fn(array $sub): bool => $sub['player_id'] !== $playerId
            ));

            // Check if too few players remain and end game if needed
            $playerData = self::checkAndHandleGameEnd($playerData);

            Game::updatePlayerData($gameId, $playerData);

            return $playerData;
        });
    }

    /**
     * Add a toast notification to the game state
     *
     * @param array<string, mixed> $playerData Game player data
     * @param string $message Toast message
     * @param string|null $icon Optional emoji/icon
     * @return array<string, mixed> Updated player data
     */
    public static function addToast(array &$playerData, string $message, ?string $icon = null): array
    {
        return GameViewService::addToast($playerData, $message, $icon);
    }

    /**
     * Remove toasts older than 30 seconds
     *
     * @param array<string, mixed> $playerData Game player data
     * @return array<string, mixed> Updated player data
     */
    public static function cleanExpiredToasts(array $playerData): array
    {
        return GameViewService::cleanExpiredToasts($playerData);
    }
}
