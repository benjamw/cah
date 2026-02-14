<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 * 
 * Loads environment, sets up database, and seeds test data
 */

// Enable all error reporting for tests
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use CAH\Database\Database;
use Dotenv\Dotenv;

// Load environment variables from .env file
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'UTC');

// Load database config
$dbConfig = require __DIR__ . '/../config/database.php';

// Override with test database settings from phpunit.xml
$dbConfig['host'] = $_ENV['TEST_DB_HOST'] ?? getenv('TEST_DB_HOST') ?: $dbConfig['host'];
$dbConfig['database'] = $_ENV['TEST_DB_NAME'] ?? getenv('TEST_DB_NAME') ?: $dbConfig['database'];
$dbConfig['username'] = $_ENV['TEST_DB_USER'] ?? getenv('TEST_DB_USER') ?: $dbConfig['username'];
$dbConfig['password'] = $_ENV['TEST_DB_PASS'] ?? getenv('TEST_DB_PASS') ?: $dbConfig['password'];

// Initialize database
Database::init($dbConfig);

// Ensure test schema has required card columns used by current model code.
// This keeps tests resilient when local test DB lags behind schema.sql.
$connection = Database::getConnection();
try {
    $columnExists = static function (\PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    };

    if (! $columnExists($connection, 'cards', 'special')) {
        $connection->exec("ALTER TABLE cards ADD COLUMN special VARCHAR(255) NULL DEFAULT NULL");
    }
    if (! $columnExists($connection, 'cards', 'notes')) {
        $connection->exec("ALTER TABLE cards ADD COLUMN notes TEXT NULL DEFAULT NULL");
    }
    if (! $columnExists($connection, 'cards', 'metadata')) {
        $connection->exec("ALTER TABLE cards ADD COLUMN metadata TEXT NULL DEFAULT NULL");
    }
} catch (\Exception $e) {
    // Let tests fail naturally later with clearer DB errors if migration fails
}

// Clean up any leftover test data first
try {
    $connection->exec("DELETE FROM cards_to_packs");
    $connection->exec("DELETE FROM cards_to_tags");
    $connection->exec("DELETE FROM packs");
    $connection->exec("DELETE FROM tags WHERE name = 'test_base'");
    $connection->exec("DELETE FROM cards");
    $connection->exec("DELETE FROM games");
    $connection->exec("DELETE FROM rate_limits");
    $connection->exec("DELETE FROM admin_sessions");

    // Reset auto-increment counters
    $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
    $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");
    $connection->exec("ALTER TABLE packs AUTO_INCREMENT = 1");
} catch (\Exception $e) {
    // Ignore errors if tables are already empty
}

// Seed test data
echo "Seeding test data...\n";

// Insert test response cards (increased for testing with multiple players)
$responseCards = [];
for ($i = 1; $i <= 300; $i++) {
    $responseCards[] = sprintf('White Card %03d', $i);
}

$stmt = $connection->prepare("INSERT INTO cards (type, copy) VALUES ('response', ?)");
foreach ($responseCards as $text) {
    $stmt->execute([$text]);
}

// Insert test prompt cards (increased for multi-round testing)
$promptCards = [];
// Add cards that require 1 choice
for ($i = 1; $i <= 40; $i++) {
    $promptCards[] = ['copy' => sprintf('Black Card %03d with ____.', $i), 'choices' => 1];
}
// Add cards that require 2 choices
for ($i = 41; $i <= 55; $i++) {
    $promptCards[] = ['copy' => sprintf('Black Card %03d with ____ and ____.', $i), 'choices' => 2];
}
// Add cards that require 3 choices
for ($i = 56; $i <= 70; $i++) {
    $promptCards[] = ['copy' => sprintf('Black Card %03d with ____, ____, and ____.', $i), 'choices' => 3];
}

$stmt = $connection->prepare("INSERT INTO cards (type, copy, choices) VALUES ('prompt', ?, ?)");
foreach ($promptCards as $card) {
    $stmt->execute([$card['copy'], $card['choices']]);
}

// Insert a test tag
$connection->exec("INSERT INTO tags (name) VALUES ('test_base')");
$tagId = $connection->lastInsertId();

// Define test tag ID as a constant for use in tests
define('TEST_TAG_ID', (int) $tagId);

// Tag all cards with test_base - get actual card IDs from database
$responseCardCount = count($responseCards);
$promptCardCount = count($promptCards);
$cardIds = $connection->query("SELECT card_id FROM cards ORDER BY card_id")->fetchAll(PDO::FETCH_COLUMN);
$stmt = $connection->prepare("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)");
foreach ($cardIds as $cardId) {
    $stmt->execute([$cardId, $tagId]);
}

echo "Seeded {$responseCardCount} response cards\n";
echo "Seeded {$promptCardCount} prompt cards\n";
echo "Created test_base tag (ID: {$tagId})\n";
echo "Test data ready!\n\n";

// Register shutdown function to clean up test data
register_shutdown_function(function () use ($connection) {
    echo "\nCleaning up test data...\n";

    try {
        // Delete all test data
        $connection->exec("DELETE FROM cards_to_packs");
        $connection->exec("DELETE FROM cards_to_tags");
        $connection->exec("DELETE FROM packs");
        $connection->exec("DELETE FROM tags");
        $connection->exec("DELETE FROM cards");
        $connection->exec("DELETE FROM games");
        $connection->exec("DELETE FROM rate_limits");
        $connection->exec("DELETE FROM admin_sessions");

        // Reset auto-increment
        $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
        $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");
        $connection->exec("ALTER TABLE packs AUTO_INCREMENT = 1");
        $connection->exec("ALTER TABLE games AUTO_INCREMENT = 1");

        echo "Test data cleaned up\n";
    } catch (Exception $e) {
        echo "Error cleaning up: {$e->getMessage()}\n";
    }
});
