<?php

declare(strict_types=1);

namespace CAH\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use CAH\Services\AdminAuthService;
use CAH\Utils\Response as JsonResponse;
use Slim\Psr7\Response;

/**
 * Admin Authentication Middleware
 *
 * Verifies admin token from Authorization header
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Get token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            $response = new Response();
            return JsonResponse::error($response, 'Missing Authorization header', 401);
        }

        // Extract token (supports "Bearer <token>" or just "<token>")
        $token = $authHeader;
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // Verify token
        if ( ! AdminAuthService::verifyToken($token)) {
            $response = new Response();
            return JsonResponse::error($response, 'Invalid or expired admin token', 401);
        }

        // Token is valid, continue to controller
        return $handler->handle($request);
    }
}
