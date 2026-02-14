<?php

declare(strict_types=1);

namespace CAH\Middleware;

use CAH\Services\CsrfService;
use CAH\Utils\Response as JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * CSRF Protection Middleware
 *
 * Validates CSRF tokens for state-changing requests (POST, PUT, PATCH, DELETE)
 * Token can be provided in:
 * - X-CSRF-Token header
 * - POST body 'csrf_token' field
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int, string> $exemptPaths Paths that don't require CSRF protection (e.g., ['/api/admin/login'])
     */
    public function __construct(private readonly array $exemptPaths = [])
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Only check CSRF for state-changing methods
        $requiresCsrf = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        // Skip if method doesn't require CSRF or path is exempt
        if ( ! $requiresCsrf || in_array($path, $this->exemptPaths, true)) {
            return $handler->handle($request);
        }

        // Get token from header or body
        $token = $request->getHeaderLine('X-CSRF-Token');

        if ($token === '' || $token === '0') {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ( $body['csrf_token'] ?? null ) : null;
        }

        // Validate token
        if ( ! CsrfService::validateToken($token)) {
            $response = new Response();
            return JsonResponse::error(
                $response,
                'CSRF token validation failed',
                403
            );
        }

        return $handler->handle($request);
    }
}
