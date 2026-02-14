<?php

declare(strict_types=1);

/**
 * Database Configuration
 *
 * Loads DB settings from environment and fails fast when required values are missing.
 */

/**
 * Read env var from $_ENV or getenv and normalize empty strings to null.
 */
$readEnv = static function (string $key): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
};

$host = $readEnv('DB_HOST') ?? 'localhost';
$portRaw = $readEnv('DB_PORT') ?? '3306';
$database = $readEnv('DB_NAME');
$username = $readEnv('DB_USER');
$password = $readEnv('DB_PASS');

$missing = [];
if ($database === null) {
    $missing[] = 'DB_NAME';
}
if ($username === null) {
    $missing[] = 'DB_USER';
}
if ($password === null) {
    $missing[] = 'DB_PASS';
}

if ($missing !== []) {
    throw new RuntimeException(
        'Missing required database environment variables: ' . implode(', ', $missing)
    );
}

$port = filter_var($portRaw, FILTER_VALIDATE_INT);
if ($port === false || $port <= 0 || $port > 65535) {
    throw new RuntimeException('Invalid DB_PORT value: ' . $portRaw);
}

return [
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
];
