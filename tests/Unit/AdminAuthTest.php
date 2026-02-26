<?php

declare(strict_types=1);

namespace CAH\Tests\Unit;

use CAH\Services\AdminAuthService;
use CAH\Tests\TestCase;

class AdminAuthTest extends TestCase
{
    /**
     * Test admin login with hashed password (bcrypt)
     */
    public function testLoginWithBcryptPassword(): void
    {
        // Set up environment with hashed password
        $plainPassword = 'test_password_123';
        $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

        // Store the original value to restore later
        $originalEnvValue = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;
        getenv('ADMIN_PASSWORD_HASH');

        $_ENV['ADMIN_PASSWORD_HASH'] = $hashedPassword;
        putenv("ADMIN_PASSWORD_HASH={$hashedPassword}");

        try {
            // Test successful login
            $result = AdminAuthService::login($plainPassword, '127.0.0.1', 'Test Agent');

            $this->assertNotNull($result);
            $this->assertArrayHasKey('token', $result);
            $this->assertArrayHasKey('expires_at', $result);
            $this->assertEquals(64, strlen($result['token'])); // 32 bytes = 64 hex chars

            // Test that token is valid
            $isValid = AdminAuthService::verifyToken($result['token']);
            $this->assertTrue($isValid);

            // Test failed login with wrong password
            $failedResult = AdminAuthService::login('wrong_password', '127.0.0.1', 'Test Agent');
            $this->assertNull($failedResult);
        } finally {
            // Restore original environment
            if ($originalEnvValue !== null) {
                $_ENV['ADMIN_PASSWORD_HASH'] = $originalEnvValue;
                putenv("ADMIN_PASSWORD_HASH={$originalEnvValue}");
            } else {
                unset($_ENV['ADMIN_PASSWORD_HASH']);
                putenv('ADMIN_PASSWORD_HASH');
            }
        }
    }

    /**
     * Test admin login with Argon2 password
     */
    public function testLoginWithArgon2Password(): void
    {
        // Skip if Argon2 is not available
        if ( ! defined('PASSWORD_ARGON2I')) {
            $this->markTestSkipped('Argon2 not available in this PHP version');
        }

        $plainPassword = 'test_password_argon';
        $hashedPassword = password_hash($plainPassword, PASSWORD_ARGON2I);

        $originalEnvValue = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;
        $_ENV['ADMIN_PASSWORD_HASH'] = $hashedPassword;
        putenv("ADMIN_PASSWORD_HASH={$hashedPassword}");

        try {
            $result = AdminAuthService::login($plainPassword, '127.0.0.1', 'Test Agent');

            $this->assertNotNull($result);
            $this->assertArrayHasKey('token', $result);
        } finally {
            if ($originalEnvValue !== null) {
                $_ENV['ADMIN_PASSWORD_HASH'] = $originalEnvValue;
                putenv("ADMIN_PASSWORD_HASH={$originalEnvValue}");
            } else {
                unset($_ENV['ADMIN_PASSWORD_HASH']);
                putenv('ADMIN_PASSWORD_HASH');
            }
        }
    }

    /**
     * Test that unconfigured password throws exception
     */
    public function testUnconfiguredPasswordThrowsException(): void
    {
        $originalEnvValue = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;
        unset($_ENV['ADMIN_PASSWORD_HASH']);
        putenv('ADMIN_PASSWORD_HASH');

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Admin password not configured');

            AdminAuthService::login('any_password', '127.0.0.1', 'Test Agent');
        } finally {
            if ($originalEnvValue !== null) {
                $_ENV['ADMIN_PASSWORD_HASH'] = $originalEnvValue;
                putenv("ADMIN_PASSWORD_HASH={$originalEnvValue}");
            }
        }
    }

    /**
     * Test logout invalidates token
     */
    public function testLogoutInvalidatesToken(): void
    {
        $plainPassword = 'test_password_logout';
        $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

        $originalEnvValue = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;
        $_ENV['ADMIN_PASSWORD_HASH'] = $hashedPassword;
        putenv("ADMIN_PASSWORD_HASH={$hashedPassword}");

        try {
            // Login
            $result = AdminAuthService::login($plainPassword, '127.0.0.1', 'Test Agent');
            $this->assertNotNull($result, 'Login should succeed');
            $token = $result['token'];

            // Verify token is valid
            $this->assertTrue(AdminAuthService::verifyToken($token));

            // Logout
            $loggedOut = AdminAuthService::logout($token);
            $this->assertTrue($loggedOut);

            // Verify token is no longer valid
            $this->assertFalse(AdminAuthService::verifyToken($token));
        } finally {
            if ($originalEnvValue !== null) {
                $_ENV['ADMIN_PASSWORD_HASH'] = $originalEnvValue;
                putenv("ADMIN_PASSWORD_HASH={$originalEnvValue}");
            } else {
                unset($_ENV['ADMIN_PASSWORD_HASH']);
                putenv('ADMIN_PASSWORD_HASH');
            }
        }
    }

    /**
     * Test session cleanup
     */
    public function testCleanupExpiredSessions(): void
    {
        $plainPassword = 'test_password_cleanup';
        $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

        $originalEnvValue = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;
        $_ENV['ADMIN_PASSWORD_HASH'] = $hashedPassword;
        putenv("ADMIN_PASSWORD_HASH={$hashedPassword}");

        try {
            // Create a session
            $result = AdminAuthService::login($plainPassword, '127.0.0.1', 'Test Agent');
            $this->assertNotNull($result, 'Login should succeed');
            $token = $result['token'];

            // Manually expire the session in database
            \CAH\Database\Database::execute(
                "UPDATE admin_sessions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE token = ?",
                [$token]
            );

            // Verify token is no longer valid
            $this->assertFalse(AdminAuthService::verifyToken($token));

            // Clean up expired sessions
            $deleted = AdminAuthService::cleanupExpiredSessions();
            $this->assertGreaterThanOrEqual(1, $deleted);

        } finally {
            if ($originalEnvValue !== null) {
                $_ENV['ADMIN_PASSWORD_HASH'] = $originalEnvValue;
                putenv("ADMIN_PASSWORD_HASH={$originalEnvValue}");
            } else {
                unset($_ENV['ADMIN_PASSWORD_HASH']);
                putenv('ADMIN_PASSWORD_HASH');
            }
        }
    }
}
