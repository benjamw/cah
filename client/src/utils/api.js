const API_BASE = '/api';

// Helper for API requests
async function apiRequest(endpoint, options = {}) {
  const defaultOptions = {
    headers: {
      'Content-Type': 'application/json',
      ...options.headers,
    },
    credentials: 'include', // Include cookies
  };

  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...defaultOptions,
    ...options,
  });

  // Handle 304 Not Modified
  if (response.status === 304) {
    return { notModified: true };
  }

  // Get Last-Modified header if present
  const lastModified = response.headers.get('Last-Modified');

  const data = await response.json();

  // For 409 (late join scenario), include the data in the response
  if (response.status === 409) {
    return {
      ...data,
      lastModified,
    };
  }

  // Include HTTP status code in response for error handling
  return {
    ...data,
    statusCode: response.status,
    lastModified,
  };
}

// Join game
export async function joinGame(playerName, gameId) {
  return apiRequest('/game/join', {
    method: 'POST',
    body: JSON.stringify({
      player_name: playerName,
      game_id: gameId,
    }),
  });
}

// Join game late (after it has started)
export async function joinGameLate(gameId, playerName, adjacentPlayer1, adjacentPlayer2) {
  return apiRequest('/game/join-late', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      player_name: playerName,
      adjacent_player_1: adjacentPlayer1,
      adjacent_player_2: adjacentPlayer2,
    }),
  });
}

// Create game
export async function createGame(gameSettings) {
  return apiRequest('/game/create', {
    method: 'POST',
    body: JSON.stringify(gameSettings),
  });
}

// Start game
export async function startGame(gameId) {
  return apiRequest('/game/start', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
    }),
  });
}

// Get game state
export async function getGameState(ifModifiedSince = null) {
  const headers = {};

  if (ifModifiedSince) {
    headers['If-Modified-Since'] = ifModifiedSince;
  }

  return apiRequest('/game/state', {
    method: 'GET',
    headers,
  });
}

// Submit cards
export async function submitCards(gameId, cardIds) {
  return apiRequest('/round/submit', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      card_ids: cardIds,
    }),
  });
}

// Pick winner
export async function pickWinner(gameId, winningPlayerId) {
  return apiRequest('/round/pick-winner', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      winner_id: winningPlayerId,
    }),
  });
}

// Set next czar
export async function setNextCzar(gameId, nextCzarId) {
  return apiRequest('/round/set-next-czar', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      next_czar_id: nextCzarId,
    }),
  });
}

// Get tags
export async function getTags() {
  return apiRequest('/tags/list', {
    method: 'GET',
  });
}

// Remove player (creator only)
export async function removePlayer(gameId, targetPlayerId) {
  return apiRequest('/player/remove', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      target_player_id: targetPlayerId,
    }),
  });
}

// Transfer host (creator only)
export async function transferHost(gameId, newHostId, removeCurrentHost = false) {
  return apiRequest('/player/transfer-host', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      new_host_id: newHostId,
      remove_current_host: removeCurrentHost,
    }),
  });
}

// Leave game (removes current player)
export async function leaveGame(gameId) {
  return apiRequest('/player/leave', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
    }),
  });
}

// Place skipped player (creator only)
export async function placeSkippedPlayer(gameId, skippedPlayerId, beforePlayerId) {
  return apiRequest('/game/place-skipped-player', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      skipped_player_id: skippedPlayerId,
      before_player_id: beforePlayerId,
    }),
  });
}

// Vote to skip czar (requires 2+ votes)
export async function voteSkipCzar(gameId) {
  return apiRequest('/game/vote-skip-czar', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
    }),
  });
}

// Toggle player pause status (creator only)
export async function togglePlayerPause(gameId, targetPlayerId) {
  return apiRequest('/game/toggle-player-pause', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
      target_player_id: targetPlayerId,
    }),
  });
}

// Refresh player's hand
export async function refreshHand(gameId) {
  return apiRequest('/game/refresh-hand', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
    }),
  });
}

// Force early review (czar only)
export async function forceEarlyReview(gameId) {
  return apiRequest('/game/force-early-review', {
    method: 'POST',
    body: JSON.stringify({
      game_id: gameId,
    }),
  });
}

// Get random card pairing (prompt + responses)
export async function getRandomPairing() {
  return apiRequest('/cards/random-pairing', {
    method: 'GET',
  });
}
