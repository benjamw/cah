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

export default HostTransferModal;
