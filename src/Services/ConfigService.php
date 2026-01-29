<?php

declare(strict_types=1);

namespace CAH\Services;

/**
 * Configuration Service
 *
 * Centralized configuration management with lazy loading and caching
 */
class ConfigService
{
    /** @var array<string, mixed>|null */
    private static ?array $gameConfig = null;
    /** @var array<string, mixed>|null */
    private static ?array $databaseConfig = null;

    /**
     * Get game configuration
     *
     * @return array<string, mixed>
     */
    public static function getGameConfig(): array
    {
        if (self::$gameConfig === null) {
            self::$gameConfig = require __DIR__ . '/../../config/game.php';
        }
        return self::$gameConfig;
    }

    /**
     * Get database configuration
     *
     * @return array<string, mixed>
     */
    public static function getDatabaseConfig(): array
    {
        if (self::$databaseConfig === null) {
            self::$databaseConfig = require __DIR__ . '/../../config/database.php';
        }
        return self::$databaseConfig;
    }

    /**
     * Get a specific game config value
     *
     * @param string $key Config key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public static function getGameValue(string $key, $default = null)
    {
        $config = self::getGameConfig();
        return $config[$key] ?? $default;
    }

    /**
     * Clear cached configuration (useful for testing)
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$gameConfig = null;
        self::$databaseConfig = null;
    }
}
