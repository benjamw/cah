<?php

declare(strict_types=1);

/**
 * Database Migration Script
 * 
 * Runs the schema.sql file to create/reset the database tables
 * Usage: php migrate.php
 */

require __DIR__ . '/vendor/autoload.php';

use CAH\Database\Database;
use Dotenv\Dotenv;

// Load environment variables from .env file
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// Load database configuration
$dbConfig = require __DIR__ . '/config/database.php';

echo "=== Cards API Hub - Database Migration ===\n\n";
echo "Database: {$dbConfig['database']}\n";
echo "Host: {$dbConfig['host']}:{$dbConfig['port']}\n";
echo "User: {$dbConfig['username']}\n\n";

// Initialize database connection
try {
    Database::init($dbConfig);
    echo "Database connection established\n\n";
} catch (Exception $e) {
    echo "Database connection failed: {$e->getMessage()}\n";
    echo "\nPlease check your .env file or config/database.php settings.\n";
    exit(1);
}

// Read schema file
$schemaFile = __DIR__ . '/src/Database/migrations/schema.sql';
if ( ! file_exists($schemaFile)) {
    echo "Schema file not found: {$schemaFile}\n";
    exit(1);
}

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    echo "Failed to read schema file\n";
    exit(1);
}

echo "Schema file loaded\n\n";

// Split SQL into individual statements
// Remove comments and split by semicolons
$sql = preg_replace('/--.*$/m', '', $sql); // Remove single-line comments
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Remove multi-line comments
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($stmt) => ! empty($stmt)
);

echo "Found " . count($statements) . " SQL statements to execute\n\n";

// Execute each statement
$connection = Database::getConnection();
$successCount = 0;
$errorCount = 0;

foreach ($statements as $index => $statement) {
    if (empty(trim($statement))) {
        continue;
    }

    try {
        $connection->exec($statement);
        $successCount++;
        
        // Extract table name for better output
        if (preg_match('/CREATE TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
            echo "Created table: {$matches[1]}\n";
        } elseif (preg_match('/DROP TABLE\s+IF EXISTS\s+`?(\w+)`?/i', $statement, $matches)) {
            echo "Dropped table (if exists): {$matches[1]}\n";
        } else {
            echo "Executed statement " . ($index + 1) . "\n";
        }
    } catch (PDOException $e) {
        $errorCount++;
        echo "Error in statement " . ($index + 1) . ": {$e->getMessage()}\n";
        echo "   SQL: " . substr($statement, 0, 100) . "...\n";
    }
}

echo "\n=== Migration Complete ===\n";
echo "Success: {$successCount}\n";
echo "Errors: {$errorCount}\n";

if ($errorCount > 0) {
    echo "\nMigration completed with errors\n";
    exit(1);
}

echo "\nAll tables created successfully!\n";
echo "\nNext steps:\n";
echo "1. Import card data: php seed.php (if you have seed data)\n";
echo "2. Start the development server: php -S localhost:8000 -t api\n";
echo "3. Test the API: curl http://localhost:8000/api/health\n";

exit(0);
