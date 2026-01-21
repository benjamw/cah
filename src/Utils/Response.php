<?php

declare(strict_types=1);

namespace CAH\Utils;

use Psr\Http\Message\ResponseInterface;

/**
 * Response Helper
 *
 * Provides consistent JSON response formatting for the API
 */
class Response
{
    /**
     * Send a success response
     *
     * @param ResponseInterface $response
     * @param mixed $data Response data
     * @param string|null $message Optional success message
     * @param int $statusCode HTTP status code
     * @return ResponseInterface
     */
    public static function success(
        ResponseInterface $response,
        mixed $data = null,
        ?string $message = null,
        int $statusCode = 200
    ): ResponseInterface {
        $payload = [
            'success' => true,
            'timestamp' => time(),
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return self::json($response, $payload, $statusCode);
    }

    /**
     * Send an error response
     *
     * @param ResponseInterface $response
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array|null $errors Additional error details
     * @param mixed $data Optional data to include in response
     * @return ResponseInterface
     */
    public static function error(
        ResponseInterface $response,
        string $message,
        int $statusCode = 400,
        ?array $errors = null,
        mixed $data = null
    ): ResponseInterface {
        $payload = [
            'success' => false,
            'timestamp' => time(),
            'error' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return self::json($response, $payload, $statusCode);
    }

    /**
     * Send a validation error response
     *
     * @param ResponseInterface $response
     * @param array $errors Validation errors
     * @param string $message Error message
     * @return ResponseInterface
     */
    public static function validationError(
        ResponseInterface $response,
        array $errors,
        string $message = 'Validation failed'
    ): ResponseInterface {
        return self::error($response, $message, 422, $errors);
    }

    /**
     * Send a not found response
     *
     * @param ResponseInterface $response
     * @param string $message Error message
     * @return ResponseInterface
     */
    public static function notFound(
        ResponseInterface $response,
        string $message = 'Resource not found'
    ): ResponseInterface {
        return self::error($response, $message, 404);
    }

    /**
     * Send an unauthorized response
     *
     * @param ResponseInterface $response
     * @param string $message Error message
     * @return ResponseInterface
     */
    public static function unauthorized(
        ResponseInterface $response,
        string $message = 'Unauthorized'
    ): ResponseInterface {
        return self::error($response, $message, 401);
    }

    /**
     * Send a rate limit exceeded response
     *
     * @param ResponseInterface $response
     * @param string $message Error message
     * @param int|null $retryAfter Seconds until retry is allowed
     * @return ResponseInterface
     */
    public static function rateLimitExceeded(
        ResponseInterface $response,
        string $message = 'Rate limit exceeded',
        ?int $retryAfter = null
    ): ResponseInterface {
        $resp = self::error($response, $message, 429);

        if ($retryAfter !== null) {
            $resp = $resp->withHeader('Retry-After', (string) $retryAfter);
        }

        return $resp;
    }

    /**
     * Send a JSON response
     *
     * @param ResponseInterface $response
     * @param mixed $data Data to encode as JSON
     * @param int $statusCode HTTP status code
     * @return ResponseInterface
     */
    private static function json(
        ResponseInterface $response,
        mixed $data,
        int $statusCode = 200
    ): ResponseInterface {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $errorData = [
                'success' => false,
                'error' => [
                    'message' => 'Failed to encode response: ' . json_last_error_msg(),
                    'code' => 'JSON_ENCODE_ERROR',
                ],
            ];
            $json = json_encode($errorData);
            $statusCode = 500;
        }

        $response->getBody()->write($json);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
}
