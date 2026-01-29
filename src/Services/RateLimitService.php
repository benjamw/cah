<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Constants\GameDefaults;
use CAH\Database\Database;

/**
 * Rate Limit Service
 *
 * Prevents brute force attacks by limiting request frequency per IP/action
 */
class RateLimitService
{
    /**
     * Check if an action is rate limited for an IP
     *
     * @param string $ipAddress Client IP address
     * @param string $action Action being performed (e.g., 'create_game', 'join_game')
     * @param array<string, int> $config Rate limit config ['max_attempts', 'window_minutes', 'lockout_minutes']
     * @return array{allowed: bool, retry_after: int|null}
     */
    public static function check(string $ipAddress, string $action, array $config): array
    {
        $maxAttempts = $config['max_attempts'] ?? GameDefaults::DEFAULT_RATE_LIMIT_MAX_ATTEMPTS;
        $windowMinutes = $config['window_minutes'] ?? GameDefaults::DEFAULT_RATE_LIMIT_WINDOW_MINUTES;
        $lockoutMinutes = $config['lockout_minutes'] ?? GameDefaults::DEFAULT_RATE_LIMIT_LOCKOUT_MINUTES;

        // Check for existing rate limit record
        $sql = "
            SELECT attempts, first_attempt, locked_until
            FROM rate_limits
            WHERE ip_address = ? AND action = ?
        ";
        $record = Database::fetchOne($sql, [$ipAddress, $action]);

        if ($record) {
            // Check if currently locked out
            if ($record['locked_until'] !== null) {
                $lockedUntil = strtotime((string) $record['locked_until']);
                if ($lockedUntil > time()) {
                    return [
                        'allowed' => false,
                        'retry_after' => $lockedUntil - time(),
                    ];
                }
                // Lockout expired, reset the record
                self::reset($ipAddress, $action);
                return ['allowed' => true, 'retry_after' => null];
            }

            // Check if window has expired
            $firstAttempt = strtotime((string) $record['first_attempt']);
            $windowExpiry = $firstAttempt + ( $windowMinutes * GameDefaults::SECONDS_PER_MINUTE );

            if (time() > $windowExpiry) {
                // Window expired, reset and allow
                self::reset($ipAddress, $action);
                return ['allowed' => true, 'retry_after' => null];
            }

            // Check if max attempts exceeded
            if ($record['attempts'] >= $maxAttempts) {
                // Lock out the IP
                self::lockout($ipAddress, $action, $lockoutMinutes);
                return [
                    'allowed' => false,
                    'retry_after' => $lockoutMinutes * GameDefaults::SECONDS_PER_MINUTE,
                ];
            }
        }

        return ['allowed' => true, 'retry_after' => null];
    }

    /**
     * Record an attempt for rate limiting
     *
     * @param string $ipAddress Client IP address
     * @param string $action Action being performed
     */
    public static function recordAttempt(string $ipAddress, string $action): void
    {
        $sql = "
            INSERT INTO rate_limits (ip_address, action, attempts, first_attempt, last_attempt)
            VALUES (?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                attempts = attempts + 1,
                last_attempt = NOW()
        ";
        Database::execute($sql, [$ipAddress, $action]);
    }

    /**
     * Reset rate limit for an IP/action
     *
     * @param string $ipAddress Client IP address
     * @param string $action Action being performed
     */
    public static function reset(string $ipAddress, string $action): void
    {
        $sql = "DELETE FROM rate_limits WHERE ip_address = ? AND action = ?";
        Database::execute($sql, [$ipAddress, $action]);
    }

    /**
     * Clear recorded attempts for an IP/action (alias for reset)
     *
     * @param string $ipAddress Client IP address
     * @param string $action Action being performed
     */
    public static function clearAttempts(string $ipAddress, string $action): void
    {
        self::reset($ipAddress, $action);
    }

    /**
     * Lock out an IP for a specific action
     *
     * @param string $ipAddress Client IP address
     * @param string $action Action being performed
     * @param int $lockoutMinutes Minutes to lock out
     */
    private static function lockout(string $ipAddress, string $action, int $lockoutMinutes): void
    {
        $sql = "
            UPDATE rate_limits
            SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
            WHERE ip_address = ? AND action = ?
        ";
        Database::execute($sql, [$lockoutMinutes, $ipAddress, $action]);
    }

    /**
     * Get client IP address from request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return string
     */
    public static function getClientIp(\Psr\Http\Message\ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();

        // Check for forwarded IP (behind proxy/load balancer)
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
        foreach ($headers as $header) {
            if ( ! empty($serverParams[$header])) {
                $ips = explode(',', (string) $serverParams[$header]);
                return trim($ips[0]);
            }
        }

        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
