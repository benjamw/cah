import { useState } from 'react';
import { joinGame } from '../utils/api';
import LateJoin from './LateJoin';

function JoinGame({ onGameJoined, onSwitchToCreate, onSwitchToRandom, playerName, setPlayerName }) {
  const [gameId, setGameId] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [lateJoinData, setLateJoinData] = useState(null);

  // Filter out dangerous characters that could be used for attacks or cause display issues
  const handleNameChange = (e) => {
    let value = e.target.value;
    
    // Remove control characters (0x00-0x1F, 0x7F-0x9F)
    // Remove zero-width and invisible characters (U+200B-U+200F)
    // Remove bidirectional text override characters (U+202A-U+202E)
    // Remove line/paragraph separators (U+2028-U+2029)
    // Remove byte order mark (U+FEFF)
    // eslint-disable-next-line no-control-regex
    value = value.replace(/[\x00-\x1F\x7F-\x9F\u200B-\u200F\u202A-\u202E\u2028\u2029\uFEFF]/g, '');
    
    setPlayerName(value);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const response = await joinGame(playerName, gameId);
      
      if (response.success) {
        onGameJoined({
          gameId: response.data.game_id || gameId,
          playerId: response.data.player_id,
          playerName: playerName,
          isCreator: false,
        });
      } else {
        // Check if it's a late join situation (status 409)
        if (response.data && response.data.game_started && response.data.player_names) {
          setLateJoinData({
            gameId: gameId,
            playerName: playerName,
            playerNames: response.data.player_names,
          });
        } else {
          // Extract error message from response
          const errorMsg = response.message || response.error || 'Failed to join game';
          setError(errorMsg);
        }
      }
    } catch (err) {
      console.error('Join game error:', err);
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
      <h1>Cards API Hub</h1>
      <h6>An online version of Cards Against Humanity</h6>
      <form onSubmit={handleSubmit} className="game-form">
        <div className="form-group">
          <label htmlFor="name">Your Name</label>
          <input
            type="text"
            id="name"
            value={playerName}
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

      <button
        type="button"
        className="btn btn-text"
        onClick={onSwitchToRandom}
        disabled={loading}
      >
        Show random pairing
      </button>
    </div>
  );
}

export default JoinGame;
