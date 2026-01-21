<?php

declare(strict_types=1);

namespace CAH\Constants;

/**
 * Validation Rules Constants
 *
 * Defines validation constraints for player names, game codes, etc.
 */
class ValidationRules
{
    // Player name validation
    public const PLAYER_NAME_MIN_LENGTH = 3;
    public const PLAYER_NAME_MAX_LENGTH = 30;

    // Game code validation
    public const GAME_CODE_LENGTH = 4;
    
    // Card choices validation
    public const DEFAULT_CARD_CHOICES = 1;
    public const MIN_UNDERSCORE_PATTERN_LENGTH = 2;
}

