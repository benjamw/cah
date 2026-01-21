<?php

declare(strict_types=1);

namespace CAH\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS Middleware
 *
 * Handles Cross-Origin Resource Sharing for mobile clients
 */
class CorsMiddleware implements MiddlewareInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'exposed_headers' => [],
            'max_age' => 86400, // 24 hours
            'credentials' => false,
        ], $config);
    }

    /**
     * Process CORS headers
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Slim\Psr7\Response();
            return $this->addCorsHeaders($response, $request);
        }

        $response = $handler->handle($request);

        return $this->addCorsHeaders($response, $request);
    }

    /**
     * Add CORS headers to response
     *
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    private function addCorsHeaders(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): ResponseInterface {
        $origin = $this->getOrigin($request);

        if ($origin !== null) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config['allowed_methods']))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config['allowed_headers']))
            ->withHeader('Access-Control-Max-Age', (string) $this->config['max_age']);

        if ( ! empty($this->config['exposed_headers'])) {
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                implode(', ', $this->config['exposed_headers'])
            );
        }

        if ($this->config['credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Get the allowed origin for the request
     *
     * @param ServerRequestInterface $request
     * @return string|null
     */
    private function getOrigin(ServerRequestInterface $request): ?string
    {
        $requestOrigin = $request->getHeaderLine('Origin');

        if (empty($requestOrigin)) {
            return null;
        }

        if (in_array('*', $this->config['allowed_origins'], true)) {
            return '*';
        }

        if (in_array($requestOrigin, $this->config['allowed_origins'], true)) {
            return $requestOrigin;
        }

        return null;
    }
}
