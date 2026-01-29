<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Models\Pack;
use CAH\Models\Card;
use CAH\Enums\CardType;
use CAH\Controllers\AdminPackController;
use CAH\Database\Database;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Admin Pack Controller Integration Tests
 *
 * Tests admin endpoints for pack management
 */
class AdminPackControllerTest extends TestCase
{
    private AdminPackController $controller;
    private RequestFactory $requestFactory;
    private ResponseFactory $responseFactory;
    private StreamFactory $streamFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new AdminPackController();
        $this->requestFactory = new RequestFactory();
        $this->responseFactory = new ResponseFactory();
        $this->streamFactory = new StreamFactory();
    }

    protected function tearDown(): void
    {
        Database::execute("DELETE FROM packs WHERE name LIKE 'AdminTest%'");
        Database::execute("DELETE FROM cards WHERE copy LIKE 'AdminTest%'");

        parent::tearDown();
    }

    /**
     * Helper to create PSR-7 request with JSON body
     */
    private function createJsonRequest(string $method, string $uri, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $uri);
        $stream = $this->streamFactory->createStream(json_encode($data));
        return $request->withBody($stream)->withParsedBody($data);
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
     * Test creating a pack with minimal data
     */
    public function testCreatePackWithMinimalData(): void
    {
        $request = $this->createJsonRequest('POST', '/admin/packs', [
            'name' => 'AdminTest Minimal Pack'
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->createPack($request, $response);

        $this->assertEquals(201, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('pack_id', $json['data']);
        $this->assertEquals('AdminTest Minimal Pack', $json['data']['name']);

        // Verify pack was created
        $pack = Pack::find($json['data']['pack_id']);
        $this->assertNotNull($pack);
        $this->assertEquals('AdminTest Minimal Pack', $pack['name']);
    }

    /**
     * Test creating a pack with all fields
     */
    public function testCreatePackWithAllFields(): void
    {
        $request = $this->createJsonRequest('POST', '/admin/packs', [
            'name' => 'AdminTest Full Pack',
            'version' => '2.0',
            'data' => '{"description": "Test pack"}',
            'release_date' => '2025-06-01 12:00:00',
            'active' => false
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->createPack($request, $response);

        $this->assertEquals(201, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);

        $pack = Pack::find($json['data']['pack_id']);
        $this->assertEquals('AdminTest Full Pack', $pack['name']);
        $this->assertEquals('2.0', $pack['version']);
        $this->assertEquals('{"description": "Test pack"}', $pack['data']);
        $this->assertEquals('2025-06-01 12:00:00', $pack['release_date']);
        $this->assertEquals(0, $pack['active']);
    }

    /**
     * Test creating a pack without name fails validation
     */
    public function testCreatePackWithoutNameFails(): void
    {
        $request = $this->createJsonRequest('POST', '/admin/packs', [
            'version' => '1.0'
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->createPack($request, $response);

        $this->assertEquals(422, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertFalse($json['success']);
        $this->assertArrayHasKey('errors', $json);
    }

    /**
     * Test editing a pack
     */
    public function testEditPack(): void
    {
        $packId = Pack::create('AdminTest Original Name', '1.0', null, null, true);

        $request = $this->createJsonRequest('PUT', "/admin/packs/{$packId}", [
            'name' => 'AdminTest Updated Name',
            'version' => '2.0',
            'active' => false
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->editPack($request, $response, ['packId' => (string) $packId]);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['updated']);

        $pack = Pack::find($packId);
        $this->assertEquals('AdminTest Updated Name', $pack['name']);
        $this->assertEquals('2.0', $pack['version']);
        $this->assertEquals(0, $pack['active']);
    }

    /**
     * Test editing only specific fields
     */
    public function testEditPackPartialUpdate(): void
    {
        $packId = Pack::create('AdminTest Partial', '1.0', '{"old": "data"}', null, true);

        $request = $this->createJsonRequest('PUT', "/admin/packs/{$packId}", [
            'version' => '1.5'
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->editPack($request, $response, ['packId' => (string) $packId]);

        $this->assertEquals(200, $result->getStatusCode());

        $pack = Pack::find($packId);
        $this->assertEquals('AdminTest Partial', $pack['name']); // Unchanged
        $this->assertEquals('1.5', $pack['version']); // Updated
        $this->assertEquals('{"old": "data"}', $pack['data']); // Unchanged
        $this->assertEquals(1, $pack['active']); // Unchanged
    }

    /**
     * Test editing non-existent pack returns 404
     */
    public function testEditNonExistentPackFails(): void
    {
        $request = $this->createJsonRequest('PUT', '/admin/packs/999999', [
            'name' => 'Does not exist'
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->editPack($request, $response, ['packId' => '999999']);

        $this->assertEquals(404, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertFalse($json['success']);
    }

    /**
     * Test deleting a pack
     */
    public function testDeletePack(): void
    {
        $packId = Pack::create('AdminTest To Delete', '1.0', null, null, true);

        $request = $this->requestFactory->createRequest('DELETE', "/admin/packs/{$packId}");
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->deletePack($request, $response, ['packId' => (string) $packId]);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['deleted']);

        $pack = Pack::find($packId);
        $this->assertNull($pack);
    }

    /**
     * Test deleting non-existent pack returns 404
     */
    public function testDeleteNonExistentPackFails(): void
    {
        $request = $this->requestFactory->createRequest('DELETE', '/admin/packs/999999');
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->deletePack($request, $response, ['packId' => '999999']);

        $this->assertEquals(404, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertFalse($json['success']);
    }

    /**
     * Test deleting a pack removes card associations
     */
    public function testDeletePackRemovesCardAssociations(): void
    {
        $packId = Pack::create('AdminTest Pack With Cards', '1.0', null, null, true);
        $cardId = Card::create(CardType::RESPONSE, 'AdminTest Card', null, true);
        Pack::addToCard($cardId, $packId);

        // Verify association exists
        $packs = Pack::getCardPacks($cardId);
        $this->assertCount(1, $packs);

        // Delete pack
        $request = $this->requestFactory->createRequest('DELETE', "/admin/packs/{$packId}");
        $response = $this->responseFactory->createResponse();
        $this->controller->deletePack($request, $response, ['packId' => (string) $packId]);

        // Verify association is removed
        $packs = Pack::getCardPacks($cardId);
        $this->assertEmpty($packs);

        // Cleanup
        Database::execute("DELETE FROM cards WHERE card_id = ?", [$cardId]);
    }

    /**
     * Test toggling pack active status (deactivate)
     */
    public function testTogglePackDeactivate(): void
    {
        $packId = Pack::create('AdminTest Toggle Pack', '1.0', null, null, true);

        $request = $this->createJsonRequest('PATCH', "/admin/packs/{$packId}/toggle", [
            'active' => false
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->togglePack($request, $response, ['packId' => (string) $packId]);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertFalse($json['data']['active']);
        $this->assertTrue($json['data']['updated']);

        $pack = Pack::find($packId);
        $this->assertEquals(0, $pack['active']);
    }

    /**
     * Test toggling pack active status (activate)
     */
    public function testTogglePackActivate(): void
    {
        $packId = Pack::create('AdminTest Inactive Pack', '1.0', null, null, false);

        $request = $this->createJsonRequest('PATCH', "/admin/packs/{$packId}/toggle", [
            'active' => true
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->togglePack($request, $response, ['packId' => (string) $packId]);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['active']);

        $pack = Pack::find($packId);
        $this->assertEquals(1, $pack['active']);
    }

    /**
     * Test toggling pack without explicit active value (flips current state)
     */
    public function testTogglePackFlipsCurrent(): void
    {
        $packId = Pack::create('AdminTest Flip Pack', '1.0', null, null, true);

        $request = $this->createJsonRequest('PATCH', "/admin/packs/{$packId}/toggle", []);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->togglePack($request, $response, ['packId' => (string) $packId]);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertFalse($json['data']['active']); // Was true, now false

        $pack = Pack::find($packId);
        $this->assertEquals(0, $pack['active']);
    }

    /**
     * Test toggling non-existent pack returns 404
     */
    public function testToggleNonExistentPackFails(): void
    {
        $request = $this->createJsonRequest('PATCH', '/admin/packs/999999/toggle', [
            'active' => true
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->togglePack($request, $response, ['packId' => '999999']);

        $this->assertEquals(404, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertFalse($json['success']);
    }

    /**
     * Test that toggling pack affects card availability
     */
    public function testTogglePackAffectsCardAvailability(): void
    {
        $packId = Pack::create('AdminTest Availability Pack', '1.0', null, null, true);
        $cardId = Card::create(CardType::RESPONSE, 'AdminTest Availability Card', null, true);
        Pack::addToCard($cardId, $packId);

        // Card should be available initially
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);
        $this->assertContains($cardId, $cards);

        // Deactivate pack
        Pack::setActive($packId, false);

        // Card should no longer be available
        $cards = Card::getActiveCardsByTypeAndTags(CardType::RESPONSE, []);
        $this->assertNotContains($cardId, $cards);

        // Cleanup
        Database::execute("DELETE FROM cards WHERE card_id = ?", [$cardId]);
    }

    /**
     * Test bulk toggle activates multiple packs
     */
    public function testBulkToggleActivatesPacks(): void
    {
        $pack1Id = Pack::create('AdminTest Bulk Pack 1', '1.0', null, null, false);
        $pack2Id = Pack::create('AdminTest Bulk Pack 2', '1.0', null, null, false);
        $pack3Id = Pack::create('AdminTest Bulk Pack 3', '1.0', null, null, false);

        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'pack_ids' => [$pack1Id, $pack2Id, $pack3Id],
            'active' => true
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['active']);
        $this->assertEquals(3, $json['data']['updated_count']);

        // Verify all packs are now active
        $pack1 = Pack::find($pack1Id);
        $pack2 = Pack::find($pack2Id);
        $pack3 = Pack::find($pack3Id);
        $this->assertEquals(1, $pack1['active']);
        $this->assertEquals(1, $pack2['active']);
        $this->assertEquals(1, $pack3['active']);
    }

    /**
     * Test bulk toggle deactivates multiple packs
     */
    public function testBulkToggleDeactivatesPacks(): void
    {
        $pack1Id = Pack::create('AdminTest Bulk Deactivate 1', '1.0', null, null, true);
        $pack2Id = Pack::create('AdminTest Bulk Deactivate 2', '1.0', null, null, true);

        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'pack_ids' => [$pack1Id, $pack2Id],
            'active' => false
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertFalse($json['data']['active']);
        $this->assertEquals(2, $json['data']['updated_count']);

        // Verify all packs are now inactive
        $pack1 = Pack::find($pack1Id);
        $pack2 = Pack::find($pack2Id);
        $this->assertEquals(0, $pack1['active']);
        $this->assertEquals(0, $pack2['active']);
    }

    /**
     * Test bulk toggle is idempotent (activating already active packs)
     */
    public function testBulkToggleIsIdempotentActivate(): void
    {
        $pack1Id = Pack::create('AdminTest Idempotent 1', '1.0', null, null, true);
        $pack2Id = Pack::create('AdminTest Idempotent 2', '1.0', null, null, true);

        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'pack_ids' => [$pack1Id, $pack2Id],
            'active' => true
        ]);
        $response = $this->responseFactory->createResponse();

        // First call - packs are already active
        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['active']);
        // Updated count may be 0 since they were already active (idempotent)
        $this->assertGreaterThanOrEqual(0, $json['data']['updated_count']);

        // Verify packs are still active
        $pack1 = Pack::find($pack1Id);
        $pack2 = Pack::find($pack2Id);
        $this->assertEquals(1, $pack1['active']);
        $this->assertEquals(1, $pack2['active']);
    }

    /**
     * Test bulk toggle is idempotent (deactivating already inactive packs)
     */
    public function testBulkToggleIsIdempotentDeactivate(): void
    {
        $pack1Id = Pack::create('AdminTest Idempotent Inactive 1', '1.0', null, null, false);
        $pack2Id = Pack::create('AdminTest Idempotent Inactive 2', '1.0', null, null, false);

        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'pack_ids' => [$pack1Id, $pack2Id],
            'active' => false
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);
        $this->assertFalse($json['data']['active']);
        // Updated count may be 0 since they were already inactive (idempotent)
        $this->assertGreaterThanOrEqual(0, $json['data']['updated_count']);

        // Verify packs are still inactive
        $pack1 = Pack::find($pack1Id);
        $pack2 = Pack::find($pack2Id);
        $this->assertEquals(0, $pack1['active']);
        $this->assertEquals(0, $pack2['active']);
    }

    /**
     * Test bulk toggle with mixed active states
     */
    public function testBulkToggleWithMixedStates(): void
    {
        $pack1Id = Pack::create('AdminTest Mixed Active', '1.0', null, null, true);
        $pack2Id = Pack::create('AdminTest Mixed Inactive', '1.0', null, null, false);
        $pack3Id = Pack::create('AdminTest Mixed Active 2', '1.0', null, null, true);

        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'pack_ids' => [$pack1Id, $pack2Id, $pack3Id],
            'active' => false
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(200, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertTrue($json['success']);

        // Verify all packs are now inactive (idempotent behavior)
        $pack1 = Pack::find($pack1Id);
        $pack2 = Pack::find($pack2Id);
        $pack3 = Pack::find($pack3Id);
        $this->assertEquals(0, $pack1['active']);
        $this->assertEquals(0, $pack2['active']);
        $this->assertEquals(0, $pack3['active']);
    }

    /**
     * Test bulk toggle with empty pack_ids array fails validation
     */
    public function testBulkToggleWithEmptyArrayFails(): void
    {
        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'pack_ids' => [],
            'active' => true
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(422, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertFalse($json['success']);
    }

    /**
     * Test bulk toggle without pack_ids fails validation
     */
    public function testBulkToggleWithoutPackIdsFails(): void
    {
        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'active' => true
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(422, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertFalse($json['success']);
        $this->assertArrayHasKey('errors', $json);
    }

    /**
     * Test bulk toggle without active field fails validation
     */
    public function testBulkToggleWithoutActiveFails(): void
    {
        $packId = Pack::create('AdminTest Missing Active', '1.0', null, null, true);

        $request = $this->createJsonRequest('PUT', '/admin/packs/bulk-toggle', [
            'pack_ids' => [$packId]
        ]);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->bulkTogglePack($request, $response);

        $this->assertEquals(422, $result->getStatusCode());

        $json = $this->getJsonResponse($result);
        $this->assertFalse($json['success']);
        $this->assertArrayHasKey('errors', $json);
    }
}
