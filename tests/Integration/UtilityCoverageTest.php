<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Services\ConfigService;
use CAH\Services\PlayerHelper;
use CAH\Tests\TestCase;
use CAH\Utils\GameCodeGenerator;
use CAH\Utils\Logger;

class UtilityCoverageTest extends TestCase
{
    public function testConfigServiceLoadsValuesAndCacheCanClear(): void
    {
        ConfigService::clearCache();

        $gameConfig = ConfigService::getGameConfig();
        $dbConfig = ConfigService::getDatabaseConfig();

        $this->assertIsArray($gameConfig);
        $this->assertIsArray($dbConfig);
        $this->assertArrayHasKey('default_max_score', $gameConfig);
        $this->assertArrayHasKey('host', $dbConfig);

        $fallback = ConfigService::getGameValue('__does_not_exist__', 'fallback');
        $this->assertSame('fallback', $fallback);
    }

    public function testPlayerHelperFindCreatorCzarAndFilterHands(): void
    {
        $playerData = [
            'creator_id' => 'p1',
            'current_czar_id' => 'p2',
            'players' => [
                ['id' => 'p1', 'name' => 'A', 'hand' => [['card_id' => 1]]],
                ['id' => 'p2', 'name' => 'B', 'hand' => [['card_id' => 2]]],
                ['id' => 'r1', 'name' => 'Rando', 'hand' => [['card_id' => 3]], 'is_rando' => true],
            ],
        ];

        $found = PlayerHelper::findPlayer($playerData, 'p2');
        $this->assertNotNull($found);
        $this->assertSame('B', $found['name']);

        $this->assertTrue(PlayerHelper::isCreator($playerData, 'p1'));
        $this->assertTrue(PlayerHelper::isCzar($playerData, 'p2'));
        $this->assertFalse(PlayerHelper::isCzar($playerData, 'p1'));

        $filtered = PlayerHelper::filterHands($playerData, 'p1');
        $this->assertNotSame('*** HIDDEN ***', $filtered['players'][0]['hand'][0]['copy'] ?? null);
        $this->assertSame('*** HIDDEN ***', $filtered['players'][1]['hand'][0]['copy']);
        $this->assertSame(3, $filtered['players'][2]['hand'][0]['card_id']); // rando remains visible
    }

    public function testGameCodeGeneratorAndLoggerPaths(): void
    {
        $code = GameCodeGenerator::generateCode();
        $this->assertTrue(GameCodeGenerator::isValid($code));
        $this->assertFalse(GameCodeGenerator::isValid('12'));
        $this->assertFalse(GameCodeGenerator::isValid('AB-1'));

        // Generate() checks DB uniqueness; should return quickly in clean test DB.
        $unique = GameCodeGenerator::generate();
        $this->assertTrue(GameCodeGenerator::isValid($unique));

        Logger::debug('coverage-debug-log');
        Logger::info('coverage-info-log');
        Logger::warning('coverage-warning-log');
        Logger::error('coverage-error-log');
        Logger::notice('coverage-notice-log');

        $this->assertTrue(true); // assertions above ensure no exceptions
    }
}

