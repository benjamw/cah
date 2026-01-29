<?php

declare(strict_types=1);

namespace CAH\Exceptions;

use RuntimeException;

/**
 * Exception thrown when game code generation fails
 */
class GameCodeGenerationException extends RuntimeException
{
    public function __construct(
        string $message = 'Failed to generate game code',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
