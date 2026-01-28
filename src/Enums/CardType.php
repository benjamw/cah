<?php

declare(strict_types=1);

namespace CAH\Enums;

/**
 * Card Type Enum
 *
 * Represents the type of card (black prompt cards or white response cards)
 */
enum CardType: string
{
    case PROMPT = 'prompt';
    case RESPONSE = 'response';
}
