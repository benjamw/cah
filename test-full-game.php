<?php

declare(strict_types=1);

/**
 * Full Game Integration Test
 *
 * Tests a complete game flow:
 * - 5 players (including Rando Cardrissian)
 * - Filtered card set (all tags except Mild and Sexual Innuendo)
 * - 10 rounds of gameplay
 * - Player leaves mid-game
 * - New player joins late between two players
 * - Detailed request/response logging
 *
 * Usage: php test-full-game.php
 */

// Configuration
$API_BASE_URL = 'http://localhost:8080/api';
$VERBOSE = false; // Set to true for request/response output

// ANSI color codes for pretty output
class Color {
    const RESET = "\033[0m";
    const BOLD = "\033[1m";
    const GREEN = "\033[32m";
    const BLUE = "\033[34m";
    const YELLOW = "\033[33m";
    const RED = "\033[31m";
    const CYAN = "\033[36m";
    const MAGENTA = "\033[35m";
}

// Test state
$gameId = null;
$players = []; // [name => [id, session_cookie]]
$currentRound = 0;

/**
 * Make an API request with detailed logging
 */
function apiRequest(string $method, string $endpoint, ?array $body = null, ?string $sessionCookie = null): array
{
    global $API_BASE_URL, $VERBOSE;

    $url = $API_BASE_URL . $endpoint;

    echo Color::BOLD . Color::CYAN . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" . Color::RESET;
    echo Color::BOLD . "➤  {$method} {$endpoint}\n" . Color::RESET;

    if ($body !== null && $VERBOSE) {
        echo Color::YELLOW . "⬇️ Request Body:\n" . Color::RESET;
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = ['Content-Type: application/json'];
    if ($sessionCookie) {
        $headers[] = "Cookie: {$sessionCookie}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $headerText = substr($response, 0, $headerSize);
    $bodyText = substr($response, $headerSize);

    curl_close($ch);

    // Extract session cookie if present
    $newSessionCookie = null;
    if (preg_match('/Set-Cookie: (([^=]+)=[^;]+)/', $headerText, $matches)) {
        $newSessionCookie = $matches[1];
        echo Color::BLUE . "⬇️ Cookie ({$matches[2]}): $newSessionCookie\n" . Color::RESET;
    }

    $responseData = json_decode($bodyText, true);

    if ($VERBOSE) {
        echo Color::BLUE . "⬇️ Response ({$httpCode}):\n" . Color::RESET;
        echo json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    if ($httpCode >= 400) {
        echo Color::RED . "❌ Request failed with status {$httpCode}\n" . Color::RESET;
    }

    return [
        'status' => $httpCode,
        'data' => $responseData,
        'session_cookie' => $newSessionCookie,
    ];
}

/**
 * Log a test step
 */
function logStep(string $message): void
{
    echo Color::BOLD . Color::GREEN . "\n{$message}\n" . Color::RESET;
}

/**
 * Log an error and exit
 */
function logError(string $message): void
{
    echo Color::BOLD . Color::RED . "\nERROR: {$message}\n" . Color::RESET;
    exit(1);
}

/**
 * Get game state for a player
 */
function getGameState(string $playerName): array
{
    global $players, $gameId;

    echo Color::BOLD . Color::GREEN . "Getting game state for {$playerName} ({$players[$playerName]['id']})\n" . Color::RESET;

    $response = apiRequest('GET', '/game/state', null, $players[$playerName]['session']);

    if ($response['status'] !== 200 || ! $response['data']['success']) {
        echo Color::RED . "Response status: {$response['status']}\n" . Color::RESET;
        echo Color::RED . "Response data: " . json_encode($response['data']) . "\n" . Color::RESET;
        echo Color::RED . "Game ID: {$gameId}\n" . Color::RESET;
        echo Color::RED . "Player: {$playerName}\n" . Color::RESET;
        echo Color::RED . "Session: {$players[$playerName]['session']}\n" . Color::RESET;
        logError("Failed to get game state for {$playerName}");
    }

    return $response['data']['data']['player_data'];
}

/**
 * Select the next czar by selecting the next player in the array.
 * Wrapping to start as needed.
 *
 * @param array $players
 * @param string $current_czar_id
 *
 * @return string
 */
function get_next_czar_id(array $players, string $current_czar_id): string {
    foreach ($players as $idx => $player) {
        if ($player['id'] === $current_czar_id) {
            break;
        }
    }

    // wrap to 0 if needed
    if (empty($players[$idx + 1])) {
        $idx = -1;
    }

    // skip Rando
    if ( ! empty($players[$idx + 1]['is_rando'])) {
        $idx += 1;
    }

    // wrap to 0 if needed
    if (empty($players[$idx + 1])) {
        $idx = -1;
    }

    return $players[$idx + 1]['id'];
}

echo Color::BOLD . Color::MAGENTA . "
╔══════════════════════════════════════════════════════════════════╗
║          CARDS AGAINST HUMANITY - FULL GAME TEST                 ║
╚══════════════════════════════════════════════════════════════════╝
" . Color::RESET;

// Step 1: Create game with creator and Rando enabled
logStep("STEP 1: Creating game with Albert (creator) and Rando enabled");


echo Color::CYAN . "Creating game with all tags except 1 (Mild) and 4 (Sexual Innuendo)\n" . Color::RESET;

$response = apiRequest('POST', '/game/create', [
    'creator_name' => 'Albert',
    'tag_ids' => [2, 3, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28],
    'settings' => [
        'rando_enabled' => true,
        'max_score' => 10,
        'hand_size' => 10
    ]
]);

if ($response['status'] !== 201 || !$response['data']['success']) {
    logError("Failed to create game");
}

$gameId = $response['data']['data']['game_id'];
$players['Albert'] = [
    'id' => $response['data']['data']['player_id'],
    'session' => $response['session_cookie']
];

echo Color::GREEN . "Game created: {$gameId}\n" . Color::RESET;
echo Color::GREEN . "Albert ID: {$players['Albert']['id']}\n" . Color::RESET;
echo Color::YELLOW . "🍪 Albert's session cookie: " . ($response['session_cookie'] ?? 'NULL') . "\n" . Color::RESET;

// Step 2: Join 4 more players
logStep("STEP 2: Joining 4 additional players (Betty, Carl, Donna, Evan)");

foreach (['Betty', 'Carl', 'Donna', 'Evan'] as $playerName) {
    echo Color::CYAN . "Joining {$playerName}...\n" . Color::RESET;

    $response = apiRequest('POST', '/game/join', [
        'game_id' => $gameId,
        'player_name' => $playerName
    ]);

    if ($response['status'] !== 200 || !$response['data']['success']) {
        logError("Failed to join {$playerName}");
    }

    $players[$playerName] = [
        'id' => $response['data']['data']['player_id'],
        'session' => $response['session_cookie']
    ];

    echo Color::GREEN . "{$playerName} joined with ID: {$players[$playerName]['id']}\n" . Color::RESET;
}

echo Color::GREEN . "All 5 players joined successfully\n" . Color::RESET;

// Step 3: Start the game
logStep("STEP 3: Starting the game (as creator)");

$response = apiRequest('POST', '/game/start', [
    'game_id' => $gameId
], $players['Albert']['session']);

if ($response['status'] !== 200 || !$response['data']['success']) {
    logError("Failed to start game");
}

echo Color::GREEN . "Game started successfully\n" . Color::RESET;

// Get initial game state
$gameState = getGameState('Albert');
echo Color::CYAN . "Initial game state: {$gameState['state']}\n" . Color::RESET;
echo Color::CYAN . "Players in game: " . count($gameState['players']) . "\n" . Color::RESET;
echo Color::CYAN . "Current round: {$gameState['current_round']}\n" . Color::RESET;

// Step 4-13: Play 10 rounds
logStep("STEP 4-13: Playing 10 rounds");

for ($round = 1; $round <= 10; $round++) {
    echo Color::BOLD . Color::MAGENTA . "\n─── ROUND {$round} ───\n" . Color::RESET;

    // Get current game state
    $gameState = getGameState('Albert');
    $currentCzarId = $gameState['current_czar_id'];
    $blackCard = $gameState['current_black_card'];

    // Find czar name
    $czarName = null;
    foreach ($gameState['players'] as $player) {
        if ($player['id'] === $currentCzarId) {
            $czarName = $player['name'];
            break;
        }
    }

    echo Color::YELLOW . "Card Czar: {$czarName}\n" . Color::RESET;
    echo Color::YELLOW . "Black Card: {$blackCard['card_id']}: {$blackCard['value']}\n" . Color::RESET;

    // Get black card details to know how many cards to submit
    $cardsToSubmit = $blackCard['choices'];

    // Each non-czar player submits cards
    $submissionCount = 0;
    foreach ($players as $playerName => $playerData) {
        if ($playerData['id'] === $currentCzarId) {
            echo Color::CYAN . "{$playerName} is the czar, skipping submission\n" . Color::RESET;
            continue;
        }

        // Get player's hand
        $playerState = getGameState($playerName);
        $hand = null;
        foreach ($playerState['players'] as $p) {
            if ($p['id'] === $playerData['id']) {
                $hand = $p['hand'];
                break;
            }
        }

        if (empty($hand)) {
            echo Color::RED . "{$playerName} has no cards in hand!\n" . Color::RESET;
            exit;
        }

        // Submit first card(s) from hand
        $cardsToSubmitArray = array_slice($hand, 0, $cardsToSubmit);
        $cardIdsToSubmit = array_column($cardsToSubmitArray, 'card_id');

        echo Color::CYAN . "{$playerName} submitting cards: " . implode(', ', $cardIdsToSubmit) . "\n" . Color::RESET;

        $response = apiRequest('POST', '/round/submit', [
            'game_id' => $gameId,
            'card_ids' => $cardIdsToSubmit,
        ], $playerData['session']);

        if ($response['status'] !== 200 || !$response['data']['success']) {
            echo Color::RED . "Failed to submit cards for {$playerName}\n" . Color::RESET;
            exit;
        } else {
            echo Color::GREEN . "{$playerName} submitted successfully\n" . Color::RESET;
            $submissionCount++;
        }
    }

    echo Color::CYAN . "Total submissions: {$submissionCount}\n" . Color::RESET;

    // Wait a moment for Rando to auto-submit
    sleep(1);

    // Get updated game state to see all submissions
    $gameState = getGameState($czarName);

    echo Color::YELLOW . "\nAll submissions received. Czar ({$czarName}) picking winner...\n" . Color::RESET;

    // Display submissions to czar
    if ( ! empty($gameState['submissions'])) {
        echo Color::CYAN . "Submissions:\n" . Color::RESET;
        foreach ($gameState['submissions'] as $idx => $submission) {
            $submitterName = 'Unknown';
            foreach ($gameState['players'] as $p) {
                if ($p['id'] === $submission['player_id']) {
                    $submitterName = $p['name'];
                    break;
                }
            }
            echo Color::CYAN . "  [{$idx}] {$submitterName}: " . implode(', ', array_column($submission['cards'], 'card_id')) . "\n" . Color::RESET;
        }
    }

    // Czar picks random submission
    if ( ! empty($gameState['submissions'])) {
        $winningSubmission = $gameState['submissions'][random_int(0, count($gameState['submissions']) - 1)];
        $winningPlayerId = $winningSubmission['player_id'];

        echo Color::CYAN . "Czar picking submission from player ID: {$winningPlayerId}\n" . Color::RESET;

        $response = apiRequest('POST', '/round/pick-winner', [
            'game_id' => $gameId,
            'player_id' => $currentCzarId,
            'winner_id' => $winningPlayerId,
            'next_czar_id' => get_next_czar_id($gameState['players'], $currentCzarId),
        ], $players[$czarName]['session']);

        if ($response['status'] !== 200 || !$response['data']['success']) {
            echo Color::RED . "Failed to pick winner\n" . Color::RESET;
            exit;
        } else {
            // Find winner name
            $winnerName = 'Unknown';
            foreach ($gameState['players'] as $p) {
                if ($p['id'] === $winningPlayerId) {
                    $winnerName = $p['name'];
                    break;
                }
            }
            echo Color::GREEN . "{$winnerName} wins round {$round}!\n" . Color::RESET;

            // Display updated scores
            try {
                $gameState = getGameState('Albert');
                echo Color::YELLOW . "\nScores after round {$round}:\n" . Color::RESET;
                foreach ($gameState['players'] as $p) {
                    echo Color::CYAN . "  {$p['name']}: {$p['score']} points\n" . Color::RESET;
                }
            } catch (Exception $e) {
                echo Color::RED . "Warning: Could not retrieve game state after round {$round}\n" . Color::RESET;
                echo Color::RED . "Error: " . $e->getMessage() . "\n" . Color::RESET;
                // Try to continue anyway
            }
        }
    }

    // Player removal at round 5
    if ($round === 5) {
        logStep("SPECIAL EVENT: Testing creator re-start & Carl leaves the game");

        // Test: Creator attempting to restart an already-started game
        echo Color::CYAN . "\nAttempting to restart already-started game...\n" . Color::RESET;
        $response = apiRequest('POST', '/game/start', [
            'game_id' => $gameId
        ], $players['Albert']['session']);

        if ($response['status'] >= 400) {
            echo Color::GREEN . "✓ Correctly rejected restart attempt\n" . Color::RESET;
        } else {
            echo Color::RED . "✗ Should have rejected restart attempt\n" . Color::RESET;
        }

        echo Color::CYAN . "\nCarl left...\n" . Color::RESET;

        $response = apiRequest('POST', '/player/remove', [
            'game_id' => $gameId,
            'player_id' => $players['Carl']['id'],
            'target_player_id' => $players['Carl']['id'],
        ], $players['Albert']['session']);

        if ($response['status'] !== 200 || !$response['data']['success']) {
            echo Color::RED . "Failed to remove Carl\n" . Color::RESET;
            exit;
        } else {
            echo Color::GREEN . "Carl has been removed from the game\n" . Color::RESET;
            unset($players['Carl']);

            // Display remaining players
            $gameState = getGameState('Albert');
            echo Color::CYAN . "Remaining players: " . count($gameState['players']) . "\n" . Color::RESET;
            foreach ($gameState['players'] as $p) {
                echo Color::CYAN . "  - {$p['name']}\n" . Color::RESET;
            }
        }
    }

    // Late join at round 7
    if ($round === 7) {
        logStep("SPECIAL EVENT: Frank joins the game late");

        echo Color::CYAN . "Frank joining between Betty and Donna...\n" . Color::RESET;

        $response = apiRequest('POST', '/game/join-late', [
            'game_id' => $gameId,
            'player_name' => 'Frank',
            'adjacent_player_1' => 'Albert',
            'adjacent_player_2' => 'Evan'
        ]);

        if ($response['status'] !== 200 || !$response['data']['success']) {
            echo Color::RED . "Failed to join Frank late\n" . Color::RESET;
            exit;
        } else {
            $players['Frank'] = [
                'id' => $response['data']['data']['player_id'],
                'session' => $response['session_cookie']
            ];

            echo Color::GREEN . "Frank joined late with ID: {$players['Frank']['id']}\n" . Color::RESET;

            // Display updated player list
            $gameState = getGameState('Albert');
            echo Color::CYAN . "Players in game: " . count($gameState['players']) . "\n" . Color::RESET;
            foreach ($gameState['players'] as $p) {
                echo Color::CYAN . "  - {$p['name']} (Score: {$p['score']})\n" . Color::RESET;
            }
        }
    }
}

// Step 14: Test invalid API calls for proper error handling
logStep("STEP 14: Testing invalid API calls");

echo Color::BOLD . Color::MAGENTA . "\n─── TESTING ERROR HANDLING ───\n" . Color::RESET;

// Verify game is still accessible before starting error tests
echo Color::YELLOW . "\nVerifying game state before error tests...\n" . Color::RESET;
echo Color::YELLOW . "Game ID: {$gameId}\n" . Color::RESET;
$preTestState = getGameState('Albert');
echo Color::GREEN . "✓ Game is still accessible. Players: " . count($preTestState['players']) . "\n" . Color::RESET;

// Test 1: Invalid game ID
echo Color::CYAN . "\nTest 1: Attempting to join with invalid game ID\n" . Color::RESET;
$response = apiRequest('POST', '/game/join', [
    'game_id' => 'INVALID',
    'player_name' => 'InvalidPlayer'
]);
if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected invalid game ID\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected invalid game ID\n" . Color::RESET;
}

// Test 2: Non-creator trying to start game (create new game for this test)
echo Color::CYAN . "\nTest 2: Non-creator trying to start game\n" . Color::RESET;
$response = apiRequest('POST', '/game/start', [
    'game_id' => $gameId
], $players['Betty']['session']);
if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected non-creator start attempt\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected non-creator start attempt\n" . Color::RESET;
}

// Test 3: Submit wrong number of cards
echo Color::CYAN . "\nTest 3: Submitting wrong number of cards\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find a non-czar player
$testPlayerName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $testPlayerName = $name;
        break;
    }
}

