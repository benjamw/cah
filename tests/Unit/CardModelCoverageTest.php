<?php

declare(strict_types=1);

namespace CAH\Tests\Unit;

use CAH\Enums\CardType;
use CAH\Models\Card;
use CAH\Models\Pack;
use CAH\Models\Tag;
use CAH\Tests\TestCase;
use CAH\Database\Database;

class CardModelCoverageTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_packs WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE 'CoverageCard%')");
        Database::execute("DELETE FROM cards_to_tags WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE 'CoverageCard%')");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'CoverageCard%'");
        Database::execute("DELETE FROM packs WHERE name LIKE 'CoverageCard%'");
        Database::execute("DELETE FROM tags WHERE name LIKE 'CoverageCard%'");

        parent::tearDown();
    }

    public function testGetByIdAndGetByIds(): void
    {
        $id1 = Card::create(CardType::RESPONSE, 'CoverageCard getByIds one');
        $id2 = Card::create(CardType::PROMPT, 'CoverageCard getByIds two', 1);

        $one = Card::getById($id1);
        $many = Card::getByIds([$id1, $id2]);

        $this->assertNotNull($one);
        $this->assertSame($id1, (int) $one['card_id']);
        $this->assertCount(2, $many);
    }

    public function testUpdateAndSoftDelete(): void
    {
        $id = Card::create(CardType::RESPONSE, 'CoverageCard update before');

        $affected = Card::update($id, [
            'copy' => 'CoverageCard update after',
            'type' => 'prompt',
            'choices' => 2,
            'active' => 1,
        ]);
        $this->assertSame(1, $affected);

        $updated = Card::getById($id);
        $this->assertNotNull($updated);
        $this->assertSame('CoverageCard update after', $updated['copy']);
        $this->assertSame('prompt', $updated['type']);
        $this->assertSame(2, (int) $updated['choices']);

        $deleted = Card::softDelete($id);
        $this->assertSame(1, $deleted);
        $afterDelete = Card::getById($id);
        $this->assertSame(0, (int) $afterDelete['active']);
    }

    public function testListWithFiltersSupportsSearchAndPagination(): void
    {
        Card::create(CardType::RESPONSE, 'CoverageCard search apple');
        Card::create(CardType::RESPONSE, 'CoverageCard search banana');
        Card::create(CardType::PROMPT, 'CoverageCard search prompt', 1);

        $result = Card::listWithFilters(
            CardType::RESPONSE,
            null,
            false,
            null,
            null,
            false,
            null,
            'CoverageCard apple',
            true,
            10,
            0
        );

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $this->assertNotEmpty($result['cards']);
        $this->assertSame('response', $result['cards'][0]['type']);
    }

    public function testListWithFiltersNoTagsAndPackFilters(): void
    {
        $cardWithNoTags = Card::create(CardType::RESPONSE, 'CoverageCard no tags');
        $cardWithTag = Card::create(CardType::RESPONSE, 'CoverageCard has tag');
        $tagId = Tag::create('CoverageCard filter tag');
        Tag::addToCard($cardWithTag, $tagId);

        $packId = Pack::create('CoverageCard filter pack');
        Pack::addToCard($cardWithNoTags, $packId);

        $noTagsResult = Card::listWithFilters(
            CardType::RESPONSE,
            null,
            true,
            null,
            null,
            false,
            null,
            'CoverageCard',
            true,
            0,
            0
        );

        $packResult = Card::listWithFilters(
            CardType::RESPONSE,
            null,
            false,
            null,
            $packId,
            false,
            null,
            'CoverageCard',
            true,
            0,
            0
        );

        $noTagIds = array_column($noTagsResult['cards'], 'card_id');
        $packIds = array_column($packResult['cards'], 'card_id');
        $this->assertContains($cardWithNoTags, $noTagIds);
        $this->assertNotContains($cardWithTag, $noTagIds);
        $this->assertContains($cardWithNoTags, $packIds);
    }

    public function testGetRandomPromptCardAndResponseCards(): void
    {
        Card::create(CardType::PROMPT, 'CoverageCard random prompt', 1);
        Card::create(CardType::RESPONSE, 'CoverageCard random response 1');
        Card::create(CardType::RESPONSE, 'CoverageCard random response 2');
        Card::create(CardType::RESPONSE, 'CoverageCard random response 3');

        $prompt = Card::getRandomPromptCard(false);
        $responses = Card::getRandomResponseCards(2, false);

        $this->assertNotNull($prompt);
        $this->assertSame('prompt', $prompt['type']);
        $this->assertCount(2, $responses);
        foreach ($responses as $response) {
            $this->assertSame('response', $response['type']);
        }
    }
}

