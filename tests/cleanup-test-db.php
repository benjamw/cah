<?php
require __DIR__ . '/../vendor/autoload.php';

// Load .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

$dbConfig = require __DIR__ . '/../config/database.php';

$pdo = new PDO(
    "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']}",
    $dbConfig['username'],
    $dbConfig['password']
);

echo "Cleaning test database...\n";

$pdo->exec('DELETE FROM cards_to_tags');
$pdo->exec('DELETE FROM tags');
$pdo->exec('DELETE FROM cards');
$pdo->exec('DELETE FROM games');
$pdo->exec('DELETE FROM rate_limits');
$pdo->exec('DELETE FROM admin_sessions');

$pdo->exec('ALTER TABLE cards AUTO_INCREMENT = 1');
$pdo->exec('ALTER TABLE tags AUTO_INCREMENT = 1');

echo "Cleanup complete!\n";
