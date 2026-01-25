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

// Initialize database
Database::init($dbConfig);

// Clean up any leftover test data first
$connection = Database::getConnection();
try {
    $connection->exec("DELETE FROM cards_to_tags");
    $connection->exec("DELETE FROM tags WHERE name = 'test_base'");
    $connection->exec("DELETE FROM cards");
    $connection->exec("DELETE FROM games");
    $connection->exec("DELETE FROM rate_limits");
    $connection->exec("DELETE FROM admin_sessions");
    
    // Reset auto-increment counters
    $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
    $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");
} catch (\Exception $e) {
    // Ignore errors if tables are already empty
}

// Seed test data
echo "Seeding test data...\n";

// Insert test white cards (increased for testing with multiple players)
$whiteCards = [];
for ($i = 1; $i <= 300; $i++) {
    $whiteCards[] = sprintf('White Card %03d', $i);
}

$stmt = $connection->prepare("INSERT INTO cards (card_type, value) VALUES ('white', ?)");
foreach ($whiteCards as $text) {
    $stmt->execute([$text]);
}

// Insert test black cards (increased for multi-round testing)
$blackCards = [];
// Add cards that require 1 choice
for ($i = 1; $i <= 40; $i++) {
    $blackCards[] = ['value' => sprintf('Black Card %03d with ____.', $i), 'choices' => 1];
}
// Add cards that require 2 choices
for ($i = 41; $i <= 55; $i++) {
    $blackCards[] = ['value' => sprintf('Black Card %03d with ____ and ____.', $i), 'choices' => 2];
}
// Add cards that require 3 choices
for ($i = 56; $i <= 70; $i++) {
    $blackCards[] = ['value' => sprintf('Black Card %03d with ____, ____, and ____.', $i), 'choices' => 3];
}

$stmt = $connection->prepare("INSERT INTO cards (card_type, value, choices) VALUES ('black', ?, ?)");
foreach ($blackCards as $card) {
    $stmt->execute([$card['value'], $card['choices']]);
}

// Insert a test tag
$connection->exec("INSERT INTO tags (name) VALUES ('test_base')");
$tagId = $connection->lastInsertId();

// Define test tag ID as a constant for use in tests
define('TEST_TAG_ID', (int) $tagId);

// Tag all cards with test_base - get actual card IDs from database
$whiteCardCount = count($whiteCards);
$blackCardCount = count($blackCards);
$cardIds = $connection->query("SELECT card_id FROM cards ORDER BY card_id")->fetchAll(PDO::FETCH_COLUMN);
$stmt = $connection->prepare("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)");
foreach ($cardIds as $cardId) {
    $stmt->execute([$cardId, $tagId]);
}

echo "Seeded {$whiteCardCount} white cards\n";
echo "Seeded {$blackCardCount} black cards\n";
echo "Created test_base tag (ID: {$tagId})\n";
echo "Test data ready!\n\n";

// Register shutdown function to clean up test data
register_shutdown_function(function () use ($connection) {
    echo "\nCleaning up test data...\n";
    
    try {
        // Delete all test data
        $connection->exec("DELETE FROM cards_to_tags");
        $connection->exec("DELETE FROM tags");
        $connection->exec("DELETE FROM cards");
        $connection->exec("DELETE FROM games");
        $connection->exec("DELETE FROM rate_limits");
        $connection->exec("DELETE FROM admin_sessions");
        
        // Reset auto-increment
        $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
        $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");
        $connection->exec("ALTER TABLE games AUTO_INCREMENT = 1");
        
        echo "Test data cleaned up\n";
    } catch (Exception $e) {
        echo "Error cleaning up: {$e->getMessage()}\n";
    }
});
