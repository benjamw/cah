<?php

declare(strict_types=1);

namespace CAH\Utils;

use CAH\Exceptions\FileSystemException;
use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

/**
 * Logger Utility
 *
 * Simple wrapper around Monolog for application logging
 */
class Logger
{
    private static ?MonologLogger $instance = null;

    /**
     * Get logger instance (singleton)
     */
    private static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('cah');

            // Log to logs/app.log
            $logPath = __DIR__ . '/../../logs/app.log';
            $logDir = dirname($logPath);

            // Create logs directory if it doesn't exist
            if ( ! is_dir($logDir)) {
                if ( ! mkdir($logDir, 0755, true) && ! is_dir($logDir)) {
                    throw new FileSystemException(sprintf('Directory "%s" could not be created', $logDir));
                }
            }

            // Add file handler (logs warnings and above)
            self::$instance->pushHandler(
                new StreamHandler($logPath, Level::Warning)
            );
        }

        return self::$instance;
    }

    /**
     * Log a warning message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }

    /**
     * Log an error message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }

    /**
     * Log an info message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }

    /**
     * Log a debug message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }
}
