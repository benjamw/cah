<?php

declare(strict_types=1);

/**
 * Game Configuration
 *
 * Default game rules and settings
 */

return [
    // Player settings
    'min_players' => 3,
    'max_players' => 20,
    'hand_size' => 10, // Number of response cards each player holds

    // Game settings
    'default_max_score' => 8, // Points needed to win
    'draw_pile_warning_threshold' => 100, // Warn when response cards remaining drops below this

    // Rate limiting
    'rate_limit' => [
        'join_game' => [
            'max_attempts' => 5,
            'window_minutes' => 1,
            'lockout_minutes' => 5,
        ],
        'create_game' => [
            'max_attempts' => 10,
            'window_minutes' => 5,
            'lockout_minutes' => 10,
        ],
        'failed_game_code' => [
            'max_attempts' => 10,
            'window_minutes' => 5,
            'lockout_minutes' => 5,
        ],
    ],

    // Cleanup
    'game_expiry_days' => 7, // Delete games older than this

    // Locking
    'lock_timeout_seconds' => 10, // Database lock timeout for game state operations

    // Special features
    'rando_cardrissian_name' => 'Rando Cardrissian',
];
