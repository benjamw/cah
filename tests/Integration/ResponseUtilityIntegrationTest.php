<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Utils\Response as JsonResponse;
use Slim\Psr7\Factory\ResponseFactory;

class ResponseUtilityIntegrationTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseFactory = new ResponseFactory();
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testSuccessWithMessageAndData(): void
    {
        $response = $this->responseFactory->createResponse();
        $result = JsonResponse::success($response, ['x' => 1], 'ok', 201);
        $json = $this->decode($result);

        $this->assertSame(201, $result->getStatusCode());
        $this->assertTrue($json['success']);
        $this->assertSame('ok', $json['message']);
        $this->assertSame(1, $json['data']['x']);
    }

    public function testErrorAndValidationErrorShapes(): void
    {
        $response = $this->responseFactory->createResponse();
        $error = JsonResponse::error($response, 'bad', 400, ['field' => 'oops']);
        $errorJson = $this->decode($error);

        $this->assertSame(400, $error->getStatusCode());
        $this->assertFalse($errorJson['success']);
        $this->assertSame('bad', $errorJson['error']);
        $this->assertSame('oops', $errorJson['errors']['field']);

        $validation = JsonResponse::validationError(
            $this->responseFactory->createResponse(),
            ['name' => 'required'],
            'Validation failed'
        );
        $validationJson = $this->decode($validation);

        $this->assertSame(422, $validation->getStatusCode());
        $this->assertFalse($validationJson['success']);
        $this->assertSame('Validation failed', $validationJson['error']);
        $this->assertArrayHasKey('name', $validationJson['errors']);
    }

    public function testNotFoundUnauthorizedAndRateLimitResponses(): void
    {
        $notFound = JsonResponse::notFound($this->responseFactory->createResponse(), 'missing');
        $notFoundJson = $this->decode($notFound);
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame('missing', $notFoundJson['error']);

        $unauthorized = JsonResponse::unauthorized($this->responseFactory->createResponse(), 'nope');
        $unauthJson = $this->decode($unauthorized);
        $this->assertSame(401, $unauthorized->getStatusCode());
        $this->assertSame('nope', $unauthJson['error']);

        $rateLimited = JsonResponse::rateLimitExceeded($this->responseFactory->createResponse(), 'slow down', 30);
        $rateJson = $this->decode($rateLimited);
        $this->assertSame(429, $rateLimited->getStatusCode());
        $this->assertSame('slow down', $rateJson['error']);
        $this->assertSame('30', $rateLimited->getHeaderLine('Retry-After'));
    }

    public function testJsonEncodingFailureFallsBackTo500Payload(): void
    {
        $response = $this->responseFactory->createResponse();
        $result = JsonResponse::success($response, INF);
        $json = $this->decode($result);

        $this->assertSame(500, $result->getStatusCode());
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('Failed to encode response', $json['error']['message']);
        $this->assertSame('JSON_ENCODE_ERROR', $json['error']['code']);
    }
}

