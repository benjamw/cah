<?php

declare(strict_types=1);

namespace CAH\Constants;

/**
 * Game Defaults Constants
 *
 * Defines default values for game creation and retry logic
 */
class GameDefaults
{
    // Game creation retry logic
    public const MAX_GAME_CODE_GENERATION_ATTEMPTS = 10;
    
    // Initial game state values
    public const INITIAL_SCORE = 0;
    public const INITIAL_ROUND = 0;
    public const FIRST_ROUND = 1;
    
    // Rate limit defaults (fallback values if config not loaded)
    public const DEFAULT_RATE_LIMIT_MAX_ATTEMPTS = 10;
    public const DEFAULT_RATE_LIMIT_WINDOW_MINUTES = 5;
    public const DEFAULT_RATE_LIMIT_LOCKOUT_MINUTES = 5;
    
    // Time conversion
    public const SECONDS_PER_MINUTE = 60;
}

