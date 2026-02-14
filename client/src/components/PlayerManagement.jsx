import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPause, faPlay, faCircleXmark } from '@fortawesome/free-solid-svg-icons';

function PlayerManagement({ players, gameData, onRemovePlayer, onTogglePause, removing, pausing }) {
  const [expanded, setExpanded] = useState(false);

  // Filter out Rando for count
  const nonRandoPlayers = players.filter((p) => !p.is_rando);

  return (
    <div className="player-management">
      <button
        className="btn btn-secondary toggle-players-btn"
        onClick={() => setExpanded(!expanded)}
      >
        {expanded ? 'Hide' : 'Manage'} Players ({nonRandoPlayers.length})
      </button>

      {expanded && (
        <div className="players-list-inline">
          {nonRandoPlayers.map((player) => (
            <div key={player.id} className="player-inline-item">
              <span className={`player-inline-name ${player.is_paused ? 'paused' : ''}`}>
                {player.is_paused && (
                  <>
                    <FontAwesomeIcon icon={faPause} />{' '}
                  </>
                )}
                {player.name}
                {player.id === gameData.playerId && ' (You)'}
              </span>
              <div className="player-actions">
                {/* Only show pause button for other players, not yourself */}
                {player.id !== gameData.playerId && (
                  <button
                    className={`btn-pause-inline ${player.is_paused ? 'unpausing' : ''}`}
                    onClick={() => onTogglePause(player.id)}
                    disabled={pausing === player.id}
                    aria-label={player.is_paused ? 'Unpause player' : 'Pause player'}
                    title={player.is_paused ? 'Unpause player' : 'Pause player'}
                  >
                    {pausing === player.id ? (
                      '...'
                    ) : (
                      <FontAwesomeIcon icon={player.is_paused ? faPlay : faPause} />
                    )}
                  </button>
                )}
                {player.id !== gameData.playerId && (
                  <button
                    className="btn-remove-icon"
                    onClick={() => onRemovePlayer(player.id)}
                    disabled={removing === player.id}
                    aria-label="Remove player"
                  >
                    {removing === player.id ? '...' : <FontAwesomeIcon icon={faCircleXmark} />}
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

export default PlayerManagement;
