<?php

declare(strict_types=1);

namespace CAH\Exceptions;

use RuntimeException;

/**
 * Exception thrown when lock operations fail
 */
class LockException extends RuntimeException
{
    public function __construct(string $message = 'Lock operation failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

