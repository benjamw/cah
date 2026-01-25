<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Services\GameService;
use CAH\Models\Game;
use CAH\Exceptions\GameException;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\PlayerNotFoundException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\UnauthorizedException;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;

class GameController
{
    /**
     * Create a new game
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['player_name'] ?? null, 'player_name')
                ->required($data['tag_ids'] ?? null, 'tag_ids')
                ->array($data['tag_ids'] ?? null, 'tag_ids', 1);

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $settings = $data['settings'] ?? [];

            $result = GameService::createGame(
                $data['player_name'],
                $data['tag_ids'],
                $settings
            );

            // Generate CSRF token for the session
            $csrfToken = \CAH\Services\CsrfService::generateToken();

            return JsonResponse::success($response, [
                'game_id' => $result['game_id'],
                'player_id' => $result['player_id'],
                'player_name' => $result['player_name'],
                'csrf_token' => $csrfToken,
            ], null, 201);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (GameException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Join an existing game
     *
     * If the game has already started, returns player names for late join flow.
     */
    public function join(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['game_id'] ?? null, 'game_id')
                ->required($data['player_name'] ?? null, 'player_name');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $result = GameService::joinGame($data['game_id'], $data['player_name']);

            // Generate CSRF token for the session
            $csrfToken = \CAH\Services\CsrfService::generateToken();

            // Game already started - return error with player names for late join
            if ($result['game_started']) {
                return JsonResponse::error(
                    $response,
                    'Game has already started. Select two adjacent players to sit between.',
                    409,
                    null, // no errors
                    [
                        'game_started' => true,
                        'player_names' => $result['player_names'],
                        'csrf_token' => $csrfToken,
                    ]
                );
            }

            return JsonResponse::success($response, [
                'game_started' => false,
                'player_id' => $result['player_id'],
                'player_name' => $result['player_name'],
                'game_state' => $result['game_state'],
                'csrf_token' => $csrfToken,
            ]);
        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (GameException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Start a game
     */
    public function start(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $gameState = GameService::startGame($gameId, $playerId);

            return JsonResponse::success($response, ['game_state' => $gameState]);
        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (UnauthorizedException $e) {
            return JsonResponse::error($response, $e->getMessage(), 403);
        } catch (GameException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Get game state
     */
    public function getState(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');

            $game = Game::find($gameId);

            if ( ! $game) {
                throw new GameNotFoundException($gameId);
            }

            // Check If-Modified-Since header for conditional request
            $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
            if ($ifModifiedSince) {
                $updatedAtTimestamp = strtotime((string) $game['updated_at']);
                $ifModifiedSinceTimestamp = strtotime($ifModifiedSince);

                // If the game hasn't been modified since the client's last request, return 304
                if ($updatedAtTimestamp <= $ifModifiedSinceTimestamp) {
                    return $response->withStatus(304); // Not Modified
                }
            }

            // Hydrate card IDs with full card data
            $playerData = GameService::hydrateCards($game['player_data']);
            $playerData = GameService::filterHands($playerData, $request->getAttributes()['player_id']);

            // Calculate deck sizes
            $whiteCardsRemaining = count($game['draw_pile']['white'] ?? []);
            $blackCardsRemaining = count($game['draw_pile']['black'] ?? []);

            // Add Last-Modified header
            $response = $response->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', strtotime((string) $game['updated_at'])) . ' GMT');

            return JsonResponse::success($response, [
                'game_id' => $game['game_id'],
                'player_data' => $playerData,
                'deck_counts' => [
                    'white_cards' => $whiteCardsRemaining,
                    'black_cards' => $blackCardsRemaining,
                ],
                'created_at' => $game['created_at'],
                'updated_at' => $game['updated_at'],
            ]);
        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Skip current czar (creator only)
     */
    public function skipCzar(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $gameState = GameService::skipCzar($gameId, $playerId);

            return JsonResponse::success($response, ['game_state' => $gameState]);
        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (UnauthorizedException $e) {
            return JsonResponse::error($response, $e->getMessage(), 403);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (GameException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Join game late (after it has started)
     */
    public function joinLate(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['game_id'] ?? null, 'game_id')
                ->required($data['player_name'] ?? null, 'player_name')
                ->required($data['adjacent_player_1'] ?? null, 'adjacent_player_1')
                ->required($data['adjacent_player_2'] ?? null, 'adjacent_player_2');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $result = GameService::joinGameLate(
                $data['game_id'],
                $data['player_name'],
                $data['adjacent_player_1'],
                $data['adjacent_player_2']
            );

            // Generate CSRF token for the session
            $csrfToken = \CAH\Services\CsrfService::generateToken();

            return JsonResponse::success($response, [
                'player_id' => $result['player_id'],
                'player_name' => $result['player_name'],
                'game_state' => $result['game_state'],
                'csrf_token' => $csrfToken,
            ]);
        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (GameException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Reshuffle discard pile back into draw pile (creator only)
     */
    public function reshuffleDiscardPile(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $result = GameService::reshuffleDiscardPile($gameId, $playerId);

            return JsonResponse::success($response, $result);
        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (UnauthorizedException $e) {
            return JsonResponse::error($response, $e->getMessage(), 403);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (GameException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Place a skipped player in the player order (creator only)
     */
    public function placeSkippedPlayer(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $data = $request->getParsedBody();
            if ( ! isset($data['skipped_player_id']) || ! isset($data['before_player_id'])) {
                throw new ValidationException('skipped_player_id and before_player_id are required');
            }

            $gameState = GameService::placeSkippedPlayer(
                $gameId,
                $playerId,
                $data['skipped_player_id'],
                $data['before_player_id']
            );

            return JsonResponse::success($response, ['game_state' => $gameState]);
        } catch (GameNotFoundException | PlayerNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (UnauthorizedException $e) {
            return JsonResponse::error($response, $e->getMessage(), 403);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (GameException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * View game details (public endpoint)
     */
    public function viewGame(Request $request, Response $response, array $args): Response
    {
        try {
            $gameId = $args['gameId'];

            $game = Game::find($gameId);
            if ( ! $game) {
                return JsonResponse::notFound($response, 'Game not found');
            }

            // Hydrate the player_data with card details
            $game['player_data'] = GameService::hydrateCards($game['player_data']);

            return JsonResponse::success($response, ['game' => $game]);
        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
