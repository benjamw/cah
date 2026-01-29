/**
 * Games management module
 */

import { apiRequest } from './api.js';

// DOM elements (initialized in initGames)
let gamesList;

/**
 * Initialize games module DOM elements
 */
export function initGames() {
    gamesList = document.getElementById('games-list');
}

/**
 * Load and display games
 */
export async function loadGames() {
    gamesList.innerHTML = '<div class="loading">Loading games...</div>';

    try {
        const data = await apiRequest('/admin/games/list');

        if (data.success) {
            renderGames(data.data.games);
        } else {
            gamesList.innerHTML = '<div class="loading">Failed to load games</div>';
        }
    } catch (error) {
        gamesList.innerHTML = '<div class="loading">Error loading games</div>';
    }
}

function renderGames(games) {
    if (games.length === 0) {
        gamesList.innerHTML = '<div class="loading">No active games</div>';
        return;
    }

    gamesList.innerHTML = games.map(game => {
        const playerData = typeof game.player_data === 'string'
            ? JSON.parse(game.player_data)
            : game.player_data;

        const playerCount = playerData.players?.length || 0;
        const state = playerData.state || 'unknown';
        const created_at = new Date(game.created_at).toLocaleString();

        return `
            <div class="game-item" data-id="${game.game_id}">
                <div class="item-info">
                    <div class="item-title">
                        Game ID: ${game.game_id}
                    </div>
                    <div class="item-meta">
                        State: ${state} |
                        Players: ${playerCount} |
                        Started: ${created_at}
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-small btn-danger" onclick="deleteGame('${game.game_id}')">Delete</button>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Delete a game
 */
export async function deleteGame(gameId) {
    if ( ! confirm('Are you sure you want to delete this game?')) {
        return;
    }

    try {
        const data = await apiRequest(`/admin/games/delete/${gameId}`, {
            method: 'DELETE'
        });

        if (data.success) {
            loadGames();
        } else {
            alert(data.message || 'Failed to delete game');
        }
    } catch (error) {
        alert(error.message || 'Error deleting game');
    }
}

