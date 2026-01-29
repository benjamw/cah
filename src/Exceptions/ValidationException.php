<?php

declare(strict_types=1);

namespace CAH\Exceptions;

/**
 * Exception thrown when validation fails
 */
class ValidationException extends GameException
{
    /**
     * @param string $message
     * @param array<string, string|array<string>> $errors
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message, 422);
    }

    /**
     * @return array<string, string|array<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
