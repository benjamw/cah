<?php

declare(strict_types=1);

namespace CAH\Database;

use PDO;
use PDOException;
use CAH\Exceptions\DatabaseException;

/**
 * Database connection wrapper using PDO
 * Implements singleton pattern for connection pooling
 *
 * IMPORTANT: Persistent connections (PDO::ATTR_PERSISTENT) are disabled by default
 * because they can cause lock leaks when using MySQL GET_LOCK() for game state locking.
 * If you need persistent connections, set DB_PERSISTENT=true in your .env file,
 * but be aware that locks may persist across requests if not properly released.
 */
class Database
{
    private static ?PDO $connection = null;
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * Initialize database configuration
     *
     * @param array<string, mixed> $config Database configuration array
     */
    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Get PDO connection instance (singleton)
     *
     * @return PDO
     * @throws DatabaseException
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }

        return self::$connection;
    }

    /**
     * Establish database connection
     *
     * @throws DatabaseException
     */
    private static function connect(): void
    {
        if (empty(self::$config)) {
            throw new DatabaseException('Database configuration not initialized. Call Database::init() first.');
        }

        $host = self::$config['host'] ?? 'localhost';
        $port = self::$config['port'] ?? 3306;
        $database = self::$config['database'] ?? '';
        $username = self::$config['username'] ?? '';
        $password = self::$config['password'] ?? '';
        $charset = self::$config['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        // Persistent connections can cause lock leaks with MySQL GET_LOCK()
        // Disable by default, enable via DB_PERSISTENT environment variable
        $persistent = filter_var(
            $_ENV['DB_PERSISTENT'] ?? getenv('DB_PERSISTENT') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => $persistent,
        ];

        try {
            self::$connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new DatabaseException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute a query and return the statement
     *
     * @param string $sql SQL query
     * @param array<int|string, mixed> $params Query parameters
     * @return \PDOStatement
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $connection = self::getConnection();
        $stmt = $connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row
     *
     * @param string $sql SQL query
     * @param array<int|string, mixed> $params Query parameters
     * @return array<string, mixed>|false
     */
    public static function fetchOne(string $sql, array $params = []): array|false
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Fetch all rows
     *
     * @param string $sql SQL query
     * @param array<int|string, mixed> $params Query parameters
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query
     *
     * @param string $sql SQL query
     * @param array<int|string, mixed> $params Query parameters
     * @return int Number of affected rows
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Get last insert ID
     *
     * @return string
     */
    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }
}
