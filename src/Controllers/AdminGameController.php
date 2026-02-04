<?php

declare(strict_types=1);

namespace CAH\Controllers;

use CAH\Exceptions\ValidationException;
use CAH\Models\Game;
use CAH\Utils\Response as JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin Game Controller
 *
 * Handles admin operations for games: listing, deleting
 */
class AdminGameController
{
    /**
     * Delete a game
     *
     * @param array<string, string> $args Route arguments
     */
    public function deleteGame(Request $request, Response $response, array $args): Response
    {
        try {
            $gameId = $args['gameId'];

            if ( ! Game::exists($gameId)) {
                return JsonResponse::notFound($response, 'Game not found');
            }

            $affected = Game::delete($gameId);

            return JsonResponse::success($response, [
                'game_id' => $gameId,
                'deleted' => $affected > 0,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * List all games with optional filtering
     *
     * Query parameters:
     * - state: filter by game state (optional)
     * - limit: number of games to return (optional, default: 50)
     * - offset: pagination offset (optional, default: 0)
     */
    public function listGames(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();

            $state = $queryParams['state'] ?? null;
            $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 50;
            $offset = isset($queryParams['offset']) ? (int) $queryParams['offset'] : 0;

            // Validate parameters
            if ($limit < 0 || $limit > 10000) {
                throw new ValidationException('Limit must be between 0 and 10000 (0 = no limit)');
            }

            if ($offset < 0) {
                throw new ValidationException('Offset must be non-negative');
            }

            // Build query
            $sql = "SELECT * FROM games";
            $params = [];
            $conditions = [];

            // Add state filter if provided
            if ($state !== null) {
                // State is stored in player_data JSON, so we need to extract it
                $conditions[] = "JSON_EXTRACT(player_data, '$.state') = ?";
                $params[] = $state;
            }

            // Add WHERE clause
            if ( ! empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            // Add ordering and pagination
            $sql .= " ORDER BY created_at DESC";

            // Only add LIMIT if limit > 0 (0 means no limit)
            if ($limit > 0) {
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
            }

            $games = \CAH\Database\Database::fetchAll($sql, $params);

            // Parse JSON fields for each game
            foreach ($games as &$game) {
                $game['tags'] = json_decode((string) $game['tags'], true);
                $game['draw_pile'] = json_decode((string) $game['draw_pile'], true);
                $game['discard_pile'] = json_decode((string) $game['discard_pile'], true);
                $game['player_data'] = json_decode((string) $game['player_data'], true);
            }

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM games";
            $countParams = [];

            if ($state !== null) {
                $countSql .= " WHERE JSON_EXTRACT(player_data, '$.state') = ?";
                $countParams[] = $state;
            }

            $countResult = \CAH\Database\Database::fetchOne($countSql, $countParams);
            $total = (int) ( $countResult['total'] ?? 0 );

            return JsonResponse::success($response, [
                'games' => $games,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Get round history for a game
     *
     * @param array<string, string> $args Route arguments
     */
    public function getGameHistory(Request $request, Response $response, array $args): Response
    {
        try {
            $gameId = $args['gameId'];

            $history = Game::getRoundHistory($gameId);

            if ($history === null) {
                return JsonResponse::notFound($response, 'Game not found');
            }

            return JsonResponse::success($response, [
                'game_id' => $gameId,
                'history' => $history,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