if ($testPlayerName) {
    $playerState = getGameState($testPlayerName);
    $hand = null;
    foreach ($playerState['players'] as $p) {
        if ($p['id'] === $players[$testPlayerName]['id']) {
            $hand = $p['hand'];
            break;
        }
    }
    
    if ($hand && count($hand) >= 2) {
        // Submit wrong number (always submit 2 when might need 1, or vice versa)
        $wrongCount = $gameState['current_black_card']['choices'] === 1 ? 2 : 1;
        $wrongCards = array_slice(array_column($hand, 'card_id'), 0, $wrongCount);
        
        $response = apiRequest('POST', '/round/submit', [
            'game_id' => $gameId,
            'card_ids' => $wrongCards,
        ], $players[$testPlayerName]['session']);
        
        if ($response['status'] >= 400) {
            echo Color::GREEN . "✓ Correctly rejected wrong number of cards\n" . Color::RESET;
        } else {
            echo Color::RED . "✗ Should have rejected wrong number of cards\n" . Color::RESET;
        }
    }
}

// Test 4: Non-czar trying to pick winner
echo Color::CYAN . "\nTest 4: Non-czar trying to pick winner\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find a non-czar player
$nonCzarName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $nonCzarName = $name;
        break;
    }
}

if ($nonCzarName && !empty($gameState['submissions'])) {
    $response = apiRequest('POST', '/round/pick-winner', [
        'game_id' => $gameId,
        'player_id' => $players[$nonCzarName]['id'],
        'winner_id' => $gameState['submissions'][0]['player_id'],
        'next_czar_id' => get_next_czar_id($gameState['players'], $currentCzarId),
    ], $players[$nonCzarName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected non-czar pick winner attempt\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected non-czar pick winner attempt\n" . Color::RESET;
    }
}

// Test 5: Invalid session cookie
echo Color::CYAN . "\nTest 5: Using invalid session cookie\n" . Color::RESET;
$response = apiRequest('GET', '/game/state', null, 'PHPSESSID=invalid_session_cookie');
if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected invalid session\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected invalid session\n" . Color::RESET;
}

