<?php

declare(strict_types=1);

/**
 * Database Configuration
 *
 * Update these values with your MySQL/MariaDB credentials
 */

return [
    'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost',
    'port' => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306,
    'database' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'cah_game',
    'username' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root',
    'password' => $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];
