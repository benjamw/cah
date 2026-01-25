import { useState } from 'react';
import { removePlayer, transferHost, leaveGame } from '../utils/api';

function WaitingRoom({ gameState, gameData, onStartGame, onLeaveGame, error }) {
  const players = gameState?.players || [];
  const settings = gameState?.settings || {};
  const minPlayers = 3;
  const canStart = gameData.isCreator && players.length >= minPlayers;
  const [removing, setRemoving] = useState(null);
  const [showHostTransfer, setShowHostTransfer] = useState(false);
  const [transferring, setTransferring] = useState(false);

  const handleRemovePlayer = async (playerId) => {
    if ( ! gameData.isCreator || playerId === gameData.playerId) return;

    if ( ! window.confirm('Are you sure you want to remove this player?')) {
      return;
    }

    setRemoving(playerId);
    try {
      const response = await removePlayer(gameData.gameId, playerId);
      if ( ! response.success) {
        console.error('Failed to remove player:', response);
      }
      // Game state will update via polling
    } catch (err) {
      console.error('Error removing player:', err);
    } finally {
      setRemoving(null);
    }
  };

  const handleLeaveClick = async () => {
    if (gameData.isCreator && players.length > 1) {
      // Creator with other players - confirm first, then show transfer modal
      if (window.confirm('Are you sure you want to leave the game? You will need to transfer host to another player.')) {
        setShowHostTransfer(true);
      }
    } else {
      // Non-creator or creator as last player can leave directly
      if (window.confirm('Are you sure you want to leave the game?')) {
        try {
          await leaveGame(gameData.gameId);
        } catch (err) {
          console.error('Error leaving game:', err);
          // Don't show error - we're leaving anyway
        } finally {
          // Always clear local storage, even if API call failed
          onLeaveGame();
        }
      }
    }
  };

  const handleTransferAndLeave = async (newHostId) => {
    setTransferring(true);
    try {
      await transferHost(gameData.gameId, newHostId, true);
    } catch (err) {
      console.error('Error transferring host:', err);
      // Don't show error - we're leaving anyway
    } finally {
      setTransferring(false);
      setShowHostTransfer(false);
      // Always clear local storage, even if API call failed
      onLeaveGame();
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

      { ! gameData.isCreator && (
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
                {gameData.isCreator && ! player.is_creator && (
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
          <li>Rando Cardrissian: {settings.rando_enabled ? 'Enabled' : 'Disabled'}</li>
          {settings.allow_late_join && <li>Late Join: Allowed</li>}
          {gameState?.deck_counts && (
            <>
              <li>White Cards in Deck: <strong>{gameState.deck_counts.white_cards}</strong></li>
              <li>Black Cards in Deck: <strong>{gameState.deck_counts.black_cards}</strong></li>
            </>
          )}
        </ul>
      </div>

      {error && <div className="error-message">{error}</div>}

      <div className="waiting-room-actions">
        {gameData.isCreator ? (
          <>
            <button 
              className="btn btn-primary" 
              onClick={onStartGame}
              disabled={ ! canStart}
            >
              Start Game
            </button>
            { ! canStart && (
              <p className="info-message">
                Need at least {minPlayers} players to start
                ({minPlayers - players.length} more needed)
              </p>
            )}
          </>
        ) : null}

        <button className="btn btn-secondary" onClick={handleLeaveClick}>
          Leave Game
        </button>
      </div>

      {showHostTransfer && (
        <HostTransferModal
          players={players}
          currentPlayerId={gameData.playerId}
          onTransfer={handleTransferAndLeave}
          onCancel={() => setShowHostTransfer(false)}
          transferring={transferring}
        />
      )}
    </div>
  );
}

function HostTransferModal({ players, currentPlayerId, onTransfer, onCancel, transferring }) {
  const otherPlayers = players.filter(p => p.id !== currentPlayerId && ! p.is_rando);

  return (
    <div className="modal-overlay">
      <div className="modal-content">
        <h3>Transfer Host</h3>
        <p>As the game host, you must select a new host before leaving.</p>
        
        <div className="player-selection-list">
          {otherPlayers.map((player) => (
            <button
              key={player.id}
              className="player-select-btn"
              onClick={() => onTransfer(player.id)}
              disabled={transferring}
            >
              <span className="player-select-name">{player.name}</span>
            </button>
          ))}
        </div>

        <button 
          className="btn btn-secondary" 
          onClick={onCancel}
          disabled={transferring}
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

export default WaitingRoom;