// Test 6: Submit invalid card IDs
echo Color::CYAN . "\nTest 6: Submitting invalid card IDs\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find a non-czar player
$testPlayerName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $testPlayerName = $name;
        break;
    }
}

if ($testPlayerName) {
    $cardsToSubmit = $gameState['current_black_card']['choices'];
    $invalidCardIds = array_fill(0, $cardsToSubmit, 999999); // Non-existent card IDs
    
    $response = apiRequest('POST', '/round/submit', [
        'game_id' => $gameId,
        'card_ids' => $invalidCardIds,
    ], $players[$testPlayerName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected invalid card IDs\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected invalid card IDs\n" . Color::RESET;
    }
}

// Test 7: Czar trying to submit cards
echo Color::CYAN . "\nTest 7: Czar trying to submit cards\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find the czar
$czarName = null;
foreach ($players as $name => $data) {
    if ($data['id'] === $currentCzarId) {
        $czarName = $name;
        break;
    }
}

if ($czarName) {
    $czarState = getGameState($czarName);
    $czarHand = null;
    foreach ($czarState['players'] as $p) {
        if ($p['id'] === $currentCzarId) {
            $czarHand = $p['hand'];
            break;
        }
    }
    
    if ($czarHand) {
        $cardsToSubmit = $gameState['current_black_card']['choices'];
        $cardIds = array_slice(array_column($czarHand, 'card_id'), 0, $cardsToSubmit);
        
        $response = apiRequest('POST', '/round/submit', [
            'game_id' => $gameId,
            'card_ids' => $cardIds,
        ], $players[$czarName]['session']);
        
        if ($response['status'] >= 400) {
            echo Color::GREEN . "✓ Correctly rejected czar submission attempt\n" . Color::RESET;
        } else {
            echo Color::RED . "✗ Should have rejected czar submission attempt\n" . Color::RESET;
        }
    }
}

// Test 8: Remove player with invalid permissions
echo Color::CYAN . "\nTest 8: Non-creator trying to remove another player\n" . Color::RESET;
$targetPlayerName = array_key_first($players);
$nonCreatorName = null;
foreach ($players as $name => $data) {
    if ($name !== 'Albert' && $name !== $targetPlayerName) {
        $nonCreatorName = $name;
        break;
    }
}

if ($nonCreatorName && $targetPlayerName) {
    $response = apiRequest('POST', '/player/remove', [
        'game_id' => $gameId,
        'player_id' => $players[$nonCreatorName]['id'],
        'target_player_id' => $players[$targetPlayerName]['id'],
    ], $players[$nonCreatorName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected unauthorized player removal\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected unauthorized player removal\n" . Color::RESET;
    }
}

// Test 9: Pick invalid winner (non-existent player)
echo Color::CYAN . "\nTest 9: Picking non-existent player as winner\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find the czar
$czarName = null;
foreach ($players as $name => $data) {
    if ($data['id'] === $currentCzarId) {
        $czarName = $name;
        break;
    }
}

if ($czarName) {
    $response = apiRequest('POST', '/round/pick-winner', [
        'game_id' => $gameId,
        'player_id' => $currentCzarId,
        'winner_id' => 'invalid_player_id_12345',
        'next_czar_id' => get_next_czar_id($gameState['players'], $currentCzarId),
    ], $players[$czarName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected invalid winner ID\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected invalid winner ID\n" . Color::RESET;
    }
}

// Test 10: Missing required fields
echo Color::CYAN . "\nTest 10: API call with missing required fields\n" . Color::RESET;
$response = apiRequest('POST', '/round/submit', [
    'game_id' => $gameId,
    // Missing card_ids field
], $players['Albert']['session']);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected missing required fields\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected missing required fields\n" . Color::RESET;
}

// Test 11: Double submission (submitting cards twice in same round)
echo Color::CYAN . "\nTest 11: Attempting to submit cards twice in the same round\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find a non-czar player who might have already submitted
$testPlayerName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $testPlayerName = $name;
        break;
    }
}

