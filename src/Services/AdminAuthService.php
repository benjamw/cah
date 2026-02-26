<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Database\Database;
use CAH\Utils\Logger;

/**
 * Admin Authentication Service
 *
 * Handles admin login, token generation, and session management
 */
class AdminAuthService
{
    /**
     * Verify admin password and create session token
     *
     * @param string $password Password to verify
     * @param string $ipAddress Client IP address
     * @param string|null $userAgent Client user agent
     * @return array<string, string>|null ['token' => string, 'expires_at' => string] or null on failure
     */
    public static function login(string $password, string $ipAddress, ?string $userAgent = null): ?array
    {
        $adminPasswordHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH');

        if (empty($adminPasswordHash)) {
            Logger::error('Admin login attempted but ADMIN_PASSWORD_HASH is not configured');
            throw new \Exception('Admin password not configured');
        }

        // Verify password
        if ( ! password_verify($password, (string) $adminPasswordHash)) {
            Logger::warning('Admin login failed: invalid password', ['ip' => $ipAddress]);
            return null;
        }

        // Generate secure random token
        $token = bin2hex(random_bytes(32)); // 64 character hex string

        // Token expires in SESSION_LIFETIME hours
        // TODO: update this to use the config value
        $expiresAt = new \DateTime('+24 hours');

        // Store session in database
        $sql = "INSERT INTO admin_sessions (token, ip_address, user_agent, expires_at)
                VALUES (:token, :ip_address, :user_agent, :expires_at)";

        Database::execute($sql, [
            'token' => $token,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        Logger::info('Admin login successful', ['ip' => $ipAddress]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Verify admin token
     *
     * @param string $token Token to verify
     * @return bool True if valid and not expired
     */
    public static function verifyToken(string $token): bool
    {
        $sql = "
            SELECT session_id, expires_at
            FROM admin_sessions
            WHERE token = :token
            AND expires_at > NOW()
            LIMIT 1
        ";

        $session = Database::fetchOne($sql, ['token' => $token]);

        return $session !== false;
    }

    /**
     * Logout (invalidate token)
     *
     * @param string $token Token to invalidate
     * @return bool True if token was found and deleted
     */
    public static function logout(string $token): bool
    {
        $sql = "DELETE FROM admin_sessions WHERE token = :token";
        $affected = Database::execute($sql, ['token' => $token]);
        if ($affected > 0) {
            Logger::info('Admin logout successful');
        }
        return $affected > 0;
    }

    /**
     * Clean up expired sessions
     *
     * @return int Number of sessions deleted
     */
    public static function cleanupExpiredSessions(): int
    {
        $sql = "DELETE FROM admin_sessions WHERE expires_at <= NOW()";
        return Database::execute($sql);
    }

    /**
     * Get all active sessions (for debugging/admin panel)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getActiveSessions(): array
    {
        $sql = "
            SELECT session_id, ip_address, user_agent, created_at, expires_at
            FROM admin_sessions
            WHERE expires_at > NOW()
            ORDER BY created_at DESC
        ";

        return Database::fetchAll($sql);
    }

    /**
     * Revoke all sessions (logout all admins)
     *
     * @return int Number of sessions deleted
     */
    public static function revokeAllSessions(): int
    {
        $sql = "DELETE FROM admin_sessions";
        return Database::execute($sql);
    }
}
