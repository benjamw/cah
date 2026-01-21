<?php

declare(strict_types=1);

namespace CAH\Exceptions;

/**
 * Exception thrown when a game is not found
 */
class GameNotFoundException extends GameException
{
    public function __construct(string $gameId)
    {
        parent::__construct("Game '{$gameId}' not found", 404);
    }
}
