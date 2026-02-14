<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Enums\CardType;
use CAH\Models\Card;
use CAH\Models\Tag;
use CAH\Services\CardImportService;
use CAH\Tests\TestCase;
use CAH\Database\Database;

class CardImportServiceCoverageTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_tags WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE 'CoverageImport%')");
        Database::execute("DELETE FROM cards_to_tags WHERE tag_id IN (SELECT tag_id FROM tags WHERE name LIKE 'CoverageImport%')");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'CoverageImport%'");
        Database::execute("DELETE FROM tags WHERE name LIKE 'CoverageImport%'");
        parent::tearDown();
    }

    public function testParsePromptChoicesAndImportBatch(): void
    {
        $this->assertSame(1, CardImportService::parsePromptCardChoices('CoverageImport no blanks'));
        $this->assertSame(1, CardImportService::parsePromptCardChoices('CoverageImport one ____ blank'));
        $this->assertSame(2, CardImportService::parsePromptCardChoices('CoverageImport ____ and ____'));
        $this->assertSame(3, CardImportService::parsePromptCardChoices('CoverageImport ____, ____, and ____'));

        $result = CardImportService::importBatch([
            ['type' => CardType::RESPONSE, 'copy' => 'CoverageImport batch response'],
            ['type' => CardType::PROMPT, 'copy' => 'CoverageImport batch prompt ____ and ____', 'choices' => null],
            ['copy' => 'CoverageImport missing type'],
        ]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testImportFromJsonHandlesMissingInvalidAndValidFiles(): void
    {
        $missingPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coverageimport_missing_' . uniqid('', true) . '.json';
        $missing = CardImportService::importFromJson($missingPath);
        $this->assertSame(0, $missing['imported']);
        $this->assertStringContainsString('File not found', $missing['errors'][0]);

        $invalidPath = tempnam(sys_get_temp_dir(), 'coverageimport_invalid_');
        file_put_contents($invalidPath, '{bad json');
        $invalid = CardImportService::importFromJson($invalidPath);
        @unlink($invalidPath);
        $this->assertSame(0, $invalid['imported']);
        $this->assertStringContainsString('Invalid JSON', $invalid['errors'][0]);

        $validPath = tempnam(sys_get_temp_dir(), 'coverageimport_valid_');
        $payload = json_encode([], JSON_THROW_ON_ERROR);
        file_put_contents($validPath, $payload);

        $valid = CardImportService::importFromJson($validPath);
        @unlink($validPath);

        $this->assertSame(0, $valid['imported']);
        $this->assertSame(0, $valid['failed']);
        $this->assertSame([], $valid['errors']);
    }

    public function testFixPromptCardChoicesUpdatesIncorrectChoices(): void
    {
        $promptId = Card::create(CardType::PROMPT, 'CoverageImport fix ____ and ____', 1, true);
        $this->assertIsInt($promptId);

        $result = CardImportService::fixPromptCardChoices();
        $this->assertGreaterThanOrEqual(1, $result['updated']);
        $this->assertSame([], $result['errors']);

        $prompt = Card::getById($promptId);
        $this->assertSame(2, (int) $prompt['choices']);
    }

    public function testImportFromCsvProcessesTagsAndWarnings(): void
    {
        $existingTagId = Tag::create('CoverageImport Existing', null, true);

        $csv = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csv .= "CoverageImport csv one,{$existingTagId},CoverageImport NewTag,999999,,,,,,,\n";
        $csv .= "CoverageImport csv two,COVERAGEIMPORT EXISTING,,,,,,,,,\n";
        $csv .= ",,,,,,,,,,\n";

        $result = CardImportService::importFromCsv($csv, CardType::RESPONSE);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $this->assertNotEmpty($result['warnings']);

        $cards = Card::getActiveByType(CardType::RESPONSE);
        $targetCards = array_values(array_filter(
            $cards,
            static fn(array $card): bool => str_starts_with((string) $card['copy'], 'CoverageImport csv')
        ));
        $this->assertCount(2, $targetCards);

        $firstCard = null;
        foreach ($targetCards as $card) {
            if ($card['copy'] === 'CoverageImport csv one') {
                $firstCard = $card;
                break;
            }
        }

        $this->assertNotNull($firstCard);
        $tags = Tag::getCardTags((int) $firstCard['card_id'], false);
        $tagNames = array_map(static fn(array $tag): string => (string) $tag['name'], $tags);
        $this->assertContains('CoverageImport Existing', $tagNames);
        $this->assertContains('CoverageImport NewTag', $tagNames);
    }
}
