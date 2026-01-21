<?php

declare(strict_types=1);

namespace CAH\Services;

use CAH\Database\Database;
use CAH\Utils\GameCodeGenerator;
use CAH\Exceptions\ValidationException;
use CAH\Exceptions\LockException;

/**
 * Lock Service
 *
 * Provides mutex locking using MySQL GET_LOCK/RELEASE_LOCK
 * Prevents race conditions when multiple players interact with the same game simultaneously
 */
class LockService
{
    private const LOCK_PREFIX = 'cah_game_';
    private const DEFAULT_LOCK_TIMEOUT = 10;

    private static array $activeLocks = [];
    private static ?int $lockTimeout = null;

    /**
     * Get lock timeout from config
     */
    private static function getLockTimeout(): int
    {
        if (self::$lockTimeout === null) {
            $configPath = __DIR__ . '/../../config/game.php';
            $config = file_exists($configPath) ? require $configPath : [];
            self::$lockTimeout = $config['lock_timeout_seconds'] ?? self::DEFAULT_LOCK_TIMEOUT;
        }
        return self::$lockTimeout;
    }

    /**
     * Acquire a lock for a game
     *
     * @param string $gameId Game ID to lock
     * @param int|null $timeout Lock timeout in seconds (null = use config default)
     * @return bool True if lock acquired, false otherwise
     * @throws LockException
     */
    public static function acquireGameLock(string $gameId, ?int $timeout = null): bool
    {
        $timeout ??= self::getLockTimeout();
        $lockName = self::LOCK_PREFIX . $gameId;

        $sql = "
            SELECT GET_LOCK(?, ?) AS `lock_result`
        ";
        $result = Database::fetchOne($sql, [$lockName, $timeout]);

        if ($result === false) {
            throw new LockException('Failed to execute lock query');
        }

        $lockAcquired = (int) $result['lock_result'] === 1;

        if ($lockAcquired) {
            self::$activeLocks[$gameId] = $lockName;
        }

        return $lockAcquired;
    }

    /**
     * Release a lock for a game
     *
     * @param string $gameId Game ID to unlock
     * @return bool True if lock released, false if lock was not held
     * @throws LockException
     */
    public static function releaseGameLock(string $gameId): bool
    {
        if ( ! isset(self::$activeLocks[$gameId])) {
            return false;
        }

        $lockName = self::$activeLocks[$gameId];

        $sql = "
            SELECT RELEASE_LOCK(?) AS `lock_result`
        ";
        $result = Database::fetchOne($sql, [$lockName]);

        if ($result === false) {
            throw new LockException('Failed to execute unlock query');
        }

        $lockReleased = (int) $result['lock_result'] === 1;

        if ($lockReleased) {
            unset(self::$activeLocks[$gameId]);
        }

        return $lockReleased;
    }

    /**
     * Execute a callback with a game lock and database transaction
     * Automatically acquires and releases the lock, and wraps in a transaction
     *
     * @param string $gameId Game ID to lock
     * @param callable $callback Function to execute while locked
     * @param int $timeout Lock timeout in seconds
     * @return mixed Return value from callback
     * @throws LockException If lock cannot be acquired
     */
    public static function withGameLock(string $gameId, callable $callback, int $timeout = self::DEFAULT_LOCK_TIMEOUT): mixed
    {
        if ( ! GameCodeGenerator::isValid($gameId)) {
            throw new ValidationException('Invalid game code format');
        }

        $lockAcquired = self::acquireGameLock($gameId, $timeout);

        if ( ! $lockAcquired) {
            throw new LockException("Unable to acquire lock for game {$gameId}. Another operation may be in progress.");
        }

        // Begin transaction for data consistency
        Database::beginTransaction();

        try {
            $result = $callback();
            Database::commit();
            return $result;
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        } finally {
            // Always release the lock, even if callback throws exception
            self::releaseGameLock($gameId);
        }
    }

    /**
     * Check if a lock is currently held for a game
     *
     * @param string $gameId Game ID to check
     * @return bool True if lock is held by this process
     */
    public static function isLocked(string $gameId): bool
    {
        return isset(self::$activeLocks[$gameId]);
    }

    /**
     * Release all active locks
     * Should be called at the end of request or in error handlers
     *
     * @return void
     */
    public static function releaseAllLocks(): void
    {
        foreach (array_keys(self::$activeLocks) as $gameId) {
            self::releaseGameLock($gameId);
        }
    }

    /**
     * Check if a lock is available (not held by any process)
     *
     * @param string $gameId Game ID to check
     * @return bool True if lock is free
     */
    public static function isLockFree(string $gameId): bool
    {
        $lockName = self::LOCK_PREFIX . $gameId;

        $sql = "
            SELECT IS_FREE_LOCK(?) AS `lock_result`
        ";
        $result = Database::fetchOne($sql, [$lockName]);

        if ($result === false) {
            throw new LockException('Failed to check lock status');
        }

        return (int) $result['lock_result'] === 1;
    }
}
