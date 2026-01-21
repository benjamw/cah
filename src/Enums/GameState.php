<?php

declare(strict_types=1);

namespace CAH\Enums;

/**
 * Game State Enum
 *
 * Represents the current state of a game
 */
enum GameState: string
{
    case WAITING = 'waiting';
    case PLAYING = 'playing';
    case FINISHED = 'finished';
}

