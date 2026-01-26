<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Services\GameService;
use CAH\Exceptions\GameException;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\UnauthorizedException;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;

class PlayerController
{
    /**
     * Remove a player from the game
     */
    public function remove(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['target_player_id'] ?? null, 'target_player_id');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $gameState = GameService::removePlayer(
                $gameId,
                $playerId,
                $data['target_player_id']
            );

            // Hydrate card IDs with full card data
            $gameState = GameService::hydrateCards($gameState);
            
            // Filter out other players' hands
            $gameState = GameService::filterHands($gameState, $playerId);

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
     * Transfer host to another player
     */
    public function transferHost(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['new_host_id'] ?? null, 'new_host_id');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $removeCurrentHost = $data['remove_current_host'] ?? false;

            $gameState = GameService::transferHost(
                $gameId,
                $playerId,
                $data['new_host_id'],
                $removeCurrentHost
            );

            // Hydrate card IDs with full card data
            $gameState = GameService::hydrateCards($gameState);
            
            // Filter out other players' hands
            $gameState = GameService::filterHands($gameState, $playerId);

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
     * Leave game (removes current player)
     */
    public function leave(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $gameState = GameService::leaveGame($gameId, $playerId);

            // Hydrate card IDs with full card data
            $gameState = GameService::hydrateCards($gameState);
            
            // Filter out other players' hands
            $gameState = GameService::filterHands($gameState, $playerId);

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
}
