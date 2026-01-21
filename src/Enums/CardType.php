<?php

declare(strict_types=1);

namespace CAH\Enums;

/**
 * Card Type Enum
 *
 * Represents the type of card (white answer cards or black question cards)
 */
enum CardType: string
{
    case WHITE = 'white';
    case BLACK = 'black';
}

