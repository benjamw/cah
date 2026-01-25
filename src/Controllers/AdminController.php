<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Constants\RateLimitAction;
use CAH\Services\AdminAuthService;
use CAH\Services\RateLimitService;
use CAH\Exceptions\ValidationException;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;

/**
 * Admin Controller
 *
 * Handles admin authentication (login/logout)
 * Other admin operations split into:
 * - AdminCardController (cards, imports, card tags)
 * - AdminTagController (tag management)
 * - AdminGameController (game management)
 */
class AdminController
{
    /**
     * Admin login
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['password'] ?? null, 'password');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $clientIp = RateLimitService::getClientIp($request);

            // Check rate limit for admin login attempts (prevent brute force)
            $rateLimitConfig = [
                'max_attempts' => 5,
                'window_minutes' => 15,
                'lockout_minutes' => 30,
            ];
            $check = RateLimitService::check($clientIp, RateLimitAction::ADMIN_LOGIN, $rateLimitConfig);

            if ( ! $check['allowed']) {
                return JsonResponse::rateLimitExceeded(
                    $response,
                    'Too many login attempts. Please try again later.',
                    $check['retry_after']
                );
            }

            $userAgent = $request->getHeaderLine('User-Agent');

            $result = AdminAuthService::login(
                $data['password'],
                $clientIp,
                $userAgent
            );

            if ($result === null) {
                // Record failed login attempt
                RateLimitService::recordAttempt($clientIp, RateLimitAction::ADMIN_LOGIN);
                return JsonResponse::error($response, 'Invalid password', 401);
            }

            // Successful login - clear rate limit attempts
            RateLimitService::clearAttempts($clientIp, RateLimitAction::ADMIN_LOGIN);

            return JsonResponse::success($response, [
                'token' => $result['token'],
                'expires_at' => $result['expires_at'],
            ]);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Admin logout
     */
    public function logout(Request $request, Response $response): Response
    {
        try {
            // Token is validated by AdminAuthMiddleware and stored in request attribute
            $token = $request->getAttribute('admin_token');

            if ( ! $token) {
                return JsonResponse::error($response, 'No token provided', 401);
            }

            $success = AdminAuthService::logout($token);

            if ($success) {
                return JsonResponse::success($response, ['message' => 'Logged out successfully']);
            }

            return JsonResponse::error($response, 'Failed to logout', 500);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
