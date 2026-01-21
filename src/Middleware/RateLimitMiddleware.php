<?php

declare(strict_types=1);

namespace CAH\Middleware;

use CAH\Constants\RateLimitAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use CAH\Services\RateLimitService;
use CAH\Utils\Response as JsonResponse;
use Slim\Psr7\Response;

/**
 * Rate Limit Middleware
 *
 * Two types of rate limiting:
 * 1. Fail2ban-style: tracks 404s on all /api/ endpoints, locks out after too many failures
 * 2. Hard rate limit: limits requests to create_game regardless of success/failure
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private readonly array $failedConfig;
    private readonly array $createConfig;

    public function __construct(array $rateLimitConfig = [])
    {
        $this->failedConfig = array_merge([
            'max_attempts' => 10,
            'window_minutes' => 5,
            'lockout_minutes' => 5,
        ], $rateLimitConfig[RateLimitAction::FAILED_GAME_CODE] ?? []);

        $this->createConfig = array_merge([
            'max_attempts' => 10,
            'window_minutes' => 5,
            'lockout_minutes' => 10,
        ], $rateLimitConfig[RateLimitAction::CREATE_GAME] ?? []);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $clientIp = RateLimitService::getClientIp($request);
        $path = $request->getUri()->getPath();
        $isApiEndpoint = str_starts_with($path, '/api/');
        $isCreateGame = $path === '/api/game/create' && $request->getMethod() === 'POST';

        // Check if IP is locked out from too many failed attempts
        if ($isApiEndpoint) {
            $check = RateLimitService::check($clientIp, RateLimitAction::FAILED_GAME_CODE, $this->failedConfig);

            if ( ! $check['allowed']) {
                $response = new Response();
                return JsonResponse::rateLimitExceeded(
                    $response,
                    'Too many failed attempts. Please try again later.',
                    $check['retry_after']
                );
            }
        }

        // Hard rate limit for game creation
        if ($isCreateGame) {
            $check = RateLimitService::check($clientIp, RateLimitAction::CREATE_GAME, $this->createConfig);

            if ( ! $check['allowed']) {
                $response = new Response();
                return JsonResponse::rateLimitExceeded(
                    $response,
                    'Too many game creation requests. Please try again later.',
                    $check['retry_after']
                );
            }

            // Record attempt before processing (hard limit)
            RateLimitService::recordAttempt($clientIp, RateLimitAction::CREATE_GAME);
        }

        // Process request
        $response = $handler->handle($request);

        // Fail2ban: Record failed attempt if 404 on any API endpoint
        if ($isApiEndpoint && $response->getStatusCode() === 404) {
            RateLimitService::recordAttempt($clientIp, RateLimitAction::FAILED_GAME_CODE);
        }

        return $response;
    }
}
