<?php

declare(strict_types=1);

/**
 * Create Test Database
 * 
 * Creates the cah_test database and runs the schema migration
 */

require __DIR__ . '/vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? 3306;
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';

echo "Creating test database...\n";
echo "Host: {$host}:{$port}\n";
echo "User: {$username}\n\n";

try {
    // Connect without specifying a database
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Create test database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS cah_game_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database 'cah_game_test' created (or already exists)\n";

    // Switch to test database
    $pdo->exec("USE cah_game_test");

    // Read and execute schema
    $schemaFile = __DIR__ . '/database/schema.sql';
    $sql = file_get_contents($schemaFile);
    
    // Remove comments and split by semicolons
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($stmt) => ! empty($stmt)
    );

    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        $pdo->exec($statement);
    }

    echo "Schema created successfully\n";
    echo "\nTest database is ready!\n";
    echo "Run tests with: ./vendor/bin/phpunit\n";

} catch (PDOException $e) {
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}
