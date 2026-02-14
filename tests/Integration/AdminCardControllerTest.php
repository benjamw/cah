<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Controllers\AdminCardController;
use CAH\Database\Database;
use CAH\Enums\CardType;
use CAH\Models\Card;
use CAH\Models\Pack;
use CAH\Models\Tag;
use CAH\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

class AdminCardControllerTest extends TestCase
{
    private AdminCardController $controller;
    private RequestFactory $requestFactory;
    private ResponseFactory $responseFactory;
    private StreamFactory $streamFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AdminCardController();
        $this->requestFactory = new RequestFactory();
        $this->responseFactory = new ResponseFactory();
        $this->streamFactory = new StreamFactory();
    }

    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_packs WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE 'CoverageTest%')");
        Database::execute("DELETE FROM cards_to_tags WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE 'CoverageTest%')");
        Database::execute("DELETE FROM cards_to_packs WHERE pack_id IN (SELECT pack_id FROM packs WHERE name LIKE 'CoverageTest%')");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'CoverageTest%'");
        Database::execute("DELETE FROM packs WHERE name LIKE 'CoverageTest%'");
        Database::execute("DELETE FROM tags WHERE name LIKE 'CoverageTest%'");

        parent::tearDown();
    }

    private function createJsonRequest(string $method, string $uri, array $data = []): ServerRequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $uri);
        $stream = $this->streamFactory->createStream((string) json_encode($data));
        return $request->withBody($stream)->withParsedBody($data);
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testListCardsReturnsPaginatedCardsWithRelations(): void
    {
        $cardId = Card::create(CardType::RESPONSE, 'CoverageTest list card', null, true);
        $tagId = Tag::create('CoverageTest list tag');
        $packId = Pack::create('CoverageTest list pack');
        Tag::addToCard($cardId, $tagId);
        Pack::addToCard($cardId, $packId);

        $request = $this->requestFactory
            ->createRequest('GET', '/api/admin/cards')
            ->withQueryParams([
                'search' => 'CoverageTest list card',
                'type' => 'response',
                'active' => '1',
                'limit' => '10',
                'offset' => '0',
            ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->listCards($request, $response);
        $json = $this->decode($result);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('cards', $json['data']);
        $this->assertArrayHasKey('total', $json['data']);
        $this->assertSame(10, $json['data']['limit']);
        $this->assertSame(0, $json['data']['offset']);
        $this->assertNotEmpty($json['data']['cards']);

        $first = $json['data']['cards'][0];
        $this->assertArrayHasKey('tags', $first);
        $this->assertArrayHasKey('packs', $first);
    }

    public function testListCardsRejectsInvalidType(): void
    {
        $request = $this->requestFactory
            ->createRequest('GET', '/api/admin/cards')
            ->withQueryParams(['type' => 'banana']);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->listCards($request, $response);
        $json = $this->decode($result);

        $this->assertSame(422, $result->getStatusCode());
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('Invalid card type', $json['error']);
    }

    public function testListCardsRejectsInvalidPagination(): void
    {
        $request = $this->requestFactory
            ->createRequest('GET', '/api/admin/cards')
            ->withQueryParams(['limit' => '-1', 'offset' => '-5']);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->listCards($request, $response);
        $json = $this->decode($result);

        $this->assertSame(422, $result->getStatusCode());
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('Limit must be between 0 and 10000', $json['error']);
    }

    public function testGetCardReturnsCardWithTagsAndPacks(): void
    {
        $cardId = Card::create(CardType::PROMPT, 'CoverageTest get card', 1, true);
        $tagId = Tag::create('CoverageTest get tag');
        $packId = Pack::create('CoverageTest get pack');
        Tag::addToCard($cardId, $tagId);
        Pack::addToCard($cardId, $packId);

        $request = $this->requestFactory->createRequest('GET', "/api/admin/cards/{$cardId}");
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->getCard($request, $response, ['cardId' => (string) $cardId]);
        $json = $this->decode($result);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($json['success']);
        $this->assertSame($cardId, $json['data']['card']['card_id']);
        $this->assertNotEmpty($json['data']['card']['tags']);
        $this->assertNotEmpty($json['data']['card']['packs']);
    }

    public function testGetCardReturnsNotFoundForMissingCard(): void
    {
        $request = $this->requestFactory->createRequest('GET', '/api/admin/cards/999999');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->getCard($request, $response, ['cardId' => '999999']);
        $json = $this->decode($result);

        $this->assertSame(404, $result->getStatusCode());
        $this->assertFalse($json['success']);
    }

    public function testEditCardUpdatesCoreFieldsAndRelations(): void
    {
        $cardId = Card::create(CardType::RESPONSE, 'CoverageTest edit original', null, true);
        $oldTagId = Tag::create('CoverageTest edit old tag');
        $newTagId = Tag::create('CoverageTest edit new tag');
        $oldPackId = Pack::create('CoverageTest edit old pack');
        $newPackId = Pack::create('CoverageTest edit new pack');

        Tag::addToCard($cardId, $oldTagId);
        Pack::addToCard($cardId, $oldPackId);

        $request = $this->createJsonRequest('PUT', "/api/admin/cards/{$cardId}", [
            'copy' => 'CoverageTest edit updated',
            'type' => 'prompt',
            'choices' => 2,
            'active' => false,
            'tags' => [$newTagId],
            'packs' => [$newPackId],
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->editCard($request, $response, ['cardId' => (string) $cardId]);
        $json = $this->decode($result);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['updated']);

        $updated = Card::getById($cardId);
        $this->assertNotNull($updated);
        $this->assertSame('CoverageTest edit updated', $updated['copy']);
        $this->assertSame('prompt', $updated['type']);
        $this->assertSame(2, (int) $updated['choices']);
        $this->assertSame(0, (int) $updated['active']);

        $tagIds = array_column(Tag::getCardTags($cardId, false), 'tag_id');
        $packIds = array_column(Pack::getCardPacks($cardId, false), 'pack_id');
        $this->assertSame([$newTagId], array_values($tagIds));
        $this->assertSame([$newPackId], array_values($packIds));
    }

    public function testDeleteCardSoftDeletesExistingCard(): void
    {
        $cardId = Card::create(CardType::RESPONSE, 'CoverageTest delete card', null, true);

        $request = $this->requestFactory->createRequest('DELETE', "/api/admin/cards/{$cardId}");
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->deleteCard($request, $response, ['cardId' => (string) $cardId]);
        $json = $this->decode($result);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['deleted']);

        $deleted = Card::getById($cardId);
        $this->assertNotNull($deleted);
        $this->assertSame(0, (int) $deleted['active']);
    }

    public function testTagEndpointsAddAndRemoveTag(): void
    {
        $cardId = Card::create(CardType::RESPONSE, 'CoverageTest tag endpoint card', null, true);
        $tagId = Tag::create('CoverageTest endpoint tag');

        $response = $this->responseFactory->createResponse();
        $addResult = $this->controller->addCardTag(
            $this->requestFactory->createRequest('POST', "/api/admin/cards/{$cardId}/tags/{$tagId}"),
            $response,
            ['cardId' => (string) $cardId, 'tagId' => (string) $tagId]
        );
        $addJson = $this->decode($addResult);
        $this->assertTrue($addJson['success']);
        $this->assertTrue($addJson['data']['added']);

        $getResult = $this->controller->getCardTags(
            $this->requestFactory->createRequest('GET', "/api/admin/cards/{$cardId}/tags"),
            $this->responseFactory->createResponse(),
            ['cardId' => (string) $cardId]
        );
        $getJson = $this->decode($getResult);
        $this->assertTrue($getJson['success']);
        $this->assertNotEmpty($getJson['data']['tags']);

        $removeResult = $this->controller->removeCardTag(
            $this->requestFactory->createRequest('DELETE', "/api/admin/cards/{$cardId}/tags/{$tagId}"),
            $this->responseFactory->createResponse(),
            ['cardId' => (string) $cardId, 'tagId' => (string) $tagId]
        );
        $removeJson = $this->decode($removeResult);
        $this->assertTrue($removeJson['success']);
        $this->assertTrue($removeJson['data']['removed']);
    }

    public function testPackEndpointsAddAndRemovePack(): void
    {
        $cardId = Card::create(CardType::RESPONSE, 'CoverageTest pack endpoint card', null, true);
        $packId = Pack::create('CoverageTest endpoint pack');

        $addResult = $this->controller->addCardPack(
            $this->requestFactory->createRequest('POST', "/api/admin/cards/{$cardId}/packs/{$packId}"),
            $this->responseFactory->createResponse(),
            ['cardId' => (string) $cardId, 'packId' => (string) $packId]
        );
        $addJson = $this->decode($addResult);
        $this->assertTrue($addJson['success']);
        $this->assertTrue($addJson['data']['added']);

        $getResult = $this->controller->getCardPacks(
            $this->requestFactory->createRequest('GET', "/api/admin/cards/{$cardId}/packs"),
            $this->responseFactory->createResponse(),
            ['cardId' => (string) $cardId]
        );
        $getJson = $this->decode($getResult);
        $this->assertTrue($getJson['success']);
        $this->assertNotEmpty($getJson['data']['packs']);

        $removeResult = $this->controller->removeCardPack(
            $this->requestFactory->createRequest('DELETE', "/api/admin/cards/{$cardId}/packs/{$packId}"),
            $this->responseFactory->createResponse(),
            ['cardId' => (string) $cardId, 'packId' => (string) $packId]
        );
        $removeJson = $this->decode($removeResult);
        $this->assertTrue($removeJson['success']);
        $this->assertTrue($removeJson['data']['removed']);
    }
}

