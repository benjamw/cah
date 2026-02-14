<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Database\Database;
use CAH\Exceptions\LockException;
use CAH\Exceptions\ValidationException;
use CAH\Services\LockService;
use CAH\Tests\TestCase;
use PDO;

class LockServiceIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        LockService::releaseAllLocks();
        parent::tearDown();
    }

    public function testAcquireReleaseAndLockState(): void
    {
        $gameId = 'ABCD';

        $this->assertTrue(LockService::isLockFree($gameId));
        $this->assertFalse(LockService::isLocked($gameId));

        $acquired = LockService::acquireGameLock($gameId, 1);
        $this->assertTrue($acquired);
        $this->assertTrue(LockService::isLocked($gameId));
        $this->assertFalse(LockService::isLockFree($gameId));

        $released = LockService::releaseGameLock($gameId);
        $this->assertTrue($released);
        $this->assertFalse(LockService::isLocked($gameId));
        $this->assertTrue(LockService::isLockFree($gameId));
    }

    public function testReleaseGameLockReturnsFalseWhenNotHeldByProcess(): void
    {
        $this->assertFalse(LockService::releaseGameLock('WXYZ'));
    }

    public function testWithGameLockReturnsCallbackResultAndReleasesLock(): void
    {
        $gameId = 'QWER';

        $result = LockService::withGameLock(
            $gameId,
            fn(): array => ['ok' => true, 'id' => $gameId]
        );

        $this->assertSame(['ok' => true, 'id' => $gameId], $result);
        $this->assertFalse(LockService::isLocked($gameId));
        $this->assertTrue(LockService::isLockFree($gameId));
    }

    public function testWithGameLockRollsBackAndReleasesOnException(): void
    {
        $gameId = 'ZXCV';

        try {
            LockService::withGameLock($gameId, function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFalse(LockService::isLocked($gameId));
        $this->assertTrue(LockService::isLockFree($gameId));
    }

    public function testWithGameLockRejectsInvalidGameCode(): void
    {
        $this->expectException(ValidationException::class);
        LockService::withGameLock('bad', fn(): bool => true);
    }

    public function testSecondAcquireOnSameConnectionIsReentrant(): void
    {
        $gameId = 'TYUI';

        $this->assertTrue(LockService::acquireGameLock($gameId, 1));
        try {
            $secondAcquire = LockService::acquireGameLock($gameId, 1);
            $this->assertTrue($secondAcquire);
        } finally {
            LockService::releaseGameLock($gameId);
        }
    }

    public function testAcquireReturnsFalseWhenHeldByAnotherConnection(): void
    {
        $host = $_ENV['TEST_DB_HOST'] ?? getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $db = $_ENV['TEST_DB_NAME'] ?? getenv('TEST_DB_NAME') ?: '';
        $user = $_ENV['TEST_DB_USER'] ?? getenv('TEST_DB_USER') ?: '';
        $pass = $_ENV['TEST_DB_PASS'] ?? getenv('TEST_DB_PASS') ?: '';
        $dsn = "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4";

        $other = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $lockName = 'cah_game_LOCK';
        $held = $other->prepare('SELECT GET_LOCK(?, 1) AS lock_result');
        $held->execute([$lockName]);
        $this->assertSame(1, (int) $held->fetch()['lock_result']);

        try {
            $acquired = LockService::acquireGameLock('LOCK', 0);
            $this->assertFalse($acquired);
            $this->assertFalse(LockService::isLocked('LOCK'));
        } finally {
            $rel = $other->prepare('SELECT RELEASE_LOCK(?) AS lock_result');
            $rel->execute([$lockName]);
        }
    }

    public function testReleaseAllLocksClearsMultipleLocks(): void
    {
        $this->assertTrue(LockService::acquireGameLock('MUL1', 1));
        $this->assertTrue(LockService::acquireGameLock('MUL2', 1));
        $this->assertTrue(LockService::isLocked('MUL1'));
        $this->assertTrue(LockService::isLocked('MUL2'));

        LockService::releaseAllLocks();

        $this->assertFalse(LockService::isLocked('MUL1'));
        $this->assertFalse(LockService::isLocked('MUL2'));
        $this->assertTrue(LockService::isLockFree('MUL1'));
        $this->assertTrue(LockService::isLockFree('MUL2'));
    }
}

