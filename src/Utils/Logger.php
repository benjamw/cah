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
 * Simple wrapper around Monolog for application logging.
 * Log level is configurable via LOG_LEVEL (debug, info, notice, warning, error).
 */
class Logger
{
    private static ?MonologLogger $instance = null;

    private const LEVEL_MAP = [
        'debug' => Level::Debug,
        'info' => Level::Info,
        'notice' => Level::Notice,
        'warning' => Level::Warning,
        'error' => Level::Error,
    ];

    /**
     * Get logger instance (singleton)
     */
    private static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('cah');

            $logPath = __DIR__ . '/../../logs/app.log';
            $logDir = dirname($logPath);

            if ( ! is_dir($logDir)) {
                if ( ! mkdir($logDir, 0755, true) && ! is_dir($logDir)) {
                    throw new FileSystemException(sprintf('Directory "%s" could not be created', $logDir));
                }
            }

            $envLogLevel = $_ENV['LOG_LEVEL'] ?? getenv('LOG_LEVEL');
            $levelName = strtolower(
                is_string($envLogLevel) && $envLogLevel !== ''
                    ? $envLogLevel
                    : 'warning'
            );
            $level = self::LEVEL_MAP[$levelName] ?? Level::Warning;

            self::$instance->pushHandler(new StreamHandler($logPath, $level));
        }

        return self::$instance;
    }

    /**
     * Log a notice message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function notice(string $message, array $context = []): void
    {
        self::getInstance()->notice($message, $context);
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
