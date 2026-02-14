<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Database\Database;
use CAH\Enums\CardType;
use CAH\Models\Card;
use CAH\Models\Tag;
use CAH\Tests\TestCase;

class TagModelIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_tags WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE 'CoverageTag%')");
        Database::execute("DELETE FROM cards_to_tags WHERE tag_id IN (SELECT tag_id FROM tags WHERE name LIKE 'CoverageTag%')");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'CoverageTag%'");
        Database::execute("DELETE FROM tags WHERE name LIKE 'CoverageTag%'");
        parent::tearDown();
    }

    public function testCrudAndLookupMethods(): void
    {
        $tagId = Tag::create('CoverageTag Alpha', 'desc', true);
        $this->assertGreaterThan(0, $tagId);

        $found = Tag::find($tagId);
        $this->assertNotNull($found);
        $this->assertSame('CoverageTag Alpha', $found['name']);

        $updated = Tag::update($tagId, ['name' => 'CoverageTag Alpha Updated', 'active' => 0]);
        $this->assertSame(1, $updated);
        $this->assertSame(0, (int) Tag::find($tagId)['active']);

        $this->assertSame(1, Tag::update($tagId, ['active' => 1]));
        $this->assertSame(1, Tag::softDelete($tagId));
        $this->assertSame(0, (int) Tag::find($tagId)['active']);

        $all = Tag::getAll();
        $allIds = array_column($all, 'tag_id');
        $this->assertContains($tagId, $allIds);
    }

    public function testFindManyAndActiveQueries(): void
    {
        $activeTag = Tag::create('CoverageTag Active', null, true);
        $inactiveTag = Tag::create('CoverageTag Inactive', null, false);

        $many = Tag::findMany([$activeTag, $inactiveTag]);
        $ids = array_column($many, 'tag_id');
        $this->assertContains($activeTag, $ids);
        $this->assertContains($inactiveTag, $ids);
        $this->assertSame([], Tag::findMany([]));

        $active = Tag::getAllActive();
        $activeIds = array_column($active, 'tag_id');
        $this->assertContains($activeTag, $activeIds);
        $this->assertNotContains($inactiveTag, $activeIds);
    }

    public function testAssociationAndCountMethods(): void
    {
        $tag1 = Tag::create('CoverageTag Assoc 1', null, true);
        $tag2 = Tag::create('CoverageTag Assoc 2', null, true);
        $card1 = Card::create(CardType::RESPONSE, 'CoverageTag card 1', null, true);
        $card2 = Card::create(CardType::PROMPT, 'CoverageTag card 2', 1, true);

        $this->assertTrue(Tag::addToCard($card1, $tag1));
        $this->assertFalse(Tag::addToCard($card1, $tag1)); // duplicate path
        $this->assertSame(1, Tag::addMultipleTagsToCard($card1, [$tag2]));
        $this->assertSame(0, Tag::addMultipleTagsToCard($card1, []));

        $this->assertSame(2, Tag::addTagToMultipleCards([$card1, $card2], $tag1));
        $this->assertSame(0, Tag::addTagToMultipleCards([], $tag1));

        $card1TagsAll = Tag::getCardTags($card1, false);
        $this->assertGreaterThanOrEqual(2, count($card1TagsAll));

        $this->assertSame(1, Tag::removeFromCard($card1, $tag2));
        $remainingTagIds = array_column(Tag::getCardTags($card1, false), 'tag_id');
        $this->assertNotContains($tag2, $remainingTagIds);

        $grouped = Tag::getCardTagsForMultipleCards([$card1, $card2], false);
        $this->assertArrayHasKey($card1, $grouped);
        $this->assertArrayHasKey($card2, $grouped);
        $this->assertSame([], Tag::getCardTagsForMultipleCards([]));

        $this->assertGreaterThanOrEqual(1, Tag::getCardCount($tag1));
        $this->assertGreaterThanOrEqual(1, Tag::getCardCount($tag1, CardType::RESPONSE));
        $this->assertGreaterThanOrEqual(1, Tag::getCardCount($tag1, CardType::PROMPT));
    }

    public function testGetAllActiveWithCountsIncludesOurTagRows(): void
    {
        $tagId = Tag::create('CoverageTag Count Row', null, true);
        $cardResponse = Card::create(CardType::RESPONSE, 'CoverageTag count response', null, true);
        $cardPrompt = Card::create(CardType::PROMPT, 'CoverageTag count prompt', 1, true);
        Tag::addToCard($cardResponse, $tagId);
        Tag::addToCard($cardPrompt, $tagId);

        $rows = Tag::getAllActiveWithCounts();
        $target = null;
        foreach ($rows as $row) {
            if ((int) $row['tag_id'] === $tagId) {
                $target = $row;
                break;
            }
        }

        $this->assertNotNull($target);
        $this->assertGreaterThanOrEqual(1, (int) $target['response_card_count']);
        $this->assertGreaterThanOrEqual(1, (int) $target['prompt_card_count']);
        $this->assertGreaterThanOrEqual(2, (int) $target['total_card_count']);
    }
}

