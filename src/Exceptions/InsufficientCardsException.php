<?php

declare(strict_types=1);

namespace CAH\Exceptions;

/**
 * Exception thrown when there are not enough cards available
 */
class InsufficientCardsException extends GameException
{
    public function __construct(string $cardType, int $required, int $available)
    {
        parent::__construct(
            "Insufficient {$cardType} cards: need {$required}, but only {$available} available",
            400
        );
    }
}
