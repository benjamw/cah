<?php

declare(strict_types=1);

namespace CAH\Tests\Unit;

use CAH\Tests\TestCase;
use CAH\Utils\Validator;

/**
 * Validator Unit Tests
 */
class ValidatorTest extends TestCase
{
    public function testValidatePlayerNameWithValidNames(): void
    {
        $validNames = [
            'John',
            'Alice Smith',
            'José García',
            'François',
            'Müller',
            'O\'Brien',
            'Jean-Pierre',
            'Mary Jane',
        ];

        foreach ($validNames as $name) {
            $result = Validator::validatePlayerName($name);
            $this->assertTrue($result['valid'], "Name '{$name}' should be valid");
            $this->assertEquals(trim($name), $result['name']);
        }
    }

    public function testValidatePlayerNameWithInvalidNames(): void
    {
        $testCases = [
            ['name' => '', 'expectedError' => 'Player name is required'],
            ['name' => '  ', 'expectedError' => 'Player name is required'],
            ['name' => 'AB', 'expectedError' => 'Player name must be at least 3 characters'],
            ['name' => 'A', 'expectedError' => 'Player name must be at least 3 characters'],
            ['name' => '123', 'expectedError' => 'Player name must contain only letters'],
            ['name' => 'Player123', 'expectedError' => 'Player name must contain only letters'],
            ['name' => 'Test@User', 'expectedError' => 'Player name must contain only letters'],
            ['name' => str_repeat('A', 31), 'expectedError' => 'Player name must not exceed 30 characters'],
        ];

        foreach ($testCases as $testCase) {
            $result = Validator::validatePlayerName($testCase['name']);
            $this->assertFalse($result['valid'], "Name '{$testCase['name']}' should be invalid");
            $this->assertStringContainsString($testCase['expectedError'], $result['error']);
        }
    }

    public function testValidatePlayerNameTrimsWhitespace(): void
    {
        $result = Validator::validatePlayerName('  John Doe  ');
        $this->assertTrue($result['valid']);
        $this->assertEquals('John Doe', $result['name']);
    }

    public function testValidateGameCodeWithValidCodes(): void
    {
        $validCodes = ['ABCD', 'XYZ9', '2345', 'TEST'];

        foreach ($validCodes as $code) {
            $result = Validator::validateGameCode($code);
            $this->assertTrue($result['valid'], "Code '{$code}' should be valid");
            $this->assertEquals(strtoupper($code), $result['code']);
        }
    }

    public function testValidateGameCodeWithInvalidCodes(): void
    {
        $invalidCodes = [
            '' => 'Game code is required',
            '   ' => 'Game code is required',
            'ABC' => 'Game code must be exactly 4 characters',
            'ABCDE' => 'Game code must be exactly 4 characters',
            'AB-D' => 'Game code must contain only letters and numbers',
            'AB D' => 'Game code must contain only letters and numbers',
        ];

        foreach ($invalidCodes as $code => $expectedError) {
            $result = Validator::validateGameCode($code);
            $this->assertFalse($result['valid'], "Code '{$code}' should be invalid");
            $this->assertStringContainsString($expectedError, $result['error']);
        }
    }

    public function testValidateGameCodeConvertsToUppercase(): void
    {
        $result = Validator::validateGameCode('abcd');
        $this->assertTrue($result['valid']);
        $this->assertEquals('ABCD', $result['code']);
    }

    public function testValidateArrayWithValidData(): void
    {
        $data = ['key1' => 'value1', 'key2' => 'value2'];
        $requiredKeys = ['key1', 'key2'];

        $result = Validator::validateArray($data, $requiredKeys);
        $this->assertTrue($result['valid']);
    }

    public function testValidateArrayWithMissingKeys(): void
    {
        $data = ['key1' => 'value1'];
        $requiredKeys = ['key1', 'key2', 'key3'];

        $result = Validator::validateArray($data, $requiredKeys);
        $this->assertFalse($result['valid']);
        // Should fail on first missing key (key2)
        $this->assertStringContainsString('Missing required key', $result['error']);
        $this->assertStringContainsString('key2', $result['error']);
    }

    public function testValidateArrayWithEmptyRequiredKeys(): void
    {
        $data = ['key1' => 'value1'];
        $requiredKeys = [];

        $result = Validator::validateArray($data, $requiredKeys);
        $this->assertTrue($result['valid']);
    }

    public function testValidateCardIdsWithValidData(): void
    {
        $cardIds = [1, 2, 3, 4, 5];
        $result = Validator::validateCardIds($cardIds);
        $this->assertTrue($result['valid']);
    }

    public function testValidateCardIdsWithInvalidData(): void
    {
        $testCases = [
            [
                'cardIds' => [],
                'expectedError' => 'Card IDs are required',
            ],
            [
                'cardIds' => [1, 'two', 3],
                'expectedError' => 'All card IDs must be integers',
            ],
            [
                'cardIds' => [1, 2, -1],
                'expectedError' => 'All card IDs must be positive integers',
            ],
            [
                'cardIds' => [1, 2, 0],
                'expectedError' => 'All card IDs must be positive integers',
            ],
        ];

        foreach ($testCases as $testCase) {
            $result = Validator::validateCardIds($testCase['cardIds']);
            $this->assertFalse($result['valid']);
            $this->assertStringContainsString($testCase['expectedError'], $result['error']);
        }
    }
}
