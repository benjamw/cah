<?php

namespace CAH\Utils;

class Request
{
    public static function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function getBody(): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        return $data ?? [];
    }

    public static function getQuery(): array
    {
        return $_GET;
    }

    public static function getQueryParam(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public static function getPathSegments(): array
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        return array_values(array_filter(explode('/', $path)));
    }

    public static function getHeader(string $name): ?string
    {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$name] ?? null;
    }
}