if ($testPlayerName) {
    // First, check if they've already submitted, if not submit first
    $playerState = getGameState($testPlayerName);
    $hand = null;
    foreach ($playerState['players'] as $p) {
        if ($p['id'] === $players[$testPlayerName]['id']) {
            $hand = $p['hand'];
            break;
        }
    }
    
    if ($hand) {
        $cardsNeeded = $gameState['current_black_card']['choices'];
        $cardIds = array_slice(array_column($hand, 'card_id'), 0, $cardsNeeded);
        
        // First submission (might succeed or already submitted)
        echo Color::YELLOW . "First submission attempt...\n" . Color::RESET;
        $firstResponse = apiRequest('POST', '/round/submit', [
            'game_id' => $gameId,
            'card_ids' => $cardIds,
        ], $players[$testPlayerName]['session']);
        
        // Now try to submit again (should always fail)
        echo Color::YELLOW . "Second submission attempt (should fail)...\n" . Color::RESET;
        $secondResponse = apiRequest('POST', '/round/submit', [
            'game_id' => $gameId,
            'card_ids' => $cardIds,
        ], $players[$testPlayerName]['session']);
        
        if ($secondResponse['status'] >= 400) {
            echo Color::GREEN . "✓ Correctly rejected double submission\n" . Color::RESET;
        } else {
            echo Color::RED . "✗ Should have rejected double submission\n" . Color::RESET;
        }
    }
}

