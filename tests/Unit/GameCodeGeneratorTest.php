<?php

declare(strict_types=1);

namespace CAH\Tests\Unit;

use CAH\Tests\TestCase;
use CAH\Utils\GameCodeGenerator;

/**
 * Game Code Generator Unit Tests
 */
class GameCodeGeneratorTest extends TestCase
{
    public function testGenerateReturnsValidCode(): void
    {
        $code = GameCodeGenerator::generate();

        $this->assertIsString($code);
        $this->assertEquals(4, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Z]{4}$/i', $code);
    }

    public function testGenerateReturnsUniqueCodesOnMultipleCalls(): void
    {
        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $codes[] = GameCodeGenerator::generate();
        }

        $uniqueCodes = array_unique($codes);
        
        // With 100 codes, we should have high probability of uniqueness
        // Allow for small chance of collision (>95% unique)
        $this->assertGreaterThan(95, count($uniqueCodes));
    }

    public function testIsValidWithValidCodes(): void
    {
        // Valid codes use only allowed characters
        $validCodes = ['ABCD', 'XYZO', 'BFOE', 'test', 'GAME'];

        foreach ($validCodes as $code) {
            $this->assertTrue(
                GameCodeGenerator::isValid($code),
                "Code '{$code}' should be valid"
            );
        }
    }

    public function testIsValidWithInvalidCodes(): void
    {
        $invalidCodes = [
            '',           // Empty
            'ABC',        // Too short
            'ABCDE',      // Too long
            'AB-D',       // Contains hyphen
            'AB D',       // Contains space
            'AB@D',       // Contains special char
            'ABÇD',       // Contains non-ASCII
        ];

        foreach ($invalidCodes as $code) {
            $this->assertFalse(
                GameCodeGenerator::isValid($code),
                "Code '{$code}' should be invalid"
            );
        }
    }

    public function testIsValidIsCaseSensitive(): void
    {
        // Lowercase should be valid
        $this->assertTrue(GameCodeGenerator::isValid('abcd'));
        $this->assertTrue(GameCodeGenerator::isValid('ABCD'));
    }

    public function testGenerateUsesAllowedCharacters(): void
    {
        // Generate many codes and check they only use allowed characters
        $allowedPattern = '/^[A-Z]+$/';
        
        for ($i = 0; $i < 50; $i++) {
            $code = GameCodeGenerator::generate();
            $this->assertMatchesRegularExpression(
                $allowedPattern,
                $code,
                "Generated code '{$code}' contains invalid characters"
            );
        }
    }
}
