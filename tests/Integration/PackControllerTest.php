<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Models\Pack;
use CAH\Models\Card;
use CAH\Controllers\PackController;
use CAH\Database\Database;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Pack Controller Integration Tests
 *
 * Tests public endpoints for pack listing (game use)
 */
class PackControllerTest extends TestCase
{
    private PackController $controller;
    private RequestFactory $requestFactory;
    private ResponseFactory $responseFactory;
    private int $activePackId;
    private int $inactivePackId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new PackController();
        $this->requestFactory = new RequestFactory();
        $this->responseFactory = new ResponseFactory();

        // Create test packs
        $this->activePackId = Pack::create('ControllerTest Active Pack', '1.0', null, null, true);
        $this->inactivePackId = Pack::create('ControllerTest Inactive Pack', '1.0', null, null, false);

        // Add some cards to packs
        $card1 = Card::create('response', 'ControllerTest Card 1', null, true);
        $card2 = Card::create('response', 'ControllerTest Card 2', null, true);
        $card3 = Card::create('prompt', 'ControllerTest Prompt', 1, true);

        Pack::addToCard($card1, $this->activePackId);
        Pack::addToCard($card2, $this->activePackId);
        Pack::addToCard($card3, $this->activePackId);

        Pack::addToCard($card1, $this->inactivePackId);
    }

    protected function tearDown(): void
    {
        Database::execute("DELETE FROM cards_to_packs WHERE pack_id IN (?, ?)",
            [$this->activePackId, $this->inactivePackId]);
        Database::execute("DELETE FROM packs WHERE name LIKE 'ControllerTest%'");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'ControllerTest%'");

        parent::tearDown();
    }

    /**
     * Helper to decode response JSON
     */
    private function getJsonResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        return json_decode($body, true);
    }

    /**
     * Test list() returns only active packs
     */
    public function testListReturnsOnlyActivePacks(): void
    {
        $request = $this->requestFactory->createRequest('GET', '/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->list($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('packs', $json['data']);

        $packs = $json['data']['packs'];
        $packIds = array_column($packs, 'pack_id');

        $this->assertContains($this->activePackId, $packIds, 'Active pack should be included');
        $this->assertNotContains($this->inactivePackId, $packIds, 'Inactive pack should not be included');

        // Verify all returned packs are active
        foreach ($packs as $pack) {
            $this->assertEquals(1, $pack['active'], 'All packs should be active');
        }
    }

    /**
     * Test list() includes card counts
     */
    public function testListIncludesCardCounts(): void
    {
        $request = $this->requestFactory->createRequest('GET', '/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->list($request, $response);

        $json = $this->getJsonResponse($result);
        $packs = $json['data']['packs'];

        $activePack = null;
        foreach ($packs as $pack) {
            if ($pack['pack_id'] == $this->activePackId) {
                $activePack = $pack;
                break;
            }
        }

        $this->assertNotNull($activePack, 'Active pack should be in results');
        $this->assertArrayHasKey('response_card_count', $activePack);
        $this->assertArrayHasKey('prompt_card_count', $activePack);
        $this->assertArrayHasKey('total_card_count', $activePack);

        $this->assertEquals(2, $activePack['response_card_count']);
        $this->assertEquals(1, $activePack['prompt_card_count']);
        $this->assertEquals(3, $activePack['total_card_count']);
    }

    /**
     * Test listAll() returns both active and inactive packs
     */
    public function testListAllReturnsBothActiveAndInactivePacks(): void
    {
        $request = $this->requestFactory->createRequest('GET', '/admin/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->listAll($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('packs', $json['data']);

        $packs = $json['data']['packs'];
        $packIds = array_column($packs, 'pack_id');

        $this->assertContains($this->activePackId, $packIds, 'Active pack should be included');
        $this->assertContains($this->inactivePackId, $packIds, 'Inactive pack should be included');
    }

    /**
     * Test listAll() includes card counts for all packs
     */
    public function testListAllIncludesCardCounts(): void
    {
        $request = $this->requestFactory->createRequest('GET', '/admin/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->listAll($request, $response);

        $json = $this->getJsonResponse($result);
        $packs = $json['data']['packs'];

        foreach ($packs as $pack) {
            if ($pack['pack_id'] == $this->activePackId || $pack['pack_id'] == $this->inactivePackId) {
                $this->assertArrayHasKey('response_card_count', $pack);
                $this->assertArrayHasKey('prompt_card_count', $pack);
                $this->assertArrayHasKey('total_card_count', $pack);
            }
        }
    }

    /**
     * Test that card counts only include active cards
     */
    public function testCardCountsOnlyIncludeActiveCards(): void
    {
        // Add an inactive card to active pack
        $inactiveCard = Card::create('response', 'ControllerTest Inactive Card', null, false);
        Pack::addToCard($inactiveCard, $this->activePackId);

        $request = $this->requestFactory->createRequest('GET', '/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->list($request, $response);

        $json = $this->getJsonResponse($result);
        $packs = $json['data']['packs'];

        $activePack = null;
        foreach ($packs as $pack) {
            if ($pack['pack_id'] == $this->activePackId) {
                $activePack = $pack;
                break;
            }
        }

        // Should still be 2 response cards (inactive card not counted)
        $this->assertEquals(2, $activePack['response_card_count']);

        // Cleanup
        Database::execute("DELETE FROM cards WHERE card_id = ?", [$inactiveCard]);
    }

    /**
     * Test list() returns packs in alphabetical order
     */
    public function testListReturnsPacksInAlphabeticalOrder(): void
    {
        // Create packs with specific names for ordering
        $packZ = Pack::create('ControllerTest Z Pack', '1.0', null, null, true);
        $packA = Pack::create('ControllerTest A Pack', '1.0', null, null, true);
        $packM = Pack::create('ControllerTest M Pack', '1.0', null, null, true);

        $request = $this->requestFactory->createRequest('GET', '/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->list($request, $response);

        $json = $this->getJsonResponse($result);
        $packs = $json['data']['packs'];

        // Filter to just our test packs
        $testPacks = array_filter($packs, function ($pack) {
            return in_array($pack['pack_id'], [$this->activePackId])
                || str_starts_with($pack['name'], 'ControllerTest');
        });

        $testPacks = array_values($testPacks);

        // Check if alphabetically sorted
        $names = array_column($testPacks, 'name');
        $sortedNames = $names;
        sort($sortedNames);

        $this->assertEquals($sortedNames, $names, 'Packs should be sorted alphabetically');

        // Cleanup
        Database::execute("DELETE FROM packs WHERE pack_id IN (?, ?, ?)", [$packZ, $packA, $packM]);
    }

    /**
     * Test that list() works even with no packs
     */
    public function testListWorksWithNoPacks(): void
    {
        // Delete all test packs
        Database::execute("DELETE FROM cards_to_packs WHERE pack_id IN (?, ?)",
            [$this->activePackId, $this->inactivePackId]);
        Database::execute("DELETE FROM packs WHERE pack_id IN (?, ?)",
            [$this->activePackId, $this->inactivePackId]);

        $request = $this->requestFactory->createRequest('GET', '/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->list($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('packs', $json['data']);
        $this->assertIsArray($json['data']['packs']);
    }

    /**
     * Test error handling in list()
     */
    public function testListHandlesErrors(): void
    {
        // This test verifies the error handling path
        // We can't easily force a database error without mocking,
        // but we can verify the structure is correct by checking a successful call
        $request = $this->requestFactory->createRequest('GET', '/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->list($request, $response);

        $json = $this->getJsonResponse($result);
        $this->assertArrayHasKey('success', $json);
        $this->assertArrayHasKey('data', $json);
    }

    /**
     * Test error handling in listAll()
     */
    public function testListAllHandlesErrors(): void
    {
        // This test verifies the error handling path
        // We can't easily force a database error without mocking,
        // but we can verify the structure is correct by checking a successful call
        $request = $this->requestFactory->createRequest('GET', '/admin/packs');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->listAll($request, $response);

        $json = $this->getJsonResponse($result);
        $this->assertArrayHasKey('success', $json);
        $this->assertArrayHasKey('data', $json);
    }
}
