<?php

declare(strict_types=1);

namespace CAH\Utils;

use CAH\Constants\ValidationRules;

/**
 * Input Validator
 *
 * Provides validation methods for API inputs
 */
class Validator
{
    private array $errors = [];

    /**
     * Validate required field
     *
     * @param mixed $value
     * @param string $fieldName
     * @return self
     */
    public function required(mixed $value, string $fieldName): self
    {
        if (in_array($value, [null, '', []], true)) {
            $this->errors[$fieldName] = "{$fieldName} is required";
        }

        return $this;
    }

    /**
     * Validate string length
     *
     * @param string|null $value
     * @param string $fieldName
     * @param int $min
     * @param int|null $max
     * @return self
     */
    public function stringLength(?string $value, string $fieldName, int $min = 0, ?int $max = null): self
    {
        if ($value === null) {
            return $this;
        }

        $length = mb_strlen($value);

        if ($length < $min) {
            $this->errors[$fieldName] = "{$fieldName} must be at least {$min} characters";
        }

        if ($max !== null && $length > $max) {
            $this->errors[$fieldName] = "{$fieldName} must not exceed {$max} characters";
        }

        return $this;
    }

    /**
     * Validate integer value
     *
     * @param mixed $value
     * @param string $fieldName
     * @param int|null $min
     * @param int|null $max
     * @return self
     */
    public function integer(mixed $value, string $fieldName, ?int $min = null, ?int $max = null): self
    {
        if ($value === null) {
            return $this;
        }

        if ( ! is_int($value) && ! ctype_digit((string)$value)) {
            $this->errors[$fieldName] = "{$fieldName} must be an integer";
            return $this;
        }

        $intValue = (int) $value;

        if ($min !== null && $intValue < $min) {
            $this->errors[$fieldName] = "{$fieldName} must be at least {$min}";
        }

        if ($max !== null && $intValue > $max) {
            $this->errors[$fieldName] = "{$fieldName} must not exceed {$max}";
        }

        return $this;
    }

    /**
     * Validate boolean value
     *
     * @param mixed $value
     * @param string $fieldName
     * @return self
     */
    public function boolean(mixed $value, string $fieldName): self
    {
        if ($value === null) {
            return $this;
        }

        if ( ! is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
            $this->errors[$fieldName] = "{$fieldName} must be a boolean";
        }

        return $this;
    }

    /**
     * Validate array
     *
     * @param mixed $value
     * @param string $fieldName
     * @param int|null $minItems
     * @param int|null $maxItems
     * @return self
     */
    public function array(mixed $value, string $fieldName, ?int $minItems = null, ?int $maxItems = null): self
    {
        if ($value === null) {
            return $this;
        }

        if ( ! is_array($value)) {
            $this->errors[$fieldName] = "{$fieldName} must be an array";
            return $this;
        }

        $count = count($value);

        if ($minItems !== null && $count < $minItems) {
            $this->errors[$fieldName] = "{$fieldName} must contain at least {$minItems} items";
        }

        if ($maxItems !== null && $count > $maxItems) {
            $this->errors[$fieldName] = "{$fieldName} must not contain more than {$maxItems} items";
        }

        return $this;
    }

    /**
     * Validate value is in a list of allowed values
     *
     * @param mixed $value
     * @param string $fieldName
     * @param array $allowedValues
     * @return self
     */
    public function in(mixed $value, string $fieldName, array $allowedValues): self
    {
        if ($value === null) {
            return $this;
        }

        if ( ! in_array($value, $allowedValues, true)) {
            $allowed = implode(', ', $allowedValues);
            $this->errors[$fieldName] = "{$fieldName} must be one of: {$allowed}";
        }

        return $this;
    }

    /**
     * Check if validation passed
     *
     * @return bool
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     *
     * @return bool
     */
    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Validate and sanitize a player name
     *
     * @param string $name Raw player name input
     * @return array ['valid' => bool, 'name' => string, 'error' => string|null]
     */
    public static function validatePlayerName(string $name): array
    {
        $sanitized = trim($name);

        if (empty($sanitized)) {
            return ['valid' => false, 'name' => $sanitized, 'error' => 'Player name is required'];
        }

        if (mb_strlen($sanitized) < ValidationRules::PLAYER_NAME_MIN_LENGTH) {
            return [
                'valid' => false,
                'name' => $sanitized,
                'error' => 'Player name must be at least ' .
                    ValidationRules::PLAYER_NAME_MIN_LENGTH . ' characters'
            ];
        }

        if (mb_strlen($sanitized) > ValidationRules::PLAYER_NAME_MAX_LENGTH) {
            return [
                'valid' => false,
                'name' => $sanitized,
                'error' => 'Player name must not exceed ' .
                    ValidationRules::PLAYER_NAME_MAX_LENGTH . ' characters'
            ];
        }

        // Block dangerous characters:
        // - Control characters (0x00-0x1F, 0x7F-0x9F)
        // - Zero-width and invisible characters (U+200B-U+200F)
        // - Bidirectional text override characters (U+202A-U+202E)
        // - Line/Paragraph separators (U+2028-U+2029)
        // - Byte Order Mark (U+FEFF)
        if (preg_match('/[\x00-\x1F\x7F-\x9F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2028}\x{2029}\x{FEFF}]/u', $sanitized)) {
            return ['valid' => false, 'name' => $sanitized, 'error' => 'Player name contains invalid characters'];
        }

        return ['valid' => true, 'name' => $sanitized, 'error' => null];
    }

