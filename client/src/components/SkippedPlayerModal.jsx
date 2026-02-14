import { useState } from 'react';

function SkippedPlayerModal({ skippedPlayers, players, playerOrder, onPlacePlayer, onCancel, placing }) {
  const [currentSkippedIndex, setCurrentSkippedIndex] = useState(0);
  const [selectedBeforePlayer, setSelectedBeforePlayer] = useState('');

  if ( ! skippedPlayers || skippedPlayers.ids.length === 0) {
    return null;
  }

  const currentSkippedId = skippedPlayers.ids[currentSkippedIndex];
  const currentSkippedName = skippedPlayers.names[currentSkippedIndex];

  // Get players in the current order
  const orderedPlayers = playerOrder
    .map(id => players.find(p => p.id === id))
    .filter(p => p && ! p.is_rando);

  const handlePlace = () => {
    if ( ! selectedBeforePlayer) return;

    onPlacePlayer(currentSkippedId, selectedBeforePlayer);

    // Move to next skipped player or close
    if (currentSkippedIndex < skippedPlayers.ids.length - 1) {
      setCurrentSkippedIndex(currentSkippedIndex + 1);
      setSelectedBeforePlayer('');
    }
  };

  return (
    <div className="modal-overlay">
      <div className="modal-content">
        <h2>Place Skipped Player</h2>
        <p>
          <strong>{currentSkippedName}</strong> was skipped in the rotation.
          <br />
          Select which player they sit <strong>before</strong> (to their right):
        </p>

        <p className="modal-hint">
          Player {currentSkippedIndex + 1} of {skippedPlayers.ids.length}
        </p>

        <div className="player-selection-list">
          {orderedPlayers.map((player) => (
            <button
              key={player.id}
              className={`player-select-btn ${selectedBeforePlayer === player.id ? 'selected' : ''}`}
              onClick={() => setSelectedBeforePlayer(player.id)}
              disabled={placing}
            >
              <span className="player-select-name">{player.name}</span>
            </button>
          ))}
        </div>

        <div className="modal-actions">
          <button
            className="btn btn-primary"
            onClick={handlePlace}
            disabled={ ! selectedBeforePlayer || placing}
          >
            {placing ? 'Placing...' : 'Place Player'}
          </button>
          <button
            className="btn btn-secondary"
            onClick={onCancel}
            disabled={placing}
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}

export default SkippedPlayerModal;
