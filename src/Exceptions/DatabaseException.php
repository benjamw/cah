<?php

declare(strict_types=1);

namespace CAH\Exceptions;

use RuntimeException;

/**
 * Exception thrown when database operations fail
 */
class DatabaseException extends RuntimeException
{
    public function __construct(
        string $message = 'Database operation failed',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