// Test 12: Submitting cards that were just played (not in hand anymore)
echo Color::CYAN . "\nTest 12: Attempting to submit cards not in current hand\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find a non-czar player
$testPlayerName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $testPlayerName = $name;
        break;
    }
}

if ($testPlayerName) {
    $playerState = getGameState($testPlayerName);
    $hand = null;
    foreach ($playerState['players'] as $p) {
        if ($p['id'] === $players[$testPlayerName]['id']) {
            $hand = $p['hand'];
            break;
        }
    }
    
    if ($hand) {
        // Try to submit card IDs that are likely from previous rounds (use very high IDs)
        $cardsNeeded = $gameState['current_black_card']['choices'];
        $fakeCardIds = [];
        for ($i = 0; $i < $cardsNeeded; $i++) {
            $fakeCardIds[] = 888888 + $i; // Cards that don't exist or aren't in hand
        }
        
        $response = apiRequest('POST', '/round/submit', [
            'game_id' => $gameId,
            'card_ids' => $fakeCardIds,
        ], $players[$testPlayerName]['session']);
        
        if ($response['status'] >= 400) {
            echo Color::GREEN . "✓ Correctly rejected cards not in hand\n" . Color::RESET;
        } else {
            echo Color::RED . "✗ Should have rejected cards not in hand\n" . Color::RESET;
        }
    }
}

