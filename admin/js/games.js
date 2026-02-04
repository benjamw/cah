/**
 * Games management module
 */

import { apiRequest } from './api.js';

// DOM elements (initialized in initGames)
let gamesList;

// Pagination state
let currentPage = 1;
let itemsPerPage = 20;
let allGames = [];

/**
 * Initialize games module DOM elements
 */
export function initGames() {
    gamesList = document.getElementById('games-list');
    setupPaginationListeners();
    setupGameActionsListener();
}

/**
 * Setup pagination event listeners
 */
function setupPaginationListeners() {
    document.querySelectorAll('.pagination-prev').forEach(btn => {
        btn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                renderGames(allGames);
            }
        });
    });
    
    document.querySelectorAll('.pagination-next').forEach(btn => {
        btn.addEventListener('click', () => {
            const totalPages = Math.ceil(allGames.length / itemsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                renderGames(allGames);
            }
        });
    });
}

/**
 * Setup event delegation for game action buttons
 */
function setupGameActionsListener() {
    gamesList.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button) return;
        
        const action = button.dataset.action;
        const gameId = button.dataset.gameId;
        
        if (action === 'view') {
            viewGame(gameId);
        } else if (action === 'delete') {
            deleteGame(gameId);
        }
    });
}

/**
 * Load and display games
 */
export async function loadGames() {
    gamesList.innerHTML = '<div class="loading">Loading games...</div>';

    try {
        const data = await apiRequest('/admin/games/list');

        if (data.success) {
            allGames = data.data.games;
            currentPage = 1;
            renderGames(allGames);
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
        updatePagination(0);
        return;
    }

    // Paginate
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const paginatedGames = games.slice(startIndex, endIndex);

    gamesList.innerHTML = paginatedGames.map(game => {
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
                    <button class="btn btn-small btn-primary" data-action="view" data-game-id="${game.game_id}">View</button>
                    <button class="btn btn-small btn-danger" data-action="delete" data-game-id="${game.game_id}">Delete</button>
                </div>
            </div>
        `;
    }).join('');
    
    updatePagination(games.length);
}

function updatePagination(total) {
    const totalPages = Math.ceil(total / itemsPerPage);
    const pageText = `Page ${currentPage} of ${totalPages} (${total} games)`;
    const prevDisabled = currentPage <= 1;
    const nextDisabled = currentPage >= totalPages || total === 0;
    
    // Update all pagination controls
    document.querySelectorAll('.pagination-info').forEach(el => {
        el.textContent = pageText;
    });
    document.querySelectorAll('.pagination-prev').forEach(btn => {
        btn.disabled = prevDisabled;
    });
    document.querySelectorAll('.pagination-next').forEach(btn => {
        btn.disabled = nextDisabled;
    });
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

/**
 * View game details
 */
export function viewGame(gameId) {
    window.location.href = `/admin/view-game.html?id=${gameId}`;
}

