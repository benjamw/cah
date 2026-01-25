<?php

declare(strict_types=1);

namespace CAH\Constants;

/**
 * Rate Limit Action Constants
 *
 * Defines action types for rate limiting
 */
class RateLimitAction
{
    public const JOIN_GAME = 'join_game';
    public const CREATE_GAME = 'create_game';
    public const FAILED_GAME_CODE = 'failed_game_code';
    public const ADMIN_LOGIN = 'admin_login';
}
