<?php

declare(strict_types=1);

namespace CAH\Services;

/**
 * CSRF Protection Service
 * 
 * Generates and validates CSRF tokens for session-based authentication
 */
class CsrfService
{
    /**
     * Generate a CSRF token and store it in the session
     *
     * @return string The generated CSRF token
     */
    public static function generateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        
        return $token;
    }

    /**
     * Get the current CSRF token from the session
     *
     * @return string|null The CSRF token or null if not set
     */
    public static function getToken(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return $_SESSION['csrf_token'] ?? null;
    }

    /**
     * Validate a CSRF token
     *
     * @param string|null $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        $sessionToken = self::getToken();
        
        if ($sessionToken === null) {
            return false;
        }

        // Use hash_equals to prevent timing attacks
        return hash_equals($sessionToken, $token);
    }

    /**
     * Regenerate the CSRF token (e.g., after login)
     *
     * @return string The new CSRF token
     */
    public static function regenerateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['csrf_token']);
        return self::generateToken();
    }
}