// Test 13: Submitting duplicate card IDs in a single submission
echo Color::CYAN . "\nTest 13: Submitting same card ID multiple times in one submission\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];
$blackCard = $gameState['current_black_card'];

// Only test this if black card requires multiple cards
if ($blackCard['choices'] > 1) {
    // Find a non-czar player
    $testPlayerName = null;
    foreach ($players as $name => $data) {
        if ($data['id'] !== $currentCzarId) {
            $testPlayerName = $name;
            break;
        }
    }
    
    if ($testPlayerName) {
        $playerState = getGameState($testPlayerName);
        $hand = null;
        foreach ($playerState['players'] as $p) {
            if ($p['id'] === $players[$testPlayerName]['id']) {
                $hand = $p['hand'];
                break;
            }
        }
        
        if ($hand && count($hand) > 0) {
            // Submit the same card ID multiple times
            $duplicateCards = array_fill(0, $blackCard['choices'], $hand[0]['card_id']);
            
            $response = apiRequest('POST', '/round/submit', [
                'game_id' => $gameId,
                'card_ids' => $duplicateCards,
            ], $players[$testPlayerName]['session']);
            
            if ($response['status'] >= 400) {
                echo Color::GREEN . "✓ Correctly rejected duplicate card IDs\n" . Color::RESET;
            } else {
                echo Color::RED . "✗ Should have rejected duplicate card IDs\n" . Color::RESET;
            }
        }
    }
} else {
    echo Color::YELLOW . "Skipped (black card only needs 1 card)\n" . Color::RESET;
}

// Test 14: Creator attempting to start an already-started game
echo Color::CYAN . "\nTest 14: Creator trying to start game that's already in progress\n" . Color::RESET;
$response = apiRequest('POST', '/game/start', [
    'game_id' => $gameId
], $players['Albert']['session']);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected start on already-started game\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected start on already-started game\n" . Color::RESET;
}

// Test 15: Rapid successive submissions (attempting to exploit race conditions)
echo Color::CYAN . "\nTest 15: Rapid successive submission attempts\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

// Find a non-czar player who hasn't submitted yet
$testPlayerName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $testPlayerName = $name;
        break;
    }
}

if ($testPlayerName) {
    $playerState = getGameState($testPlayerName);
    $hand = null;
    foreach ($playerState['players'] as $p) {
        if ($p['id'] === $players[$testPlayerName]['id']) {
            $hand = $p['hand'];
            break;
        }
    }
    
    if ($hand && count($hand) >= 3) {
        $cardsNeeded = $gameState['current_black_card']['choices'];
        
        // Try 3 rapid submissions with different cards
        echo Color::YELLOW . "Attempting 3 rapid submissions...\n" . Color::RESET;
        $successCount = 0;
        
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $cardIds = array_slice(array_column($hand, 'card_id'), $attempt, $cardsNeeded);
            
            $response = apiRequest('POST', '/round/submit', [
                'game_id' => $gameId,
                'card_ids' => $cardIds,
            ], $players[$testPlayerName]['session']);
            
            if ($response['status'] < 400) {
                $successCount++;
            }
        }
        
        if ($successCount <= 1) {
            echo Color::GREEN . "✓ Correctly allowed only one submission ({$successCount} succeeded)\n" . Color::RESET;
        } else {
            echo Color::RED . "✗ Should have rejected multiple submissions ({$successCount} succeeded)\n" . Color::RESET;
        }
    }
}

// Test 16: Joining game after it has already started (not late join)
echo Color::CYAN . "\nTest 16: Regular join attempt on already-started game\n" . Color::RESET;
$VERBOSE = true;
$response = apiRequest('POST', '/game/join', [
    'game_id' => $gameId,
    'player_name' => 'TooLatePlayer'
]);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected regular join on started game\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected regular join on started game\n" . Color::RESET;
}
$VERBOSE = false;

