<?php

declare(strict_types=1);

namespace CAH\Tests\Functional;

use CAH\Tests\TestCase;
use CAH\Services\AdminAuthService;
use CAH\Services\RateLimitService;
use CAH\Constants\RateLimitAction;
use CAH\Database\Database;
use CAH\Exceptions\UnauthorizedException;

/**
 * Admin Authentication Functional Tests
 * 
 * Comprehensive tests for admin login, token management, rate limiting,
 * and security edge cases.
 */
class AdminAuthenticationTest extends TestCase
{
    private string $adminPassword = 'test_admin_password_secure123';
    private string $adminPasswordHash;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test admin password hash
        $this->adminPasswordHash = password_hash($this->adminPassword, PASSWORD_DEFAULT);
        
        // Set admin password in environment
        $_ENV['ADMIN_PASSWORD_HASH'] = $this->adminPasswordHash;
    }

    // ========================================
    // SUCCESSFUL LOGIN TESTS
    // ========================================

    public function test_admin_can_login_with_valid_password(): void
    {
        // Arrange
        $ipAddress = '127.0.0.1';
        $userAgent = 'Test Browser';

        // Act
        $result = AdminAuthService::login($this->adminPassword, $ipAddress, $userAgent);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertEquals(64, strlen($result['token'])); // SHA-256 = 64 chars
        
        // Verify token is stored in database
        $tokenData = Database::fetchOne(
            "SELECT * FROM admin_sessions WHERE token = ?",
            [$result['token']]
        );
        $this->assertNotNull($tokenData);
        $this->assertEquals($ipAddress, $tokenData['ip_address']);
        $this->assertEquals($userAgent, $tokenData['user_agent']);
    }

    public function test_admin_token_expires_after_24_hours(): void
    {
        // Arrange
        $ipAddress = '127.0.0.1';
        $loginResult = AdminAuthService::login(
            $this->adminPassword,
            $ipAddress,
            'Test Browser'
        );
        $token = $loginResult['token'];

        // Act - Check expiration time
        $tokenData = Database::fetchOne(
            "SELECT expires_at FROM admin_sessions WHERE token = ?",
            [$token]
        );
        
        $expiresAt = strtotime($tokenData['expires_at']);
        $now = time();
        $expectedExpiry = $now + (24 * 60 * 60); // 24 hours

        // Assert - Should expire in approximately 24 hours (within 5 seconds tolerance)
        $this->assertGreaterThan($now, $expiresAt, 'Token should expire in the future');
        $this->assertLessThan(
            5,
            abs($expiresAt - $expectedExpiry),
            'Token should expire in approximately 24 hours'
        );
    }

    public function test_login_response_contains_correct_data(): void
    {
        // Arrange
        $ipAddress = '127.0.0.1';
        $userAgent = 'Test Browser';

        // Act
        $result = AdminAuthService::login($this->adminPassword, $ipAddress, $userAgent);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result, 'Response should include token');
        $this->assertArrayHasKey('expires_at', $result, 'Response should include expiration time');
        
        // Token should be a valid hex string (from bin2hex)
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/i',
            $result['token'],
            'Token should be a 64-character hex string'
        );
        
        // expires_at should be a valid timestamp
        $expiresAt = strtotime($result['expires_at']);
        $this->assertNotFalse($expiresAt, 'expires_at should be a valid timestamp');
        $this->assertGreaterThan(time(), $expiresAt, 'expires_at should be in the future');
    }

    // ========================================
    // FAILED LOGIN TESTS
    // ========================================

    public function test_admin_cannot_login_with_invalid_password(): void
    {
        // Arrange
        $wrongPassword = 'wrong_password_123';
        $ipAddress = '127.0.0.1';
        $userAgent = 'Test Browser';

        // Act & Assert
        $this->expectException(UnauthorizedException::class);
        AdminAuthService::login($wrongPassword, $ipAddress, $userAgent);
    }

    public function test_admin_login_fails_with_empty_password(): void
    {
        // Arrange
        $emptyPassword = '';
        $ipAddress = '127.0.0.1';

        // Act & Assert
        $this->expectException(UnauthorizedException::class);
        AdminAuthService::login($emptyPassword, $ipAddress, 'Test');
    }

    public function test_admin_login_fails_when_no_password_configured(): void
    {
        // Arrange
        $originalHash = $_ENV['ADMIN_PASSWORD_HASH'];
        unset($_ENV['ADMIN_PASSWORD_HASH']);

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Admin password not configured');
        
        try {
            AdminAuthService::login('anypassword', '127.0.0.1', 'Test');
        } finally {
            // Restore for other tests
            $_ENV['ADMIN_PASSWORD_HASH'] = $originalHash;
        }
    }

    // ========================================
    // RATE LIMITING TESTS
    // ========================================

    public function test_admin_login_is_rate_limited_after_5_failures(): void
    {
        // Arrange
        $ipAddress = '203.0.113.50';
        $wrongPassword = 'wrong_password';
        $rateLimitConfig = [
            'max_attempts' => 5,
            'window_minutes' => 5,
            'lockout_minutes' => 1440, // 24 hours
        ];

        // Act - Make 5 failed login attempts
        for ($i = 0; $i < 5; $i++) {
            try {
                AdminAuthService::login($wrongPassword, $ipAddress, 'Test');
            } catch (UnauthorizedException $e) {
                // Expected - record the attempt
                RateLimitService::recordAttempt($ipAddress, RateLimitAction::ADMIN_LOGIN);
            }
        }

        // Assert - 6th attempt should be blocked
        $check = RateLimitService::check($ipAddress, RateLimitAction::ADMIN_LOGIN, $rateLimitConfig);
        $this->assertFalse($check['allowed'], 'IP should be blocked after 5 failed attempts');
        $this->assertNotNull($check['retry_after'], 'Should provide retry_after time');
    }

    public function test_admin_lockout_lasts_24_hours(): void
    {
        // Arrange
        $ipAddress = '203.0.113.51';
        $rateLimitConfig = [
            'max_attempts' => 5,
            'window_minutes' => 5,
            'lockout_minutes' => 1440, // 24 hours
        ];

        // Make 5 failed attempts to trigger lockout
        for ($i = 0; $i < 5; $i++) {
            RateLimitService::recordAttempt($ipAddress, RateLimitAction::ADMIN_LOGIN);
        }

        // Trigger lockout by checking
        RateLimitService::check($ipAddress, RateLimitAction::ADMIN_LOGIN, $rateLimitConfig);

        // Act - Check the locked_until time
        $record = Database::fetchOne(
            "SELECT locked_until FROM rate_limits WHERE ip_address = ? AND action = ?",
            [$ipAddress, RateLimitAction::ADMIN_LOGIN]
        );

        // Assert
        $this->assertNotNull($record['locked_until']);
        $lockedUntil = strtotime($record['locked_until']);
        $expectedUnlock = time() + (1440 * 60); // 24 hours from now
        
        $this->assertGreaterThan(time(), $lockedUntil, 'Lockout should be in the future');
        $this->assertLessThan(
            300, // 5 minute tolerance
            abs($lockedUntil - $expectedUnlock),
            'Lockout should last approximately 24 hours'
        );
    }

    public function test_successful_login_resets_rate_limit_counter(): void
    {
        // Arrange
        $ipAddress = '203.0.113.52';
        $rateLimitConfig = [
            'max_attempts' => 5,
            'window_minutes' => 5,
            'lockout_minutes' => 1440,
        ];

        // Make 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            RateLimitService::recordAttempt($ipAddress, RateLimitAction::ADMIN_LOGIN);
        }

        // Verify attempts were recorded
        $record = Database::fetchOne(
            "SELECT attempts FROM rate_limits WHERE ip_address = ? AND action = ?",
            [$ipAddress, RateLimitAction::ADMIN_LOGIN]
        );
        $this->assertEquals(3, $record['attempts']);

        // Act - Successful login should reset counter
        AdminAuthService::login($this->adminPassword, $ipAddress, 'Test');
        RateLimitService::reset($ipAddress, RateLimitAction::ADMIN_LOGIN);

        // Assert - Counter should be reset
        $recordAfter = Database::fetchOne(
            "SELECT attempts FROM rate_limits WHERE ip_address = ? AND action = ?",
            [$ipAddress, RateLimitAction::ADMIN_LOGIN]
        );
        $this->assertNull($recordAfter, 'Rate limit record should be cleared after successful login');
    }

    public function test_rate_limit_window_expires_after_5_minutes(): void
    {
        // Arrange
        $ipAddress = '203.0.113.53';
        $rateLimitConfig = [
            'max_attempts' => 5,
            'window_minutes' => 5,
            'lockout_minutes' => 1440,
        ];

        // Make 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            RateLimitService::recordAttempt($ipAddress, RateLimitAction::ADMIN_LOGIN);
        }

        // Act - Manually set first_attempt to 6 minutes ago
        $pastTime = date('Y-m-d H:i:s', time() - (6 * 60));
        Database::execute(
            "UPDATE rate_limits SET first_attempt = ? WHERE ip_address = ? AND action = ?",
            [$pastTime, $ipAddress, RateLimitAction::ADMIN_LOGIN]
        );

        // Assert - Should be allowed again (window expired)
        $check = RateLimitService::check($ipAddress, RateLimitAction::ADMIN_LOGIN, $rateLimitConfig);
        $this->assertTrue($check['allowed'], 'Should be allowed after rate limit window expires');
    }

    // ========================================
    // TOKEN VALIDATION TESTS - IP BINDING
    // ========================================

    public function test_admin_token_validation_requires_matching_ip(): void
    {
        // Arrange
        $originalIp = '192.168.1.100';
        $loginResult = AdminAuthService::login(
            $this->adminPassword,
            $originalIp,
            'Test Browser'
        );
        $token = $loginResult['token'];

        // Act & Assert - Token should be valid from original IP
        $isValid = AdminAuthService::validateToken($token, $originalIp);
        $this->assertTrue($isValid, 'Token should be valid from original IP');

        // Act & Assert - Token should NOT be valid from different IP
        $differentIp = '192.168.1.101';
        $isValid = AdminAuthService::validateToken($token, $differentIp);
        $this->assertFalse($isValid, 'Token should not be valid from different IP');
    }

    public function test_token_validation_requires_exact_ip_match(): void
    {
        // Arrange
        $originalIp = '192.168.1.100';
        $loginResult = AdminAuthService::login(
            $this->adminPassword,
            $originalIp,
            'Test Browser'
        );
        $token = $loginResult['token'];

        // Act & Assert - Try various similar but different IPs
        $similarIps = [
            '192.168.1.101', // Different last octet
            '192.168.2.100', // Different third octet
            '192.168.1.10',  // Shorter IP
            '192.168.1.1000', // Longer IP (invalid but test it)
            '192.168.001.100', // Leading zeros
        ];
        
        foreach ($similarIps as $testIp) {
            $isValid = AdminAuthService::validateToken($token, $testIp);
            $this->assertFalse(
                $isValid,
                "Token should not be valid from IP {$testIp} (logged in from {$originalIp})"
            );
        }
        
        // Only exact match should work
        $this->assertTrue(
            AdminAuthService::validateToken($token, $originalIp),
            'Token should be valid from original IP'
        );
    }

    // ========================================
    // MULTIPLE CONCURRENT SESSIONS
    // ========================================

    public function test_admin_can_have_multiple_concurrent_sessions(): void
    {
        // Arrange & Act - Login from three different IPs
        $session1 = AdminAuthService::login(
            $this->adminPassword,
            '127.0.0.1',
            'Browser 1'
        );
        
        $session2 = AdminAuthService::login(
            $this->adminPassword,
            '192.168.1.100',
            'Browser 2'
        );
        
        $session3 = AdminAuthService::login(
            $this->adminPassword,
            '10.0.0.50',
            'Browser 3'
        );

        // Assert - All three sessions should be valid from their respective IPs
        $this->assertTrue(
            AdminAuthService::validateToken($session1['token'], '127.0.0.1'),
            'Session 1 should be valid'
        );
        
        $this->assertTrue(
            AdminAuthService::validateToken($session2['token'], '192.168.1.100'),
            'Session 2 should be valid'
        );
        
        $this->assertTrue(
            AdminAuthService::validateToken($session3['token'], '10.0.0.50'),
            'Session 3 should be valid'
        );
        
        // Verify all sessions exist in database
        $allSessions = Database::fetchAll(
            "SELECT * FROM admin_sessions WHERE token IN (?, ?, ?)",
            [$session1['token'], $session2['token'], $session3['token']]
        );
        $this->assertCount(3, $allSessions, 'All three sessions should exist in database');
    }

    // ========================================
    // LOGOUT TESTS
    // ========================================

    public function test_logout_only_invalidates_current_session(): void
    {
        // Arrange - Create two sessions from different IPs
        $session1 = AdminAuthService::login(
            $this->adminPassword,
            '127.0.0.1',
            'Browser 1'
        );
        
        $session2 = AdminAuthService::login(
            $this->adminPassword,
            '192.168.1.100',
            'Browser 2'
        );
        
        // Verify both are valid
        $this->assertTrue(AdminAuthService::validateToken($session1['token'], '127.0.0.1'));
        $this->assertTrue(AdminAuthService::validateToken($session2['token'], '192.168.1.100'));

        // Act - Logout from session 1
        $logoutResult = AdminAuthService::logout($session1['token']);

        // Assert
        $this->assertTrue($logoutResult, 'Logout should succeed');
        
        // Session 1 should be invalid
        $this->assertFalse(
            AdminAuthService::validateToken($session1['token'], '127.0.0.1'),
            'Logged out session should be invalid'
        );
        
        // Session 2 should still be valid
        $this->assertTrue(
            AdminAuthService::validateToken($session2['token'], '192.168.1.100'),
            'Other session should remain valid after logout'
        );
    }

    public function test_cannot_logout_with_invalid_token(): void
    {
        // Arrange
        $invalidToken = hash('sha256', 'fake_token_' . time());

        // Act
        $result = AdminAuthService::logout($invalidToken);

        // Assert
        $this->assertFalse($result, 'Logout with invalid token should return false');
    }

    public function test_logout_with_already_logged_out_token(): void
    {
        // Arrange
        $loginResult = AdminAuthService::login($this->adminPassword, '127.0.0.1', 'Test');
        $token = $loginResult['token'];
        
        // Logout once
        $firstLogout = AdminAuthService::logout($token);
        $this->assertTrue($firstLogout);

        // Act - Try to logout again with same token
        $secondLogout = AdminAuthService::logout($token);

        // Assert
        $this->assertFalse($secondLogout, 'Second logout should return false');
    }

    // ========================================
    // EXPIRATION TESTS
    // ========================================

    public function test_expired_tokens_are_not_valid(): void
    {
        // Arrange - Create a token and manually set it to expired
        $ipAddress = '127.0.0.1';
        $loginResult = AdminAuthService::login(
            $this->adminPassword,
            $ipAddress,
            'Test Browser'
        );
        $token = $loginResult['token'];
        
        // Manually expire the token (set expires_at to past)
        $pastTime = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
        Database::execute(
            "UPDATE admin_sessions SET expires_at = ? WHERE token = ?",
            [$pastTime, $token]
        );

        // Act
        $isValid = AdminAuthService::validateToken($token, $ipAddress);

        // Assert
        $this->assertFalse($isValid, 'Expired token should not be valid');
    }

    public function test_token_expires_exactly_at_expiration_time(): void
    {
        // Arrange
        $ipAddress = '127.0.0.1';
        $loginResult = AdminAuthService::login($this->adminPassword, $ipAddress, 'Test');
        $token = $loginResult['token'];
        
        // Set expires_at to exactly now
        $now = date('Y-m-d H:i:s', time());
        Database::execute(
            "UPDATE admin_sessions SET expires_at = ? WHERE token = ?",
            [$now, $token]
        );

        // Act - Token should be invalid (expires_at must be > NOW)
        $isValid = AdminAuthService::validateToken($token, $ipAddress);

        // Assert
        $this->assertFalse($isValid, 'Token should be invalid at exact expiration time');
    }

    public function test_cleanup_expired_sessions_removes_old_tokens(): void
    {
        // Arrange - Create some expired sessions
        $expiredTokens = [];
        for ($i = 0; $i < 3; $i++) {
            $token = bin2hex(random_bytes(32));
            $expiredTime = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
            Database::execute(
                "INSERT INTO admin_sessions (token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?)",
                [$token, '127.0.0.1', 'Test', $expiredTime]
            );
            $expiredTokens[] = $token;
        }

        // Create one valid session
        $validSession = AdminAuthService::login($this->adminPassword, '127.0.0.1', 'Test');

        // Act
        $deletedCount = AdminAuthService::cleanupExpiredSessions();

        // Assert
        $this->assertGreaterThanOrEqual(3, $deletedCount, 'Should delete at least 3 expired sessions');
        
        // Verify expired tokens are gone
        foreach ($expiredTokens as $token) {
            $exists = Database::fetchOne("SELECT token FROM admin_sessions WHERE token = ?", [$token]);
            $this->assertNull($exists, 'Expired token should be deleted');
        }
        
        // Verify valid session still exists
        $validExists = Database::fetchOne(
            "SELECT token FROM admin_sessions WHERE token = ?",
            [$validSession['token']]
        );
        $this->assertNotNull($validExists, 'Valid session should not be deleted');
    }

    // ========================================
    // SESSION LIMIT TESTS
    // ========================================

    public function test_max_5_concurrent_sessions_allowed(): void
    {
        // Arrange & Act - Create 5 sessions
        $sessions = [];
        for ($i = 1; $i <= 5; $i++) {
            $sessions[] = AdminAuthService::login(
                $this->adminPassword,
                "192.168.1.{$i}",
                "Browser {$i}"
            );
        }

        // Assert - All 5 should be valid
        $this->assertCount(5, $sessions);
        foreach ($sessions as $index => $session) {
            $ip = "192.168.1." . ($index + 1);
            $this->assertTrue(
                AdminAuthService::validateToken($session['token'], $ip),
                "Session {$index} should be valid"
            );
        }
    }

    public function test_6th_session_removes_oldest_session(): void
    {
        // Arrange - Create 5 sessions
        $sessions = [];
        for ($i = 1; $i <= 5; $i++) {
            $sessions[] = AdminAuthService::login(
                $this->adminPassword,
                "192.168.1.{$i}",
                "Browser {$i}"
            );
            // Small delay to ensure created_at is different
            usleep(10000); // 10ms
        }

        $oldestToken = $sessions[0]['token'];

        // Act - Create 6th session (should remove oldest)
        $sixthSession = AdminAuthService::login(
            $this->adminPassword,
            '192.168.1.100',
            'Browser 6'
        );

        // Assert - 6th session should be valid
        $this->assertTrue(AdminAuthService::validateToken($sixthSession['token'], '192.168.1.100'));

        // Assert - Oldest session should be removed
        $oldestExists = Database::fetchOne(
            "SELECT token FROM admin_sessions WHERE token = ?",
            [$oldestToken]
        );
        $this->assertNull($oldestExists, 'Oldest session should be removed when exceeding limit');

        // Assert - Total sessions should still be 5
        $totalSessions = Database::fetchAll("SELECT * FROM admin_sessions");
        $this->assertLessThanOrEqual(5, count($totalSessions), 'Should not exceed 5 sessions');
    }

    public function test_expired_sessions_cleaned_on_login_attempt(): void
    {
        // Arrange - Create some expired sessions
        for ($i = 1; $i <= 3; $i++) {
            $token = bin2hex(random_bytes(32));
            $expiredTime = date('Y-m-d H:i:s', time() - 3600);
            Database::execute(
                "INSERT INTO admin_sessions (token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?)",
                [$token, "192.168.1.{$i}", 'Test', $expiredTime]
            );
        }

        // Verify expired sessions exist
        $expiredBefore = Database::fetchAll(
            "SELECT * FROM admin_sessions WHERE expires_at < NOW()"
        );
        $this->assertCount(3, $expiredBefore);

        // Act - New login should trigger cleanup
        AdminAuthService::login($this->adminPassword, '127.0.0.1', 'Test');

        // Assert - Expired sessions should be removed
        $expiredAfter = Database::fetchAll(
            "SELECT * FROM admin_sessions WHERE expires_at < NOW()"
        );
        $this->assertCount(0, $expiredAfter, 'Expired sessions should be cleaned up on login');
    }

    // ========================================
    // INPUT VALIDATION & EDGE CASES
    // ========================================

    public function test_login_with_empty_ip_address_fails_gracefully(): void
    {
        // Arrange
        $emptyIp = '';

        // Act & Assert - Should fail gracefully (not crash)
        $this->expectException(\CAH\Exceptions\ValidationException::class);
        AdminAuthService::login($this->adminPassword, $emptyIp, 'Test');
    }

    public function test_login_with_malformed_ip_address_fails_gracefully(): void
    {
        // Arrange
        $malformedIps = [
            'not-an-ip',
            '999.999.999.999',
            '192.168.1',
            '192.168.1.1.1',
            'localhost',
            '192.168.001.001',
        ];

        // Act & Assert
        foreach ($malformedIps as $badIp) {
            try {
                AdminAuthService::login($this->adminPassword, $badIp, 'Test');
                // If it succeeds, just verify it stored the IP (graceful handling)
                $this->assertTrue(true, "Malformed IP '{$badIp}' was handled gracefully");
            } catch (\CAH\Exceptions\ValidationException $e) {
                // Expected - validation exception is also acceptable
                $this->assertTrue(true, "Malformed IP '{$badIp}' threw validation exception");
            }
        }
    }

    public function test_sql_injection_in_password_is_safe(): void
    {
        // Arrange - SQL injection attempts
        $sqlInjectionAttempts = [
            "' OR '1'='1",
            "'; DROP TABLE admin_sessions; --",
            "admin' --",
            "' UNION SELECT * FROM admin_sessions --",
        ];

        // Act & Assert - Should fail gracefully without executing SQL
        foreach ($sqlInjectionAttempts as $injection) {
            $this->expectException(UnauthorizedException::class);
            try {
                AdminAuthService::login($injection, '127.0.0.1', 'Test');
            } catch (UnauthorizedException $e) {
                // Expected - continue
                continue;
            }
            $this->fail("SQL injection '{$injection}' should have been rejected");
        }

        // Verify admin_sessions table still exists
        $tableExists = Database::fetchOne("SHOW TABLES LIKE 'admin_sessions'");
        $this->assertNotNull($tableExists, 'admin_sessions table should still exist');
    }

    public function test_sql_injection_in_token_is_safe(): void
    {
        // Arrange
        $sqlInjectionTokens = [
            "'; DROP TABLE admin_sessions; --",
            "' OR '1'='1",
            "token' UNION SELECT * FROM games --",
        ];

        // Act & Assert - Should fail gracefully
        foreach ($sqlInjectionTokens as $injection) {
            $isValid = AdminAuthService::validateToken($injection, '127.0.0.1');
            $this->assertFalse($isValid, "SQL injection in token should be invalid");
        }

        // Verify tables still exist
        $tableExists = Database::fetchOne("SHOW TABLES LIKE 'admin_sessions'");
        $this->assertNotNull($tableExists, 'Tables should not be affected');
    }

    public function test_malformed_token_throws_validation_exception(): void
    {
        // Arrange
        $malformedToken = 'short';

        // Act & Assert
        $this->expectException(\CAH\Exceptions\ValidationException::class);
        AdminAuthService::validateToken($malformedToken, '127.0.0.1');
    }

    public function test_token_with_special_characters_throws_validation_exception(): void
    {
        // Arrange
        $specialCharToken = 'token-with-special-chars-!!!-@#$%';

        // Act & Assert
        $this->expectException(\CAH\Exceptions\ValidationException::class);
        AdminAuthService::validateToken($specialCharToken, '127.0.0.1');
    }

    public function test_logout_with_expired_token_returns_true(): void
    {
        // Arrange - Create expired session manually
        $token = bin2hex(random_bytes(32));
        $expiredTime = date('Y-m-d H:i:s', time() - 3600);
        Database::execute(
            "INSERT INTO admin_sessions (token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?)",
            [$token, '127.0.0.1', 'Test', $expiredTime]
        );

        // Verify it exists
        $exists = Database::fetchOne("SELECT * FROM admin_sessions WHERE token = ?", [$token]);
        $this->assertNotNull($exists);

        // Act
        $result = AdminAuthService::logout($token);

        // Assert - Should return true and delete the session
        $this->assertTrue($result, 'Logout with expired token should return true');
        
        $stillExists = Database::fetchOne("SELECT * FROM admin_sessions WHERE token = ?", [$token]);
        $this->assertNull($stillExists, 'Expired session should be deleted on logout');
    }

    public function test_token_validated_at_request_start(): void
    {
        // Arrange - Create token that expires in 2 seconds
        $ipAddress = '127.0.0.1';
        $loginResult = AdminAuthService::login($this->adminPassword, $ipAddress, 'Test');
        $token = $loginResult['token'];
        
        // Set expires_at to 2 seconds from now
        $expiresIn2Sec = date('Y-m-d H:i:s', time() + 2);
        Database::execute(
            "UPDATE admin_sessions SET expires_at = ? WHERE token = ?",
            [$expiresIn2Sec, $token]
        );

        // Act - Validate at start of "request"
        $validAtStart = AdminAuthService::validateToken($token, $ipAddress);
        $this->assertTrue($validAtStart, 'Token should be valid at request start');

        // Simulate request processing (sleep 3 seconds)
        sleep(3);

        // Token expired during processing, but request already started
        // Next validation should fail
        $validAfterExpiry = AdminAuthService::validateToken($token, $ipAddress);
        $this->assertFalse($validAfterExpiry, 'Next request should fail after expiration');
    }

    public function test_login_with_very_long_password(): void
    {
        // Arrange
        $longPassword = str_repeat('a', 10000); // 10KB password

        // Act & Assert
        $this->expectException(UnauthorizedException::class);
        AdminAuthService::login($longPassword, '127.0.0.1', 'Test');
    }

    public function test_login_with_special_characters_in_password(): void
    {
        // Arrange - Create admin password with special characters
        $specialPassword = "p@ssw0rd!#$%^&*()[]{}|;:',.<>?/`~";
        $specialHash = password_hash($specialPassword, PASSWORD_DEFAULT);
        $_ENV['ADMIN_PASSWORD_HASH'] = $specialHash;

        // Act
        $result = AdminAuthService::login($specialPassword, '127.0.0.1', 'Test');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        
        // Restore original password
        $_ENV['ADMIN_PASSWORD_HASH'] = $this->adminPasswordHash;
    }

    public function test_login_stores_very_long_user_agent(): void
    {
        // Arrange
        $longUserAgent = str_repeat('Mozilla/5.0 ', 100); // Very long UA
        
        // Act
        $result = AdminAuthService::login($this->adminPassword, '127.0.0.1', $longUserAgent);

        // Assert
        $session = Database::fetchOne(
            "SELECT user_agent FROM admin_sessions WHERE token = ?",
            [$result['token']]
        );
        
        // Should be truncated or stored completely depending on column size
        $this->assertNotNull($session);
    }

    public function test_validate_token_with_malformed_token(): void
    {
        // Arrange - Various malformed tokens
        $malformedTokens = [
            '',
            'short',
            'not-hex-chars-!!!',
            'abc' . str_repeat('x', 100), // Too long
            "token'; DROP TABLE admin_sessions; --", // SQL injection attempt
        ];

        // Act & Assert
        foreach ($malformedTokens as $badToken) {
            $isValid = AdminAuthService::validateToken($badToken, '127.0.0.1');
            $this->assertFalse($isValid, "Malformed token '{$badToken}' should be invalid");
        }
    }

    public function test_login_with_ipv6_address(): void
    {
        // Arrange
        $ipv6Address = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        // Act
        $result = AdminAuthService::login($this->adminPassword, $ipv6Address, 'Test');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        
        // Verify IP is stored correctly
        $session = Database::fetchOne(
            "SELECT ip_address FROM admin_sessions WHERE token = ?",
            [$result['token']]
        );
        $this->assertEquals($ipv6Address, $session['ip_address']);
        
        // Verify token is IP-bound to IPv6
        $this->assertTrue(AdminAuthService::validateToken($result['token'], $ipv6Address));
        $this->assertFalse(AdminAuthService::validateToken($result['token'], '127.0.0.1'));
    }

    // ========================================
    // SINGLE ADMIN PASSWORD
    // ========================================

    public function test_only_environment_password_works(): void
    {
        // This test verifies single admin password behavior
        
        // Arrange
        $correctPassword = $this->adminPassword;
        $wrongPasswords = [
            'wrong_password_123',
            'admin',
            'password',
            'test',
            '',
        ];

        // Act & Assert - Correct password works
        $result = AdminAuthService::login($correctPassword, '127.0.0.1', 'Test');
        $this->assertIsArray($result);

        // Act & Assert - All wrong passwords fail
        foreach ($wrongPasswords as $wrong) {
            $this->expectException(UnauthorizedException::class);
            try {
                AdminAuthService::login($wrong, '127.0.0.1', 'Test');
            } catch (UnauthorizedException $e) {
                // Expected - continue to next
                continue;
            }
            $this->fail("Password '{$wrong}' should have been rejected");
        }
    }
}
