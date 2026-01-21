<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Services\GameService;
use CAH\Models\Game;
use CAH\Exceptions\GameException;
use CAH\Exceptions\GameNotFoundException;
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

            $validator = (new Validator())
                ->required($data['creator_name'] ?? null, 'creator_name')
                ->required($data['tag_ids'] ?? null, 'tag_ids')
                ->array($data['tag_ids'] ?? null, 'tag_ids', 1);

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $settings = $data['settings'] ?? [];

            $result = GameService::createGame(
                $data['creator_name'],
                $data['tag_ids'],
                $settings
            );

            return JsonResponse::success($response, [
                'game_id' => $result['game_id'],
                'player_id' => $result['player_id'],
                'player_name' => $result['player_name'],
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

            $validator = (new Validator())
                ->required($data['game_id'] ?? null, 'game_id')
                ->required($data['player_name'] ?? null, 'player_name');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $result = GameService::joinGame($data['game_id'], $data['player_name']);

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
                    ]
                );
            }

            return JsonResponse::success($response, [
                'game_started' => false,
                'player_id' => $result['player_id'],
                'player_name' => $result['player_name'],
                'game_state' => $result['game_state'],
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

            // Hydrate card IDs with full card data
            $playerData = GameService::hydrateCards($game['player_data']);
            $playerData = GameService::filterHands($playerData, $request->getAttributes()['player_id']);

            return JsonResponse::success($response, [
                'game_id' => $game['game_id'],
                'player_data' => $playerData,
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

            $validator = (new Validator())
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

            return JsonResponse::success($response, [
                'player_id' => $result['player_id'],
                'player_name' => $result['player_name'],
                'game_state' => $result['game_state'],
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

            // Hydrate the game state with card details
            $gameState = GameService::hydrateGameState($game);

            return JsonResponse::success($response, ['game' => $gameState]);

        } catch (GameNotFoundException $e) {
            return JsonResponse::notFound($response, $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
