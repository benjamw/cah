/**
 * View Game module - Display detailed game information
 */

import { apiRequest } from './api.js';

// DOM elements
let contentContainer;
let gameId;

/**
 * Initialize view game module
 */
export function initViewGame() {
    contentContainer = document.getElementById('game-details-content');
    
    // Get game ID from URL
    const urlParams = new URLSearchParams(window.location.search);
    gameId = urlParams.get('id');
    
    if (!gameId) {
        contentContainer.innerHTML = '<div class="loading">Error: No game ID provided</div>';
    }
}

/**
 * Load and display game details
 */
export async function loadGameDetails() {
    if (!gameId) {
        return;
    }
    
    contentContainer.innerHTML = '<div class="loading">Loading game details...</div>';
    
    try {
        // Fetch game data including history
        const [gameData, historyData] = await Promise.all([
            apiRequest(`/games/view/${gameId}`),
            apiRequest(`/admin/games/${gameId}/history`).catch(() => ({ success: false, data: { history: [] } }))
        ]);
        
        if (!gameData.success) {
            contentContainer.innerHTML = '<div class="loading">Failed to load game details</div>';
            return;
        }
        
        const game = gameData.data.game;
        const history = historyData.success ? historyData.data.history : [];
        
        renderGameDetails(game, history);
    } catch (error) {
        console.error('Error loading game:', error);
        contentContainer.innerHTML = '<div class="loading">Error loading game details</div>';
    }
}

/**
 * Render complete game details
 */
function renderGameDetails(game, history) {
    const playerData = typeof game.player_data === 'string'
        ? JSON.parse(game.player_data)
        : game.player_data;
    
    contentContainer.innerHTML = `
        ${renderOverview(game, playerData)}
        ${renderSettings(playerData.settings || {})}
        ${renderPlayers(playerData.players || [])}
        ${renderCurrentRound(playerData)}
        ${renderHistory(history, playerData.players || [])}
        ${renderRawData(game)}
    `;
}

/**
 * Render game overview section
 */
