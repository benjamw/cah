<?php

declare(strict_types=1);

use CAH\Controllers\AdminController;
use CAH\Controllers\CardController;
use CAH\Controllers\GameController;
use CAH\Controllers\PlayerController;
use CAH\Controllers\RoundController;
use CAH\Controllers\TagController;
use CAH\Database\Database;
use CAH\Middleware\AdminAuthMiddleware;
use CAH\Middleware\CorsMiddleware;
use CAH\Middleware\RateLimitMiddleware;
use CAH\Middleware\SessionMiddleware;
use CAH\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env file
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

$dbConfig = require __DIR__ . '/../config/database.php';
$gameConfig = require __DIR__ . '/../config/game.php';

Database::init($dbConfig);

$app = AppFactory::create();

// Middleware order (LIFO - last added runs first):
// 1. RoutingMiddleware - must run first to determine route
// 2. BodyParsingMiddleware - parses JSON/form bodies before controllers
// 3. SessionMiddleware - starts PHP sessions for authentication
// 4. RateLimitMiddleware - fail2ban + hard rate limiting
// 5. CorsMiddleware - handles CORS headers and preflight requests
// 6. ErrorMiddleware - catches all errors (added last, wraps everything)
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->add(new SessionMiddleware());
$app->add(new RateLimitMiddleware($gameConfig['rate_limit'] ?? []));

// Configure CORS from environment
$corsOrigins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*';
$allowedOrigins = $corsOrigins === '*' ? ['*'] : array_map('trim', explode(',', $corsOrigins));
$app->add(new CorsMiddleware(['allowed_origins' => $allowedOrigins]));

$displayErrorDetails = getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === '1';
$app->addErrorMiddleware($displayErrorDetails, true, true);

