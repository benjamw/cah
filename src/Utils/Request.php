<?php

declare(strict_types=1);

namespace CAH\Utils;

class Request
{
    public static function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getBody(): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getQuery(): array
    {
        return $_GET;
    }

    public static function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * @return list<string>
     */
    public static function getPathSegments(): array
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ( ! is_string($path) || $path === '') {
            return [];
        }

        return array_values(
            array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '')
        );
    }

    public static function getHeader(string $name): ?string
    {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$name] ?? null;
    }
}
