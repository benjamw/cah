<?php

declare(strict_types=1);

namespace CAH\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Session Middleware
 *
 * Starts PHP sessions for all requests to enable session-based authentication
 */
class SessionMiddleware implements MiddlewareInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'name' => 'CAH_SESSION',
            'lifetime' => 86400, // 24 hours
            'path' => '/',
            'domain' => '',
            'secure' => false, // Set to true in production with HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ], $config);
    }

    /**
     * Process request and start session
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            $this->configureSession();
            session_start();
        }

        return $handler->handle($request);
    }

    /**
     * Configure session settings
     */
    private function configureSession(): void
    {
        ini_set('session.name', $this->config['name']);
        ini_set('session.cookie_lifetime', (string) $this->config['lifetime']);
        ini_set('session.cookie_path', $this->config['path']);
        ini_set('session.cookie_domain', $this->config['domain']);
        ini_set('session.cookie_secure', $this->config['secure'] ? '1' : '0');
        ini_set('session.cookie_httponly', $this->config['httponly'] ? '1' : '0');
        ini_set('session.cookie_samesite', $this->config['samesite']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
    }
}
