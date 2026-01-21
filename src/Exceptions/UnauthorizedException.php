<?php

declare(strict_types=1);

namespace CAH\Exceptions;

/**
 * Exception thrown when a player is not authorized to perform an action
 */
class UnauthorizedException extends GameException
{
    public function __construct(string $message = 'Unauthorized action')
    {
        parent::__construct($message, 403);
    }
}