// API routes
$app->get('/api', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Cards API Hub Game API',
        'version' => '1.0.0',
        'endpoints' => [
            'health' => 'GET /api/health',
            'game' => [
                'create' => 'POST /api/game/create',
                'join' => 'POST /api/game/join',
                'start' => 'POST /api/game/start',
                'state' => 'GET /api/game/state',
                'view' => 'GET /api/games/view/{gameId}',
            ],
            'round' => [
                'submit' => 'POST /api/round/submit',
                'pick_winner' => 'POST /api/round/pick-winner',
            ],
            'player' => [
                'remove' => 'POST /api/player/remove',
            ],
            'cards' => [
                'get' => 'POST /api/cards/get',
            ],
            'tags' => [
                'list' => 'GET /api/tags/list',
            ],
            'admin' => [
                'login' => 'POST /api/admin/login',
                'logout' => 'POST /api/admin/logout',
                'cards_list' => 'GET /api/admin/cards/list',
                'cards_import' => 'POST /api/admin/cards/import',
                'cards_edit' => 'PUT /api/admin/cards/edit/{cardId}',
                'cards_delete' => 'DELETE /api/admin/cards/delete/{cardId}',
                'tags_create' => 'POST /api/admin/tags/create',
                'tags_edit' => 'PUT /api/admin/tags/edit/{tagId}',
                'tags_delete' => 'DELETE /api/admin/tags/delete/{tagId}',
                'games_list' => 'GET /api/admin/games/list',
                'games_delete' => 'DELETE /api/admin/games/delete/{gameId}',
            ],
        ],
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Health routes
$app->get('/api/health', function (Request $request, Response $response) {
    $healthData = [
        'success' => true,
        'message' => 'Cards API Hub is running',
        'timestamp' => (new DateTime())->format('Y-m-d H:i:s'),
        'database' => 'unknown',
    ];

    // Check database connectivity
    try {
        $result = Database::fetchOne('SELECT 1 AS health_check');
        if ($result && isset($result['health_check']) && $result['health_check'] === 1) {
            $healthData['database'] = 'connected';
        } else {
            $healthData['database'] = 'error';
            $healthData['success'] = false;
            $healthData['message'] = 'Database query returned unexpected result';
        }
    } catch (\Exception $e) {
        $healthData['database'] = 'disconnected';
        $healthData['success'] = false;
        $healthData['message'] = 'Database connection failed';
        $healthData['error'] = $e->getMessage();
    }

    $statusCode = $healthData['success'] ? 200 : 503;
    $response->getBody()->write(json_encode($healthData));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($statusCode);
});

// Game routes - Public (no auth required)
$app->post('/api/game/create', [GameController::class, 'create']);
$app->post('/api/game/join', [GameController::class, 'join']);
$app->post('/api/game/join-late', [GameController::class, 'joinLate']);

// Game routes - Protected (auth required)
$app->post('/api/game/start', [GameController::class, 'start'])->add(new AuthMiddleware());
$app->post('/api/game/skip-czar', [GameController::class, 'skipCzar'])->add(new AuthMiddleware());
$app->post('/api/game/reshuffle', [GameController::class, 'reshuffleDiscardPile'])->add(new AuthMiddleware());
$app->post('/api/game/place-skipped-player', [GameController::class, 'placeSkippedPlayer'])->add(new AuthMiddleware());
$app->get('/api/game/state', [GameController::class, 'getState'])->add(new AuthMiddleware());

// Round routes - Protected (auth required)
$app->post('/api/round/submit', [RoundController::class, 'submit'])->add(new AuthMiddleware());
$app->post('/api/round/pick-winner', [RoundController::class, 'pickWinner'])->add(new AuthMiddleware());
$app->post('/api/round/set-next-czar', [RoundController::class, 'setNextCzar'])->add(new AuthMiddleware());

// Player routes - Protected (auth required)
$app->post('/api/player/remove', [PlayerController::class, 'remove'])->add(new AuthMiddleware());
$app->post('/api/player/transfer-host', [PlayerController::class, 'transferHost'])->add(new AuthMiddleware());
$app->post('/api/player/leave', [PlayerController::class, 'leave'])->add(new AuthMiddleware());

// Card routes - Protected (auth required)
$app->post('/api/cards/get', [CardController::class, 'getByIds'])->add(new AuthMiddleware());

// Tag routes
$app->get('/api/tags/list', [TagController::class, 'list']);

// Game view route - Public (no auth required)
$app->get('/api/games/view/{gameId}', [GameController::class, 'viewGame']);

// Admin routes - Public login
$app->post('/api/admin/login', [AdminController::class, 'login']);

// Admin routes - Protected (admin auth required)
$app->post('/api/admin/logout', [AdminController::class, 'logout'])->add(new AdminAuthMiddleware());
$app->get('/api/admin/cards/list', [AdminController::class, 'listCards'])->add(new AdminAuthMiddleware());
$app->post('/api/admin/cards/import', [AdminController::class, 'importCards'])->add(new AdminAuthMiddleware());
$app->put('/api/admin/cards/edit/{cardId}', [AdminController::class, 'editCard'])->add(new AdminAuthMiddleware());
$app->delete('/api/admin/cards/delete/{cardId}', [AdminController::class, 'deleteCard'])->add(new AdminAuthMiddleware());
$app->post('/api/admin/tags/create', [AdminController::class, 'createTag'])->add(new AdminAuthMiddleware());
$app->put('/api/admin/tags/edit/{tagId}', [AdminController::class, 'editTag'])->add(new AdminAuthMiddleware());
$app->delete('/api/admin/tags/delete/{tagId}', [AdminController::class, 'deleteTag'])->add(new AdminAuthMiddleware());
$app->get('/api/admin/games/list', [AdminController::class, 'listGames'])->add(new AdminAuthMiddleware());
$app->delete('/api/admin/games/delete/{gameId}', [AdminController::class, 'deleteGame'])->add(new AdminAuthMiddleware());
$app->get('/api/admin/cards/{cardId}/tags', [AdminController::class, 'getCardTags'])->add(new AdminAuthMiddleware());
$app->post('/api/admin/cards/{cardId}/tags/{tagId}', [AdminController::class, 'addCardTag'])->add(new AdminAuthMiddleware());
$app->delete('/api/admin/cards/{cardId}/tags/{tagId}', [AdminController::class, 'removeCardTag'])->add(new AdminAuthMiddleware());

$app->run();
