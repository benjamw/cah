<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Database\Database;
use CAH\Exceptions\DatabaseException;
use CAH\Tests\TestCase;
use PDO;
use PDOStatement;
use ReflectionClass;

class DatabaseIntegrationTest extends TestCase
{
    public function testQueryAndFetchHelpersWorkTogether(): void
    {
        $uniqueCopy = 'Coverage DB card ' . uniqid('', true);

        $inserted = Database::execute(
            "INSERT INTO cards (type, copy) VALUES ('response', ?)",
            [$uniqueCopy]
        );
        $this->assertSame(1, $inserted);

        $cardId = (int) Database::lastInsertId();
        $this->assertGreaterThan(0, $cardId);

        $stmt = Database::query('SELECT card_id, copy FROM cards WHERE card_id = ?', [$cardId]);
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $fromStatement = $stmt->fetch();
        $this->assertIsArray($fromStatement);
        $this->assertSame($uniqueCopy, $fromStatement['copy']);

        $one = Database::fetchOne('SELECT card_id, copy FROM cards WHERE card_id = ?', [$cardId]);
        $this->assertIsArray($one);
        $this->assertSame($uniqueCopy, $one['copy']);

        $all = Database::fetchAll('SELECT card_id FROM cards WHERE copy = ?', [$uniqueCopy]);
        $this->assertCount(1, $all);
        $this->assertSame($cardId, (int) $all[0]['card_id']);
    }

    public function testTransactionCommitAndRollbackChangePersistence(): void
    {
        $rollbackCopy = 'Coverage rollback ' . uniqid('', true);
        $commitCopy = 'Coverage commit ' . uniqid('', true);

        $this->assertTrue(Database::beginTransaction());
        Database::execute("INSERT INTO cards (type, copy) VALUES ('response', ?)", [$rollbackCopy]);
        $this->assertTrue(Database::rollback());

        $rolledBackRow = Database::fetchOne('SELECT card_id FROM cards WHERE copy = ?', [$rollbackCopy]);
        $this->assertFalse($rolledBackRow);

        $this->assertTrue(Database::beginTransaction());
        Database::execute("INSERT INTO cards (type, copy) VALUES ('response', ?)", [$commitCopy]);
        $this->assertTrue(Database::commit());

        $committedRow = Database::fetchOne('SELECT card_id FROM cards WHERE copy = ?', [$commitCopy]);
        $this->assertIsArray($committedRow);
    }

    public function testGetConnectionThrowsWhenConfigWasNotInitialized(): void
    {
        $reflection = new ReflectionClass(Database::class);
        $connectionProperty = $reflection->getProperty('connection');
        $configProperty = $reflection->getProperty('config');
        $connectionProperty->setAccessible(true);
        $configProperty->setAccessible(true);

        $originalConnection = $connectionProperty->getValue();
        $originalConfig = $configProperty->getValue();

        try {
            $connectionProperty->setValue(null, null);
            $configProperty->setValue(null, []);

            $this->assertThrowsException(
                DatabaseException::class,
                static fn(): PDO => Database::getConnection(),
                'Database configuration not initialized'
            );
        } finally {
            $connectionProperty->setValue(null, $originalConnection);
            $configProperty->setValue(null, $originalConfig);
        }
    }
}
