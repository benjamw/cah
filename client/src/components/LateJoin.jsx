import { useState } from 'react';
import { joinGameLate } from '../utils/api';

function LateJoin({ gameId, playerName, playerNames, onGameJoined, onBack }) {
  const [adjacentPlayer1, setAdjacentPlayer1] = useState('');
  const [adjacentPlayer2, setAdjacentPlayer2] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if ( ! adjacentPlayer1 || ! adjacentPlayer2) {
      setError('Please select both players');
      return;
    }

    if (adjacentPlayer1 === adjacentPlayer2) {
      setError('Please select two different players');
      return;
    }

    setError('');
    setLoading(true);

    try {
      const response = await joinGameLate(gameId, playerName, adjacentPlayer1, adjacentPlayer2);
      if (response.success) {
        onGameJoined({
          gameId: gameId,
          playerId: response.data.player_id,
          playerName: playerName,
          isCreator: false,
        });
      } else {
        setError(response.message || 'Failed to join game');
      }
    } catch (err) {
      setError(err.message || 'Failed to join game');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="late-join">
      <h1>Game Already Started</h1>
      <p className="late-join-info">
        The game has already started, but you can still join!
        Select two players who are sitting next to each other - you'll be placed between them.
      </p>

      <div className="join-info-box">
        <div className="info-row">
          <span className="info-label">Your Name:</span>
          <span className="info-value">{playerName}</span>
        </div>
        <div className="info-row">
          <span className="info-label">Game Code:</span>
          <span className="info-value">{gameId}</span>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="game-form">
        <div className="form-group">
          <label htmlFor="player1">First Adjacent Player</label>
          <select
            id="player1"
            value={adjacentPlayer1}
            onChange={(e) => setAdjacentPlayer1(e.target.value)}
            required
            disabled={loading}
          >
            <option value="">-- Select Player --</option>
            {playerNames.map((name, index) => (
              <option key={index} value={name}>
                {name}
              </option>
            ))}
          </select>
        </div>

        <div className="form-group">
          <label htmlFor="player2">Second Adjacent Player</label>
          <select
            id="player2"
            value={adjacentPlayer2}
            onChange={(e) => setAdjacentPlayer2(e.target.value)}
            required
            disabled={loading}
          >
            <option value="">-- Select Player --</option>
            {playerNames.map((name, index) => (
              <option key={index} value={name}>
                {name}
              </option>
            ))}
          </select>
        </div>

        <div className="seat-preview">
          <div className="seat-diagram">
            <div className="seat">{adjacentPlayer1 || '?'}</div>
            <div className="seat you-seat">YOU</div>
            <div className="seat">{adjacentPlayer2 || '?'}</div>
          </div>
          <p className="seat-hint">You'll sit between these two players</p>
        </div>

        {error && <div className="error-message">{error}</div>}

        <button type="submit" className="btn btn-primary" disabled={loading}>
          {loading ? 'Joining...' : 'Join Game'}
        </button>
      </form>

      <button
        type="button"
        className="btn btn-secondary"
        onClick={onBack}
        disabled={loading}
      >
        Back
      </button>
    </div>
  );
}

export default LateJoin;
