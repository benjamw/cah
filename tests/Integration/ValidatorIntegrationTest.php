<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Utils\Validator;

class ValidatorIntegrationTest extends TestCase
{
    public function testValidateGameSettingsBranches(): void
    {
        $valid = Validator::validateGameSettings(['max_score' => 7, 'hand_size' => 10]);
        $this->assertTrue($valid['valid']);

        $invalidMaxType = Validator::validateGameSettings(['max_score' => 'abc']);
        $this->assertFalse($invalidMaxType['valid']);
        $this->assertStringContainsString('max_score must be an integer', (string) $invalidMaxType['error']);

        $invalidMaxValue = Validator::validateGameSettings(['max_score' => 0]);
        $this->assertFalse($invalidMaxValue['valid']);
        $this->assertStringContainsString('max_score must be at least 1', (string) $invalidMaxValue['error']);

        $invalidHandType = Validator::validateGameSettings(['hand_size' => 'x']);
        $this->assertFalse($invalidHandType['valid']);
        $this->assertStringContainsString('hand_size must be an integer', (string) $invalidHandType['error']);

        $invalidHandValue = Validator::validateGameSettings(['hand_size' => -1]);
        $this->assertFalse($invalidHandValue['valid']);
        $this->assertStringContainsString('hand_size must be at least 1', (string) $invalidHandValue['error']);
    }

    public function testFluentValidatorMethodsCollectErrors(): void
    {
        $validator = ( new Validator() )
            ->required('', 'name')
            ->stringLength('ab', 'username', 3, 5)
            ->integer('abc', 'age')
            ->boolean('yes', 'enabled')
            ->array('not-array', 'items')
            ->in('orange', 'color', ['red', 'blue']);

        $this->assertTrue($validator->fails());
        $this->assertFalse($validator->passes());

        $errors = $validator->getErrors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('username', $errors);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('enabled', $errors);
        $this->assertArrayHasKey('items', $errors);
        $this->assertArrayHasKey('color', $errors);
    }
}