function renderOverview(game, playerData) {
    const createdAt = new Date(game.created_at).toLocaleString();
    const updatedAt = new Date(game.updated_at).toLocaleString();
    const state = playerData.state || 'unknown';
    const currentRound = playerData.current_round || 0;
    const playerCount = playerData.players?.length || 0;
    const maxPlayers = playerData.settings?.max_players || 10;
    
    return `
        <div class="detail-card">
            <h3>Game Overview</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Game ID</div>
                    <div class="detail-value">${game.game_id}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">State</div>
                    <div class="detail-value">
                        <span class="badge badge-${state === 'playing' ? 'active' : 'inactive'}">${state}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Current Round</div>
                    <div class="detail-value">${currentRound}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Players</div>
                    <div class="detail-value">${playerCount} / ${maxPlayers}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Created</div>
                    <div class="detail-value">${createdAt}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Last Updated</div>
                    <div class="detail-value">${updatedAt}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Creator</div>
                    <div class="detail-value">${playerData.creator_id || 'Unknown'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Current Czar</div>
                    <div class="detail-value">${playerData.current_czar_id || 'None'}</div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Render game settings section
 */
function renderSettings(settings) {
    return `
        <div class="detail-card">
            <h3>Game Settings</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Max Players</div>
                    <div class="detail-value">${settings.max_players || 10}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Score Limit</div>
                    <div class="detail-value">${settings.score_limit || 10}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Hand Size</div>
                    <div class="detail-value">${settings.hand_size || 10}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Max Randos</div>
                    <div class="detail-value">${settings.max_randos || 0}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Blank Cards</div>
                    <div class="detail-value">${settings.blank_cards_enabled ? 'Enabled' : 'Disabled'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Czar Rotation</div>
                    <div class="detail-value">${settings.czar_rotation || 'sequential'}</div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Render players section
 */
function renderPlayers(players) {
    if (players.length === 0) {
        return `
            <div class="detail-card">
                <h3>Players</h3>
                <div class="no-history">No players</div>
            </div>
        `;
    }
    
    // Sort players by score (descending)
    const sortedPlayers = [...players].sort((a, b) => (b.score || 0) - (a.score || 0));
    
    const playersHtml = sortedPlayers.map(player => {
        const isCzar = player.is_czar || false;
        const isPaused = player.paused || false;
        const handSize = player.hand?.length || 0;
        const classes = ['player-card'];
        if (isCzar) classes.push('czar');
        if (isPaused) classes.push('paused');
        
        return `
            <div class="${classes.join(' ')}">
                <div class="player-name">
                    ${escapeHtml(player.name)}
                    ${isCzar ? '👑' : ''}
                    ${isPaused ? '⏸️' : ''}
                </div>
                <div class="player-meta">
                    ID: ${player.player_id}<br>
                    Score: ${player.score || 0}<br>
                    Hand: ${handSize} cards<br>
                    Status: ${isPaused ? 'Paused' : 'Active'}
                </div>
            </div>
        `;
    }).join('');
    
    return `
        <div class="detail-card">
            <h3>Players (${players.length})</h3>
            <div class="players-list">
                ${playersHtml}
            </div>
        </div>
    `;
}

/**
 * Render current round section
 */
function renderCurrentRound(playerData) {
    if (!playerData.current_prompt_card) {
        return `
            <div class="detail-card">
                <h3>Current Round</h3>
                <div class="no-history">No active round</div>
            </div>
        `;
    }
    
    const promptCard = playerData.current_prompt_card;
    const submissions = playerData.submissions || [];
    const submittedCount = submissions.filter(s => s.cards && s.cards.length > 0).length;
    const totalPlayers = (playerData.players?.length || 1) - 1; // Exclude czar
    
    const submissionsHtml = submissions.length > 0
        ? `
            <h4 style="margin-top: 1.5rem; margin-bottom: 1rem;">Submissions (${submittedCount} / ${totalPlayers})</h4>
            <div class="response-cards">
                ${submissions.map(sub => {
                    if (!sub.cards || sub.cards.length === 0) return '';
                    
                    const cardsText = sub.cards.map(card => {
                        const cardText = typeof card === 'object' ? card.copy : card;
                        return escapeHtml(cardText);
                    }).join(' / ');
                    
                    return `
                        <div class="response-card">
                            <div style="font-size: 0.75rem; color: var(--secondary-color); margin-bottom: 0.5rem;">
                                Player: ${sub.player_id}
                            </div>
                            ${cardsText}
                        </div>
                    `;
                }).join('')}
            </div>
        `
        : '<p style="color: var(--secondary-color);">No submissions yet</p>';
    
    return `
        <div class="detail-card">
            <h3>Current Round (Round ${playerData.current_round || 0})</h3>
            <div class="prompt-card">
                ${escapeHtml(promptCard.copy || promptCard.text || 'Unknown prompt')}
            </div>
            ${submissionsHtml}
        </div>
    `;
}

/**
 * Render round history section
 */
function renderHistory(history, players) {
    if (!history || history.length === 0) {
        return `
            <div class="detail-card">
                <h3>Round History</h3>
                <div class="no-history">No completed rounds yet</div>
            </div>
        `;
    }
    
    // Sort history by round number (descending)
    const sortedHistory = [...history].sort((a, b) => (b.round || 0) - (a.round || 0));
    
    const historyHtml = sortedHistory.map(round => {
        const czarName = getPlayerName(round.czar_id, players);
        const winnerName = getPlayerName(round.winner_id, players);
        
        const promptText = round.prompt_card?.copy || round.prompt_card?.text || 'Unknown prompt';
        
        const submissionsHtml = round.submissions && round.submissions.length > 0
            ? `
                <h4 style="margin-top: 1rem; margin-bottom: 0.75rem;">Submissions</h4>
                <div class="response-cards">
                    ${round.submissions.map(sub => {
                        const isWinner = sub.player_id === round.winner_id;
                        const playerName = getPlayerName(sub.player_id, players);
                        
                        const cardsText = (sub.cards || []).map(card => {
                            const cardText = typeof card === 'object' ? card.copy : card;
                            return escapeHtml(cardText);
                        }).join(' / ');
                        
                        return `
                            <div class="response-card ${isWinner ? 'winner' : ''}">
                                ${isWinner ? '<div class="winner-badge">WINNER</div>' : ''}
                                <div style="font-size: 0.75rem; color: var(--secondary-color); margin-bottom: 0.5rem;">
                                    ${playerName}
                                </div>
                                ${cardsText}
                            </div>
                        `;
                    }).join('')}
                </div>
            `
            : '';
        
        return `
            <div class="history-item">
                <div class="history-header">
                    <div class="history-round">Round ${round.round}</div>
                    <div style="color: var(--secondary-color); font-size: 0.875rem;">
                        Czar: ${czarName} | Winner: ${winnerName}
                    </div>
                </div>
                <div class="prompt-card">
                    ${escapeHtml(promptText)}
                </div>
                ${submissionsHtml}
            </div>
        `;
    }).join('');
    
    return `
        <div class="detail-card">
            <h3>Round History (${history.length} ${history.length === 1 ? 'round' : 'rounds'})</h3>
            ${historyHtml}
        </div>
    `;
}

/**
 * Render raw JSON data section
 */
function renderRawData(game) {
    const jsonStr = JSON.stringify(game, null, 2);
    
    return `
        <div class="detail-card">
            <h3>Raw Game Data</h3>
            <details>
                <summary style="cursor: pointer; padding: 0.5rem; background: var(--bg-color); border-radius: 4px;">
                    Click to view raw JSON data
                </summary>
                <div class="json-viewer">
                    <pre>${escapeHtml(jsonStr)}</pre>
                </div>
            </details>
        </div>
    `;
}

/**
 * Get player name from player ID
 */
function getPlayerName(playerId, players) {
    if (!playerId) return 'Unknown';
    const player = players.find(p => p.player_id === playerId);
    return player ? escapeHtml(player.name) : playerId;
}

/**
 * Escape HTML special characters
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
