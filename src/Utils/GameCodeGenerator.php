<?php

declare(strict_types=1);

namespace CAH\Utils;

use CAH\Constants\GameDefaults;
use CAH\Constants\ValidationRules;
use CAH\Database\Database;
use CAH\Exceptions\GameCodeGenerationException;

/**
 * Game Code Generator
 *
 * Generates unique 4-character game codes
 */
class GameCodeGenerator
{
    private const CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Generate a unique 4-character game code
     *
     * @throws GameCodeGenerationException If unable to generate unique code after max attempts
     */
    public static function generate(): string
    {
        $attempts = 0;

        while ($attempts < GameDefaults::MAX_GAME_CODE_GENERATION_ATTEMPTS) {
            $code = self::generateCode();

            if ( ! self::codeExists($code)) {
                return $code;
            }

            $attempts++;
        }

        throw new GameCodeGenerationException(
            'Unable to generate unique game code after ' .
            GameDefaults::MAX_GAME_CODE_GENERATION_ATTEMPTS . ' attempts'
        );
    }

    /**
     * Generate a random 4-character code without checking uniqueness
     * Used when the caller will handle duplicate key errors
     */
    public static function generateCode(): string
    {
        $code = '';
        $maxIndex = strlen(self::CHARACTERS) - 1;

        for ($i = 0; $i < ValidationRules::GAME_CODE_LENGTH; $i++) {
            $code .= self::CHARACTERS[random_int(0, $maxIndex)];
        }

        return $code;
    }

    /**
     * Check if a game code already exists in the database
     */
    private static function codeExists(string $code): bool
    {
        $sql = "
            SELECT COUNT(*) AS `count`
            FROM games
            WHERE game_id = ?
        ";
        $result = Database::fetchOne($sql, [$code]);

        return $result && $result['count'] > 0;
    }

    /**
     * Validate a game code format
     */
    public static function isValid(string $code): bool
    {
        if (strlen($code) !== ValidationRules::GAME_CODE_LENGTH) {
            return false;
        }

        $pattern = '/^[' . preg_quote(self::CHARACTERS, '/') . ']{' . ValidationRules::GAME_CODE_LENGTH . '}$/';
        return preg_match($pattern, strtoupper($code)) === 1;
    }
}
