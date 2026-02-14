<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Utils\Validator;

class ValidatorDeepIntegrationTest extends TestCase
{
    public function testValidatePlayerNameCoversEdgeCases(): void
    {
        $valid = Validator::validatePlayerName('  Alice Smith  ');
        $this->assertTrue($valid['valid']);
        $this->assertSame('Alice Smith', $valid['name']);

        $tooShort = Validator::validatePlayerName('AB');
        $this->assertFalse($tooShort['valid']);
        $this->assertStringContainsString('at least', (string) $tooShort['error']);

        $tooLong = Validator::validatePlayerName(str_repeat('A', 31));
        $this->assertFalse($tooLong['valid']);
        $this->assertStringContainsString('must not exceed', (string) $tooLong['error']);

        $invalidChars = Validator::validatePlayerName("Bad\x00Name");
        $this->assertFalse($invalidChars['valid']);
        $this->assertStringContainsString('invalid characters', (string) $invalidChars['error']);
    }

    public function testValidateCardIdsCoversSuccessAndFailureBranches(): void
    {
        $valid = Validator::validateCardIds([1, '2', 3]);
        $this->assertTrue($valid['valid']);
        $this->assertSame([1, 2, 3], $valid['card_ids']);

        $empty = Validator::validateCardIds([]);
        $this->assertFalse($empty['valid']);
        $this->assertStringContainsString('required', (string) $empty['error']);

        $invalidType = Validator::validateCardIds([1, ['bad']]);
        $this->assertFalse($invalidType['valid']);
        $this->assertStringContainsString('must be integers', (string) $invalidType['error']);

        $nonPositive = Validator::validateCardIds([1, 0]);
        $this->assertFalse($nonPositive['valid']);
        $this->assertStringContainsString('positive integers', (string) $nonPositive['error']);
    }

    public function testValidateGameCodeAndArrayBranches(): void
    {
        $validCode = Validator::validateGameCode('ab12');
        $this->assertTrue($validCode['valid']);
        $this->assertSame('AB12', $validCode['code']);

        $badLength = Validator::validateGameCode('ABC');
        $this->assertFalse($badLength['valid']);
        $this->assertStringContainsString('exactly', (string) $badLength['error']);

        $badChars = Validator::validateGameCode('AB-1');
        $this->assertFalse($badChars['valid']);
        $this->assertStringContainsString('letters and numbers', (string) $badChars['error']);

        $validArray = Validator::validateArray(['a' => 'x', 'b' => 0, 'c' => false], ['a', 'b', 'c']);
        $this->assertTrue($validArray['valid']);

        $missing = Validator::validateArray(['a' => 'x'], ['a', 'b']);
        $this->assertFalse($missing['valid']);
        $this->assertStringContainsString('Missing required key', (string) $missing['error']);

        $emptyRequired = Validator::validateArray(['a' => ''], ['a']);
        $this->assertFalse($emptyRequired['valid']);
        $this->assertStringContainsString('cannot be empty', (string) $emptyRequired['error']);
    }

    public function testFluentMethodsBoundsAndNullBypass(): void
    {
        $validator = new Validator();
        $validator
            ->stringLength('abcdef', 'nickname', 1, 5)
            ->integer('10', 'age', 1, 5)
            ->array([1, 2, 3], 'items', 4, 10)
            ->required(null, 'required_field')
            ->in('green', 'color', ['red', 'blue']);

        $this->assertTrue($validator->fails());
        $errors = $validator->getErrors();
        $this->assertArrayHasKey('nickname', $errors);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('items', $errors);
        $this->assertArrayHasKey('required_field', $errors);
        $this->assertArrayHasKey('color', $errors);

        // Null values should bypass non-required checks.
        $passes = new Validator();
        $passes
            ->stringLength(null, 's', 1, 2)
            ->integer(null, 'i', 1, 2)
            ->boolean(null, 'b')
            ->array(null, 'a', 1, 2)
            ->in(null, 'in', ['x']);

        $this->assertTrue($passes->passes());
    }
}

