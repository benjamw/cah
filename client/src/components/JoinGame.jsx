import { useState } from 'react';
import { joinGame } from '../utils/api';
import LateJoin from './LateJoin';

function JoinGame({ onGameJoined, onSwitchToCreate }) {
  const [name, setName] = useState('');
  const [gameId, setGameId] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [lateJoinData, setLateJoinData] = useState(null);

  // Filter out dangerous characters that could be used for XSS
  const handleNameChange = (e) => {
    const value = e.target.value;
    // Allow all visible ASCII except: < > & " \ / ; | ` { }
    const filtered = value.replace(/[<>&"\\\/;|`{}]/g, '');
    setName(filtered);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const response = await joinGame(name, gameId);
      console.log('Join game response:', response);
      
      if (response.success) {
        onGameJoined({
          gameId: response.data.game_id || gameId,
          playerId: response.data.player_id,
          playerName: name,
          isCreator: false,
        });
      } else {
        // Check if it's a late join situation (status 409)
        if (response.data && response.data.game_started && response.data.player_names) {
          setLateJoinData({
            gameId: gameId,
            playerName: name,
            playerNames: response.data.player_names,
          });
        } else {
          setError(response.message || 'Failed to join game');
        }
      }
    } catch (err) {
      setError(err.message || 'Failed to join game');
    } finally {
      setLoading(false);
    }
  };

  // Show late join screen if game has already started
  if (lateJoinData) {
    return (
      <LateJoin
        gameId={lateJoinData.gameId}
        playerName={lateJoinData.playerName}
        playerNames={lateJoinData.playerNames}
        onGameJoined={onGameJoined}
        onBack={() => setLateJoinData(null)}
      />
    );
  }

  return (
    <div className="join-game">
      <h1>Cards Against Humanity</h1>
      
      <form onSubmit={handleSubmit} className="game-form">
        <div className="form-group">
          <label htmlFor="name">Your Name</label>
          <input
            type="text"
            id="name"
            value={name}
            onChange={handleNameChange}
            placeholder="Enter your name"
            required
            maxLength={50}
            disabled={loading}
          />
        </div>

        <div className="form-group">
          <label htmlFor="gameId">Game Code</label>
          <input
            type="text"
            id="gameId"
            value={gameId}
            onChange={(e) => setGameId(e.target.value.toUpperCase())}
            placeholder="Enter game code"
            required
            maxLength={10}
            disabled={loading}
          />
        </div>

        {error && <div className="error-message">{error}</div>}

        <button type="submit" className="btn btn-primary" disabled={loading}>
          {loading ? 'Joining...' : 'Join Game'}
        </button>
      </form>

      <div className="divider">
        <span>OR</span>
      </div>

      <button
        type="button"
        className="btn btn-secondary"
        onClick={onSwitchToCreate}
        disabled={loading}
      >
        Create New Game
      </button>
    </div>
  );
}

export default JoinGame;
