<?php

declare(strict_types=1);

namespace CAH\Exceptions;

/**
 * Exception thrown when a player is not found
 */
class PlayerNotFoundException extends GameException
{
    public function __construct(string $playerId)
    {
        parent::__construct("Player '{$playerId}' not found", 404);
    }
}