    /**
     * Validate and normalize an array of card IDs
     *
     * @param array $cardIds Raw card IDs from input
     * @return array ['valid' => bool, 'card_ids' => int[], 'error' => string|null]
     */
    public static function validateCardIds(array $cardIds): array
    {
        if (empty($cardIds)) {
            return ['valid' => false, 'card_ids' => [], 'error' => 'Card IDs are required'];
        }

        $normalized = [];
        foreach ($cardIds as $cardId) {
            if (is_array($cardId) || ( ! is_int($cardId) && ! ctype_digit((string) $cardId) )) {
                return ['valid' => false, 'card_ids' => [], 'error' => 'All card IDs must be integers'];
            }

            $intValue = (int) $cardId;
            if ($intValue <= 0) {
                return ['valid' => false, 'card_ids' => [], 'error' => 'All card IDs must be positive integers'];
            }

            $normalized[] = $intValue;
        }

        return ['valid' => true, 'card_ids' => $normalized, 'error' => null];
    }

    /**
     * Validate and normalize a game code
     *
     * @param string $code Raw game code input
     * @return array ['valid' => bool, 'code' => string, 'error' => string|null]
     */
    public static function validateGameCode(string $code): array
    {
        $normalized = strtoupper(trim($code));

        if (empty($normalized)) {
            return ['valid' => false, 'code' => $normalized, 'error' => 'Game code is required'];
        }

        if (strlen($normalized) !== ValidationRules::GAME_CODE_LENGTH) {
            return [
                'valid' => false,
                'code' => $normalized,
                'error' => 'Game code must be exactly ' .
                    ValidationRules::GAME_CODE_LENGTH . ' characters'
            ];
        }

        // Allow only alphanumeric characters
        $pattern = '/^[A-Z0-9]{' . ValidationRules::GAME_CODE_LENGTH . '}$/';
        if ( ! preg_match($pattern, $normalized)) {
            return ['valid' => false, 'code' => $normalized, 'error' => 'Game code must contain only letters and numbers'];
        }

        return ['valid' => true, 'code' => $normalized, 'error' => null];
    }

    /**
     * Validate array has required keys
     *
     * @param array $data Array to validate
     * @param array $requiredKeys Required keys that must be present and non-empty
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateArray(array $data, array $requiredKeys): array
    {
        foreach ($requiredKeys as $key) {
            if ( ! array_key_exists($key, $data)) {
                return ['valid' => false, 'error' => "Missing required key: {$key}"];
            }

            if (empty($data[$key]) && $data[$key] !== 0 && $data[$key] !== false) {
                return ['valid' => false, 'error' => "Required key '{$key}' cannot be empty"];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate game settings
     *
     * @param array $settings Game settings to validate
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateGameSettings(array $settings): array
    {
        // Validate max_score if provided
        if (isset($settings['max_score'])) {
            if ( ! is_int($settings['max_score']) && ! ctype_digit((string) $settings['max_score'])) {
                return ['valid' => false, 'error' => 'max_score must be an integer'];
            }

            $maxScore = (int) $settings['max_score'];
            if ($maxScore < 1) {
                return ['valid' => false, 'error' => 'max_score must be at least 1'];
            }
        }

        // Validate hand_size if provided
        if (isset($settings['hand_size'])) {
            if ( ! is_int($settings['hand_size']) && ! ctype_digit((string) $settings['hand_size'])) {
                return ['valid' => false, 'error' => 'hand_size must be an integer'];
            }

            $handSize = (int) $settings['hand_size'];
            if ($handSize < 1) {
                return ['valid' => false, 'error' => 'hand_size must be at least 1'];
            }
        }

        return ['valid' => true, 'error' => null];
    }
}
