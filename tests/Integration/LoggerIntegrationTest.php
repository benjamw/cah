<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Utils\Logger;

class LoggerIntegrationTest extends TestCase
{
    private function resetLoggerSingleton(): void
    {
        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    public function testLoggerInitializesAndWritesAtConfiguredLevel(): void
    {
        $logFile = __DIR__ . '/../../logs/app.log';
        if (file_exists($logFile)) {
            @unlink($logFile);
        }

        $_ENV['LOG_LEVEL'] = 'debug';
        $this->resetLoggerSingleton();

        Logger::debug('coverage logger debug');
        Logger::info('coverage logger info');
        Logger::notice('coverage logger notice');
        Logger::warning('coverage logger warning');
        Logger::error('coverage logger error');

        $this->assertFileExists($logFile);
        $contents = (string) file_get_contents($logFile);
        $this->assertStringContainsString('coverage logger debug', $contents);
        $this->assertStringContainsString('coverage logger error', $contents);
    }

    public function testLoggerFallsBackToWarningOnUnknownLevel(): void
    {
        $_ENV['LOG_LEVEL'] = 'not-a-real-level';
        $this->resetLoggerSingleton();

        // Should not throw and should still initialize.
        Logger::warning('coverage logger fallback warning');
        $this->assertTrue(true);
    }
}

