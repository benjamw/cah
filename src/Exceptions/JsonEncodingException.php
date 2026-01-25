<?php

declare(strict_types=1);

namespace CAH\Exceptions;

use RuntimeException;

/**
 * Exception thrown when JSON encoding/decoding fails
 */
class JsonEncodingException extends RuntimeException
{
    public function __construct(string $message = 'JSON encoding failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
