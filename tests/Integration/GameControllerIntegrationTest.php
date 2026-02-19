<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Controllers\GameController;
use CAH\Database\Database;
use CAH\Models\Game;
use CAH\Services\GameService;
use CAH\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

class GameControllerIntegrationTest extends TestCase
{
    private GameController $controller;
    private RequestFactory $requestFactory;
    private ResponseFactory $responseFactory;
    private StreamFactory $streamFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new GameController();
        $this->requestFactory = new RequestFactory();
        $this->responseFactory = new ResponseFactory();
        $this->streamFactory = new StreamFactory();
    }

    private function suppressSessionWarnings(callable $callback): mixed
    {
        $previous = set_error_handler(static function (int $severity, string $message): bool {
            if ($severity === E_WARNING && str_contains($message, 'session_start(): Session cannot be started')) {
                return true;
            }
            return false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private function createJsonRequest(string $method, string $uri, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $uri);
        $stream = $this->streamFactory->createStream((string) json_encode($data));
        return $request->withBody($stream)->withParsedBody($data);
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @return array{game_id: string, creator_id: string, game: array<string, mixed>}
     */
    private function createStartedGame(): array
    {
        $created = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $created['game_id'];
        $creatorId = $created['player_id'];
        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');
        GameService::startGame($gameId, $creatorId);
        $game = Game::find($gameId);

        return ['game_id' => $gameId, 'creator_id' => $creatorId, 'game' => $game];
    }

    /**
     * @return int tag_id
     */
    private function createTagWithCards(string $prefix, int $responseCount, int $promptCount): int
    {
        Database::execute("INSERT INTO tags (name, active) VALUES (?, 1)", ["{$prefix}_tag"]);
        $tagId = (int) Database::lastInsertId();

        for ($i = 0; $i < $responseCount; $i++) {
            Database::execute(
                "INSERT INTO cards (type, copy, active) VALUES ('response', ?, 1)",
                ["{$prefix}_response_{$i}"]
            );
            $cardId = (int) Database::lastInsertId();
            Database::execute("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)", [$cardId, $tagId]);
        }

        for ($i = 0; $i < $promptCount; $i++) {
            Database::execute(
                "INSERT INTO cards (type, copy, choices, active) VALUES ('prompt', ?, 1, 1)",
                ["{$prefix}_prompt_{$i}"]
            );
            $cardId = (int) Database::lastInsertId();
            Database::execute("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)", [$cardId, $tagId]);
        }

        return $tagId;
    }

    private function cleanupTagCards(string $prefix): void
    {
        Database::execute(
            "DELETE FROM cards_to_tags WHERE card_id IN (SELECT card_id FROM cards WHERE copy LIKE ?)",
            ["{$prefix}_%"]
        );
        Database::execute("DELETE FROM cards WHERE copy LIKE ?", ["{$prefix}_%"]);
        Database::execute("DELETE FROM tags WHERE name = ?", ["{$prefix}_tag"]);
    }

    public function testCreateAndJoinHappyPath(): void
    {
        $createReq = $this->createJsonRequest('POST', '/api/games', [
            'player_name' => 'Creator',
            'tag_ids' => [TEST_TAG_ID],
            'settings' => ['max_score' => 6],
        ]);
        $createRes = $this->suppressSessionWarnings(
            fn() => $this->controller->create($createReq, $this->responseFactory->createResponse())
        );
        $createJson = $this->decode($createRes);

        $this->assertSame(201, $createRes->getStatusCode());
        $this->assertTrue($createJson['success']);
        $this->assertArrayHasKey('csrf_token', $createJson['data']);

        $joinReq = $this->createJsonRequest('POST', '/api/games/join', [
            'game_id' => $createJson['data']['game_id'],
            'player_name' => 'Joiner',
        ]);
        $joinRes = $this->suppressSessionWarnings(
            fn() => $this->controller->join($joinReq, $this->responseFactory->createResponse())
        );
        $joinJson = $this->decode($joinRes);

        $this->assertSame(200, $joinRes->getStatusCode());
        $this->assertTrue($joinJson['success']);
        $this->assertFalse($joinJson['data']['game_started']);
        $this->assertArrayHasKey('csrf_token', $joinJson['data']);
    }

    public function testCreateValidationError(): void
    {
        $request = $this->createJsonRequest('POST', '/api/games', ['player_name' => 'OnlyName']);
        $response = $this->controller->create($request, $this->responseFactory->createResponse());
        $json = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($json['success']);
        $this->assertArrayHasKey('errors', $json);
    }

    public function testPreviewCreateReportsLowCardPoolAndThresholds(): void
    {
        $prefix = 'ControllerPreviewLowPool';
        $tagId = $this->createTagWithCards($prefix, 10, 3);

        try {
            $request = $this->createJsonRequest('POST', '/api/game/preview-create', [
                'tag_ids' => [$tagId],
            ]);
            $response = $this->controller->previewCreate($request, $this->responseFactory->createResponse());
            $json = $this->decode($response);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($json['success']);
            $this->assertSame(10, $json['data']['card_counts']['response_cards']);
            $this->assertSame(3, $json['data']['card_counts']['prompt_cards']);
            $this->assertTrue($json['data']['card_counts']['has_required_cards']);
            $this->assertTrue($json['data']['card_counts']['low_card_pool']);
            $this->assertSame(200, $json['data']['card_counts']['warning_thresholds']['response_cards']);
            $this->assertSame(25, $json['data']['card_counts']['warning_thresholds']['prompt_cards']);
        } finally {
            $this->cleanupTagCards($prefix);
        }
    }

    public function testCreateRejectsTagSelectionWithZeroPromptCards(): void
    {
        $prefix = 'ControllerNoPrompt';
        $tagId = $this->createTagWithCards($prefix, 2, 0);

        try {
            $request = $this->createJsonRequest('POST', '/api/games', [
                'player_name' => 'Creator',
                'tag_ids' => [$tagId],
            ]);
            $response = $this->controller->create($request, $this->responseFactory->createResponse());
            $json = $this->decode($response);

            $this->assertSame(422, $response->getStatusCode());
            $this->assertFalse($json['success']);
            $this->assertStringContainsString('Cannot create game: selected cards include', $json['error']);
        } finally {
            $this->cleanupTagCards($prefix);
        }
    }

    public function testJoinStartedGameReturnsConflictPayload(): void
    {
        $setup = $this->createStartedGame();
        $request = $this->createJsonRequest('POST', '/api/games/join', [
            'game_id' => $setup['game_id'],
            'player_name' => 'Late Join',
        ]);
        $response = $this->suppressSessionWarnings(
            fn() => $this->controller->join($request, $this->responseFactory->createResponse())
        );
        $json = $this->decode($response);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($json['success']);
        $this->assertTrue($json['data']['game_started']);
        $this->assertArrayHasKey('player_names', $json['data']);
    }

    public function testJoinNonExistentGameReturnsNotFound(): void
    {
        $request = $this->createJsonRequest('POST', '/api/games/join', [
            'game_id' => 'ZZZZ',
            'player_name' => 'Nobody',
        ]);
        $response = $this->suppressSessionWarnings(
            fn() => $this->controller->join($request, $this->responseFactory->createResponse())
        );
        $json = $this->decode($response);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($json['success']);
    }

    public function testStartAndGetStateEndpoints(): void
    {
        $created = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $created['game_id'];
        $creatorId = $created['player_id'];
        GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $startReq = $this->requestFactory->createRequest('POST', '/api/games/start')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $startRes = $this->controller->start($startReq, $this->responseFactory->createResponse());
        $startJson = $this->decode($startRes);

        $this->assertSame(200, $startRes->getStatusCode());
        $this->assertTrue($startJson['success']);
        $this->assertArrayHasKey('game_state', $startJson['data']);

        $stateReq = $this->requestFactory->createRequest('GET', '/api/games/state')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $stateRes = $this->controller->getState($stateReq, $this->responseFactory->createResponse());
        $stateJson = $this->decode($stateRes);

        $this->assertSame(200, $stateRes->getStatusCode());
        $this->assertTrue($stateJson['success']);
        $this->assertArrayHasKey('Last-Modified', $stateRes->getHeaders());
        $this->assertArrayHasKey('deck_counts', $stateJson['data']);
    }

    public function testGetStateNotModifiedAndGoneCases(): void
    {
        $setup = $this->createStartedGame();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];

        $future = gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT';
        $notModifiedReq = $this->requestFactory->createRequest('GET', '/api/games/state')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId)
            ->withHeader('If-Modified-Since', $future);
        $notModifiedRes = $this->controller->getState($notModifiedReq, $this->responseFactory->createResponse());
        $this->assertSame(304, $notModifiedRes->getStatusCode());

        $goneReq = $this->requestFactory->createRequest('GET', '/api/games/state')
            ->withAttribute('game_id', 'ZZZZ')
            ->withAttribute('player_id', $creatorId);
        $goneRes = $this->controller->getState($goneReq, $this->responseFactory->createResponse());
        $goneJson = $this->decode($goneRes);
        $this->assertSame(410, $goneRes->getStatusCode());
        $this->assertFalse($goneJson['success']);
    }

    public function testActionEndpointsAndViewGame(): void
    {
        $setup = $this->createStartedGame();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];
        $game = $setup['game'];
        $czarId = $game['player_data']['current_czar_id'];

        $nonCzarId = null;
        foreach ($game['player_data']['players'] as $player) {
            if ($player['id'] !== $czarId) {
                $nonCzarId = $player['id'];
                break;
            }
        }
        $this->assertNotNull($nonCzarId);

        // forceEarlyReview
        $forceReq = $this->requestFactory->createRequest('POST', '/api/games/force-early-review')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $czarId);
        $forceRes = $this->controller->forceEarlyReview($forceReq, $this->responseFactory->createResponse());
        $this->assertSame(200, $forceRes->getStatusCode());

        // refreshHand
        $refreshReq = $this->requestFactory->createRequest('POST', '/api/games/refresh-hand')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $nonCzarId);
        $refreshRes = $this->controller->refreshHand($refreshReq, $this->responseFactory->createResponse());
        $this->assertSame(200, $refreshRes->getStatusCode());

        // togglePlayerPause
        $pauseReq = $this->createJsonRequest('POST', '/api/games/pause', ['target_player_id' => $nonCzarId])
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $pauseRes = $this->controller->togglePlayerPause($pauseReq, $this->responseFactory->createResponse());
        $this->assertSame(200, $pauseRes->getStatusCode());

        // voteSkipCzar
        $afterPauseGame = Game::find($gameId);
        $votePlayerId = null;
        foreach ($afterPauseGame['player_data']['players'] as $player) {
            if ($player['id'] !== $afterPauseGame['player_data']['current_czar_id']) {
                $votePlayerId = $player['id'];
                break;
            }
        }
        $this->assertNotNull($votePlayerId);

        $voteReq = $this->requestFactory->createRequest('POST', '/api/games/vote-skip-czar')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $votePlayerId);
        $voteRes = $this->controller->voteSkipCzar($voteReq, $this->responseFactory->createResponse());
        $this->assertSame(200, $voteRes->getStatusCode());

        // skipCzar
        $skipReq = $this->requestFactory->createRequest('POST', '/api/games/skip-czar')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $skipRes = $this->controller->skipCzar($skipReq, $this->responseFactory->createResponse());
        $this->assertSame(200, $skipRes->getStatusCode());

        // joinLate validation error path
        $joinLateReq = $this->createJsonRequest('POST', '/api/games/join-late', ['game_id' => $gameId]);
        $joinLateRes = $this->controller->joinLate($joinLateReq, $this->responseFactory->createResponse());
        $this->assertSame(422, $joinLateRes->getStatusCode());

        // reshuffle path (add discard first)
        Game::update($gameId, ['discard_pile' => [1, 2, 3, 4]]);
        $reshuffleReq = $this->requestFactory->createRequest('POST', '/api/games/reshuffle')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $reshuffleRes = $this->controller->reshuffleDiscardPile($reshuffleReq, $this->responseFactory->createResponse());
        $this->assertSame(200, $reshuffleRes->getStatusCode());

        // placeSkippedPlayer validation path
        $placeReq = $this->createJsonRequest('POST', '/api/games/place-skipped', [])
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $placeRes = $this->controller->placeSkippedPlayer($placeReq, $this->responseFactory->createResponse());
        $this->assertSame(422, $placeRes->getStatusCode());

        // viewGame success and not-found
        $viewRes = $this->controller->viewGame(
            $this->requestFactory->createRequest('GET', '/api/games/' . $gameId),
            $this->responseFactory->createResponse(),
            ['gameId' => $gameId]
        );
        $this->assertSame(200, $viewRes->getStatusCode());

        $missingViewRes = $this->controller->viewGame(
            $this->requestFactory->createRequest('GET', '/api/games/ZZZZ'),
            $this->responseFactory->createResponse(),
            ['gameId' => 'ZZZZ']
        );
        $this->assertSame(404, $missingViewRes->getStatusCode());
    }

    public function testStartReturnsForbiddenForNonCreator(): void
    {
        $created = GameService::createGame('Creator', [TEST_TAG_ID]);
        $gameId = $created['game_id'];
        $creatorId = $created['player_id'];
        $joiner = GameService::joinGame($gameId, 'Player Two');
        GameService::joinGame($gameId, 'Player Three');

        $request = $this->requestFactory->createRequest('POST', '/api/games/start')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $joiner['player_id']);
        $response = $this->controller->start($request, $this->responseFactory->createResponse());
        $json = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($json['success']);

        // sanity check creator can still start
        $okRequest = $this->requestFactory->createRequest('POST', '/api/games/start')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $okResponse = $this->controller->start($okRequest, $this->responseFactory->createResponse());
        $this->assertSame(200, $okResponse->getStatusCode());
    }

    public function testTogglePlayerPauseMissingTargetReturnsValidationError(): void
    {
        $setup = $this->createStartedGame();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];

        $request = $this->createJsonRequest('POST', '/api/games/pause', [])
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $creatorId);
        $response = $this->controller->togglePlayerPause($request, $this->responseFactory->createResponse());
        $json = $this->decode($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($json['success']);
    }

    public function testReshuffleDiscardPileReturnsForbiddenForNonCreator(): void
    {
        $setup = $this->createStartedGame();
        $gameId = $setup['game_id'];
        $creatorId = $setup['creator_id'];

        Game::update($gameId, ['discard_pile' => [1, 2, 3]]);

        $nonCreatorId = null;
        foreach ($setup['game']['player_data']['players'] as $player) {
            if ($player['id'] !== $creatorId) {
                $nonCreatorId = $player['id'];
                break;
            }
        }
        $this->assertNotNull($nonCreatorId);

        $request = $this->requestFactory->createRequest('POST', '/api/games/reshuffle')
            ->withAttribute('game_id', $gameId)
            ->withAttribute('player_id', $nonCreatorId);
        $response = $this->controller->reshuffleDiscardPile($request, $this->responseFactory->createResponse());
        $json = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($json['success']);
    }
}
