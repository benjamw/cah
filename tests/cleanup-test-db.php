<?php
require __DIR__ . '/../vendor/autoload.php';

// Load .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

$dbConfig = require __DIR__ . '/../config/database.php';
$testHost = $_ENV['TEST_DB_HOST'] ?? getenv('TEST_DB_HOST') ?: $dbConfig['host'];
$testName = $_ENV['TEST_DB_NAME'] ?? getenv('TEST_DB_NAME') ?: null;
$testUser = $_ENV['TEST_DB_USER'] ?? getenv('TEST_DB_USER') ?: $dbConfig['username'];
$testPass = $_ENV['TEST_DB_PASS'] ?? getenv('TEST_DB_PASS') ?: $dbConfig['password'];

if ($testName === null || trim((string) $testName) === '') {
    throw new RuntimeException('TEST_DB_NAME is required for cleanup-test-db.php');
}

$pdo = new PDO(
    "mysql:host={$testHost};dbname={$testName}",
    $testUser,
    $testPass
);

echo "Cleaning test database: {$testName}\n";

$pdo->exec('DELETE FROM cards_to_tags');
$pdo->exec('DELETE FROM tags');
$pdo->exec('DELETE FROM cards');
$pdo->exec('DELETE FROM games');
$pdo->exec('DELETE FROM rate_limits');
$pdo->exec('DELETE FROM admin_sessions');

$pdo->exec('ALTER TABLE cards AUTO_INCREMENT = 1');
$pdo->exec('ALTER TABLE tags AUTO_INCREMENT = 1');

echo "Cleanup complete!\n";
