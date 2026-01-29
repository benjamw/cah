<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Services\RoundService;
use CAH\Services\GameService;
use CAH\Models\Game;
use CAH\Exceptions\GameException;
use CAH\Exceptions\GameNotFoundException;
use CAH\Exceptions\PlayerNotFoundException;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\UnauthorizedException;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;

class RoundController
{
    /**
     * Submit cards for the current round
     */
    public function submit(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['card_ids'] ?? null, 'card_ids')
                ->array($data['card_ids'] ?? null, 'card_ids', 1);

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $cardValidation = Validator::validateCardIds($data['card_ids']);
            if ( ! $cardValidation['valid']) {
                throw new ValidationException($cardValidation['error']);
            }

            $gameState = RoundService::submitCards(
                $gameId,
                $playerId,
                $cardValidation['card_ids']
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
     * Pick winner and advance to next round
     */
    public function pickWinner(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['winner_id'] ?? null, 'winner_id');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $winnerId = $data['winner_id'];

            // Use player_id from session as czar_id
            $gameState = RoundService::pickWinner($gameId, $playerId, $winnerId);

            $winner = RoundService::checkForWinner($gameState);

            if ($winner) {
                $gameState = RoundService::endGame($gameId, $winner['id']);

                // Hydrate card IDs with full card data
                $gameState = GameService::hydrateCards($gameState);

                // Filter out other players' hands
                $gameState = GameService::filterHands($gameState, $playerId);

                return JsonResponse::success($response, [
                    'game_state' => $gameState,
                    'game_over' => true,
                    'winner' => $winner,
                ]);
            }

            // Check if order is locked - if so, auto-advance, otherwise wait for czar selection
            if ($gameState['order_locked']) {
                // Automatically determine next czar from the updated player data
                $nextCzarId = GameService::getNextCzar($gameState);

                $gameState = GameService::setNextCzar($gameId, $playerId, $nextCzarId);

                $gameState = RoundService::advanceToNextRound($gameId);
            }

            // Hydrate card IDs with full card data
            $gameState = GameService::hydrateCards($gameState);

            // Filter out other players' hands
            $gameState = GameService::filterHands($gameState, $playerId);

            return JsonResponse::success($response, [
                'game_state' => $gameState,
                'game_over' => false,
                'needs_czar_selection' => ! $gameState['order_locked'],
            ]);
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
     * Set next czar and advance to next round
     */
    public function setNextCzar(Request $request, Response $response): Response
    {
        try {
            // Get authenticated session data from request attributes (set by AuthMiddleware)
            $gameId = $request->getAttribute('game_id');
            $playerId = $request->getAttribute('player_id');

            $data = $request->getParsedBody();
            if ( ! isset($data['next_czar_id'])) {
                throw new ValidationException('next_czar_id is required');
            }

            $nextCzarId = $data['next_czar_id'];

            // Set the next czar (validates that current player is czar)
            $gameState = GameService::setNextCzar($gameId, $playerId, $nextCzarId);

            // Check if there were skipped players
            $skippedPlayers = $gameState['skipped_players'] ?? null;
            $orderLocked = $gameState['order_locked'] ?? false;

            // Advance to next round
            $gameState = RoundService::advanceToNextRound($gameId);

            // Hydrate card IDs with full card data
            $gameState = GameService::hydrateCards($gameState);

            // Filter out other players' hands
            $gameState = GameService::filterHands($gameState, $playerId);

            return JsonResponse::success($response, [
                'game_state' => $gameState,
                'skipped_players' => $skippedPlayers,
                'order_locked' => $orderLocked,
            ]);
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
}
