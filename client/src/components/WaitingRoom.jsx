import { useState } from 'react';
import { removePlayer } from '../utils/api';

function WaitingRoom({ gameState, gameData, onStartGame, onLeaveGame, error }) {
  const players = gameState?.players || [];
  const settings = gameState?.settings || {};
  const minPlayers = 3;
  const canStart = gameData.isCreator && players.length >= minPlayers;
  const [removing, setRemoving] = useState(null);

  console.log('WaitingRoom props:', { gameState, gameData, players, settings });

  const handleRemovePlayer = async (playerId) => {
    if (!gameData.isCreator || playerId === gameData.playerId) return;

    if (!window.confirm('Are you sure you want to remove this player?')) {
      return;
    }

    setRemoving(playerId);
    try {
      const response = await removePlayer(gameData.gameId, playerId);
      if (!response.success) {
        console.error('Failed to remove player:', response);
      }
      // Game state will update via polling
    } catch (err) {
      console.error('Error removing player:', err);
    } finally {
      setRemoving(null);
    }
  };

  return (
    <div className="waiting-room">
      {gameData.isCreator && players.length < minPlayers && (
        <div className="waiting-message">
          <h2>Waiting for other players to join...</h2>
          <p className="waiting-subtext">
            Share the game code <strong>{gameData.gameId}</strong> with your friends!
          </p>
        </div>
      )}

      {!gameData.isCreator && (
        <div className="waiting-message">
          <h2>Waiting for game to start...</h2>
          <p className="waiting-subtext">
            The host will start the game when ready.
          </p>
        </div>
      )}

      {gameData.isCreator && players.length >= minPlayers && (
        <div className="ready-message">
          <h2>Ready to start!</h2>
          <p className="waiting-subtext">
            You have enough players to begin.
          </p>
        </div>
      )}

      <div className="player-list">
        <h3>Players ({players.length}/{settings.max_players || 0})</h3>
        {players.length === 0 ? (
          <p className="empty-list">No players yet...</p>
        ) : (
          <ul>
            {players.map((player) => (
              <li key={player.id} className="player-item">
                <div className="player-info">
                  <span className="player-name">{player.name}</span>
                  {player.is_creator && <span className="badge">Host</span>}
                </div>
                {gameData.isCreator && !player.is_creator && (
                  <button
                    className="btn-remove-player"
                    onClick={() => handleRemovePlayer(player.id)}
                    disabled={removing === player.id}
                    aria-label="Remove player"
                  >
                    {removing === player.id ? '...' : '×'}
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="game-settings">
        <h3>Game Settings</h3>
        <ul className="settings-list">
          <li>Points to Win: {settings.max_score || 8}</li>
          <li>Hand Size: {settings.hand_size || 10}</li>
          {settings.rando_cardrissian && <li>Rando Cardrissian: Enabled</li>}
          {settings.allow_late_join && <li>Late Join: Allowed</li>}
        </ul>
      </div>

      {error && <div className="error-message">{error}</div>}

      <div className="waiting-room-actions">
        {gameData.isCreator ? (
          <>
            <button 
              className="btn btn-primary" 
              onClick={onStartGame}
              disabled={!canStart}
            >
              Start Game
            </button>
            {!canStart && (
              <p className="info-message">
                Need at least {minPlayers} players to start
                ({minPlayers - players.length} more needed)
              </p>
            )}
          </>
        ) : (
          <p className="info-message">Waiting for host to start the game...</p>
        )}

        <button className="btn btn-secondary" onClick={onLeaveGame}>
          Leave Game
        </button>
      </div>
    </div>
  );
}

export default WaitingRoom;