// Test 17: Empty card array submission
echo Color::CYAN . "\nTest 17: Submitting empty card array\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

$testPlayerName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $testPlayerName = $name;
        break;
    }
}

if ($testPlayerName) {
    $response = apiRequest('POST', '/round/submit', [
        'game_id' => $gameId,
        'card_ids' => [], // Empty array
    ], $players[$testPlayerName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected empty card array\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected empty card array\n" . Color::RESET;
    }
}

// Test 18: Picking the czar as the winner
echo Color::CYAN . "\nTest 18: Attempting to pick the czar as round winner\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

$czarName = null;
foreach ($players as $name => $data) {
    if ($data['id'] === $currentCzarId) {
        $czarName = $name;
        break;
    }
}

if ($czarName) {
    $response = apiRequest('POST', '/round/pick-winner', [
        'game_id' => $gameId,
        'player_id' => $currentCzarId,
        'winner_id' => $currentCzarId, // Czar picking themselves
        'next_czar_id' => get_next_czar_id($gameState['players'], $currentCzarId),
    ], $players[$czarName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected czar as winner\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected czar as winner\n" . Color::RESET;
    }
}

// Test 19: Accessing game state for a game you're not in
echo Color::CYAN . "\nTest 19: Creating new player and accessing original game\n" . Color::RESET;
// Create a new game with a different player
$newGameResponse = apiRequest('POST', '/game/create', [
    'creator_name' => 'Outsider',
    'tag_ids' => [1, 2, 3],
    'settings' => ['rando_enabled' => false]
]);

if ($newGameResponse['status'] === 201) {
    $outsiderSession = $newGameResponse['session_cookie'];
    
    // Try to access the original game with this new player's session
    $response = apiRequest('POST', '/round/submit', [
        'game_id' => $gameId, // Original game
        'card_ids' => [1, 2],
    ], $outsiderSession);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected unauthorized game access\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected unauthorized game access\n" . Color::RESET;
    }
}

// Test 20: Excessively long player name
echo Color::CYAN . "\nTest 20: Creating game with excessively long player name\n" . Color::RESET;
$longName = str_repeat('A', 500); // 500 character name
$response = apiRequest('POST', '/game/create', [
    'creator_name' => $longName,
    'tag_ids' => [1, 2, 3],
    'settings' => ['rando_enabled' => false]
]);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected excessively long name\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected excessively long name\n" . Color::RESET;
}

// Test 21: SQL injection attempt in player name
echo Color::CYAN . "\nTest 21: SQL injection attempt in player name\n" . Color::RESET;
$sqlInjectionName = "Robert'; DROP TABLE players; --";
$response = apiRequest('POST', '/game/create', [
    'creator_name' => $sqlInjectionName,
    'tag_ids' => [1, 2, 3],
    'settings' => ['rando_enabled' => false]
]);

// This should either be sanitized or rejected, but shouldn't execute SQL
if ($response['status'] === 201) {
    echo Color::YELLOW . "⚠ SQL injection name was accepted (check if sanitized properly)\n" . Color::RESET;
} else if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Rejected SQL injection attempt\n" . Color::RESET;
}

// Test 22: Invalid game settings
echo Color::CYAN . "\nTest 22: Creating game with invalid settings (negative values)\n" . Color::RESET;
$VERBOSE = true;
$response = apiRequest('POST', '/game/create', [
    'creator_name' => 'TestPlayer',
    'tag_ids' => [1, 2, 3],
    'settings' => [
        'rando_enabled' => false,
        'max_score' => -5, // Invalid negative score
        'hand_size' => 0  // Invalid zero hand size
    ]
]);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected invalid game settings\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected invalid game settings\n" . Color::RESET;
}
$VERBOSE = false;

// Test 23: Creating game with no tags
echo Color::CYAN . "\nTest 23: Creating game with empty tag array\n" . Color::RESET;
$response = apiRequest('POST', '/game/create', [
    'creator_name' => 'TestPlayer',
    'tag_ids' => [], // No tags
    'settings' => ['rando_enabled' => false]
]);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected game with no tags\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected game with no tags\n" . Color::RESET;
}

// Test 24: Late join with invalid adjacent players
echo Color::CYAN . "\nTest 24: Late join with non-existent adjacent players\n" . Color::RESET;
$response = apiRequest('POST', '/game/join-late', [
    'game_id' => $gameId,
    'player_name' => 'LateGuy',
    'adjacent_player_1' => 'NonExistentPlayer1',
    'adjacent_player_2' => 'NonExistentPlayer2'
]);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected late join with invalid adjacent players\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected late join with invalid adjacent players\n" . Color::RESET;
}

