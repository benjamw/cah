<?php

declare(strict_types=1);

namespace CAH\Utils;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 adapter that delegates to CAH\Utils\Logger.
 * Used by Slim ErrorMiddleware so uncaught exceptions are written to app.log.
 */
class PsrLoggerAdapter extends AbstractLogger
{
    /**
     * @param mixed $level
     * @param mixed[] $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;
        $context = array_merge($context, ['level' => $level]);

        match ($level) {
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR => Logger::error($message, $context),
            LogLevel::WARNING => Logger::warning($message, $context),
            LogLevel::NOTICE => Logger::notice($message, $context),
            LogLevel::INFO => Logger::info($message, $context),
            default => Logger::debug($message, $context),
        };
    }
}
