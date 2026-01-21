<?php

declare(strict_types=1);

namespace CAH\Exceptions;

/**
 * Exception thrown when an operation is invalid for the current game state
 */
class InvalidGameStateException extends GameException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 400);
    }
}