// Test 25: Late join with same player listed twice
echo Color::CYAN . "\nTest 25: Late join with same adjacent player twice\n" . Color::RESET;
$VERBOSE = true;
$firstPlayerName = array_key_first($players);
$response = apiRequest('POST', '/game/join-late', [
    'game_id' => $gameId,
    'player_name' => 'AnotherLateGuy',
    'adjacent_player_1' => $firstPlayerName,
    'adjacent_player_2' => $firstPlayerName // Same player
]);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected late join with duplicate adjacent player\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected late join with duplicate adjacent player\n" . Color::RESET;
}
$VERBOSE = false;

// Test 26: Negative card IDs
echo Color::CYAN . "\nTest 26: Submitting negative card IDs\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

$testPlayerName = null;
foreach ($players as $name => $data) {
    if ($data['id'] !== $currentCzarId) {
        $testPlayerName = $name;
        break;
    }
}

if ($testPlayerName) {
    $cardsNeeded = $gameState['current_black_card']['choices'];
    $negativeCardIds = array_fill(0, $cardsNeeded, -1);
    
    $response = apiRequest('POST', '/round/submit', [
        'game_id' => $gameId,
        'card_ids' => $negativeCardIds,
    ], $players[$testPlayerName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected negative card IDs\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected negative card IDs\n" . Color::RESET;
    }
}

// Test 27: Invalid next_czar_id when picking winner
echo Color::CYAN . "\nTest 27: Picking winner with invalid next_czar_id\n" . Color::RESET;
$gameState = getGameState('Albert');
$currentCzarId = $gameState['current_czar_id'];

$czarName = null;
foreach ($players as $name => $data) {
    if ($data['id'] === $currentCzarId) {
        $czarName = $name;
        break;
    }
}

if ($czarName && !empty($gameState['submissions'])) {
    $response = apiRequest('POST', '/round/pick-winner', [
        'game_id' => $gameId,
        'player_id' => $currentCzarId,
        'winner_id' => $gameState['submissions'][0]['player_id'],
        'next_czar_id' => 'invalid_czar_id_xyz',
    ], $players[$czarName]['session']);
    
    if ($response['status'] >= 400) {
        echo Color::GREEN . "✓ Correctly rejected invalid next_czar_id\n" . Color::RESET;
    } else {
        echo Color::RED . "✗ Should have rejected invalid next_czar_id\n" . Color::RESET;
    }
}

// Test 28: Wrong HTTP method
echo Color::CYAN . "\nTest 28: Using GET instead of POST for game creation\n" . Color::RESET;
$response = apiRequest('GET', '/game/create', [
    'creator_name' => 'TestPlayer',
    'tag_ids' => [1, 2, 3],
]);

if ($response['status'] >= 400) {
    echo Color::GREEN . "✓ Correctly rejected wrong HTTP method\n" . Color::RESET;
} else {
    echo Color::RED . "✗ Should have rejected wrong HTTP method\n" . Color::RESET;
}

echo Color::BOLD . Color::GREEN . "\nAll error handling tests completed!\n" . Color::RESET;

// Verify game is still intact after error tests
echo Color::YELLOW . "\nVerifying game state after error tests...\n" . Color::RESET;
$postTestState = getGameState('Albert');
echo Color::GREEN . "✓ Game is still accessible. Players: " . count($postTestState['players']) . "\n" . Color::RESET;

// Final Results
logStep("FINAL RESULTS");

$gameState = getGameState('Albert');

echo Color::BOLD . Color::YELLOW . "\n" . str_repeat("=", 50) . "\n" . Color::RESET;
echo Color::BOLD . Color::YELLOW . "GAME COMPLETE!\n" . Color::RESET;
echo Color::BOLD . Color::YELLOW . str_repeat("=", 50) . "\n\n" . Color::RESET;

echo Color::CYAN . "Game ID: {$gameId}\n" . Color::RESET;
echo Color::CYAN . "Total Rounds Played: {$gameState['current_round']}\n" . Color::RESET;
echo Color::CYAN . "Game State: {$gameState['state']}\n\n" . Color::RESET;

echo Color::BOLD . Color::YELLOW . "Final Scores:\n" . Color::RESET;

// Sort players by score
$finalPlayers = $gameState['players'];
usort($finalPlayers, function($a, $b) {
    return $b['score'] - $a['score'];
});

foreach ($finalPlayers as $idx => $p) {
    $medal = '';
    if ($idx === 0) {
        $medal = '🏆 ';
        echo Color::BOLD . Color::GREEN;
    } elseif ($idx === 1) {
        $medal = '🥈 ';
        echo Color::YELLOW;
    } elseif ($idx === 2) {
        $medal = '🥉 ';
        echo Color::CYAN;
    } else {
        echo Color::RESET;
    }

    echo "  {$medal}{$p['name']}: {$p['score']} points\n" . Color::RESET;
}

echo Color::BOLD . Color::GREEN . "\nTest completed successfully!\n" . Color::RESET;
echo Color::BOLD . Color::YELLOW . str_repeat("=", 50) . "\n" . Color::RESET;
