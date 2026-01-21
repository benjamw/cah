<?php

declare(strict_types=1);

namespace CAH\Middleware;

use CAH\Constants\SessionKeys;
use CAH\Utils\Response as JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Auth Middleware
 *
 * Validates that the user has an active session with player_id and game_id
 * Protects endpoints that require authentication
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Process request and validate session
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Check if session has required data
        if ( ! isset($_SESSION[SessionKeys::PLAYER_ID]) || ! isset($_SESSION[SessionKeys::GAME_ID])) {
            $response = new Response();
            return JsonResponse::unauthorized(
                $response,
                'No active session. Please create or join a game first.'
            );
        }

        // Add session data to request attributes for easy access in controllers
        $request = $request
            ->withAttribute(SessionKeys::PLAYER_ID, $_SESSION[SessionKeys::PLAYER_ID])
            ->withAttribute(SessionKeys::GAME_ID, $_SESSION[SessionKeys::GAME_ID]);

        return $handler->handle($request);
    }
}
