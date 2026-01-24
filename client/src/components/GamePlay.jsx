import { useState, useEffect, useRef } from 'react';
import { getGameState, startGame } from '../utils/api';
import WaitingRoom from './WaitingRoom';
import PlayingGame from './PlayingGame';

function GamePlay({ gameData, onLeaveGame }) {
  const [gameState, setGameState] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const lastModifiedRef = useRef(null);
  const pollIntervalRef = useRef(null);

  console.log('GamePlay gameData:', gameData);

  const fetchGameState = async () => {
    try {
      const response = await getGameState(lastModifiedRef.current);
      
      console.log('Game state response:', response);
      
      // Handle 304 Not Modified
      if (response.notModified) {
        console.log('304 Not Modified - no changes');
        return;
      }

      if (response.success && response.data) {
        console.log('Raw data:', response.data);
        
        // Transform the API response structure
        const transformedState = {
          ...response.data.player_data,
          game_id: response.data.game_id,
          created_at: response.data.created_at,
          updated_at: response.data.updated_at,
        };
        
        console.log('Transformed game state:', transformedState);
        setGameState(transformedState);
        setError('');
        
        // Update last modified timestamp if provided
        if (response.lastModified) {
          lastModifiedRef.current = response.lastModified;
          console.log('Updated lastModified:', response.lastModified);
        }
      } else {
        console.error('Game state error:', response);
        
        // Check if it's an authentication error
        if (response.error && (response.error.includes('session') || response.error.includes('auth'))) {
          setError('Session expired. Please refresh and rejoin the game.');
        } else {
          setError(response.message || 'Failed to get game state');
        }
      }
    } catch (err) {
      console.error('Game state exception:', err);
      
      // Network error or session expired
      if (err.message && (err.message.includes('401') || err.message.includes('403'))) {
        setError('Session expired. Please refresh the page to rejoin.');
      } else {
        setError(err.message || 'Failed to connect to server');
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // Initial fetch
    fetchGameState();

    // Set up polling every 3 seconds
    pollIntervalRef.current = setInterval(fetchGameState, 3000);

    return () => {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
      }
    };
  }, []); // Empty dependency array - only run once

  const handleStartGame = async () => {
    setLoading(true);
    try {
      const response = await startGame(gameData.gameId);
      if (response.success) {
        // Game state will update via polling
        setError('');
      } else {
        setError(response.message || 'Failed to start game');
      }
    } catch (err) {
      setError(err.message || 'Failed to start game');
    } finally {
      setLoading(false);
    }
  };

  if (loading && !gameState) {
    return (
      <div className="game-play">
        <div className="game-header">
          <div className="player-name">{gameData.playerName}</div>
          <div className="game-code">Game: {gameData.gameId}</div>
        </div>
        <div className="loading">Loading game...</div>
      </div>
    );
  }

  if (error && !gameState) {
    return (
      <div className="game-play">
        <div className="game-header">
          <div className="player-name">{gameData.playerName}</div>
          <div className="game-code">Game: {gameData.gameId}</div>
        </div>
        <div className="error-message">{error}</div>
        <button className="btn btn-secondary" onClick={onLeaveGame}>
          Leave Game
        </button>
      </div>
    );
  }

  const isWaiting = gameState?.state === 'waiting';
  const isPlaying = gameState?.state === 'playing' || gameState?.state === 'round_end';

  console.log('Game state check:', { state: gameState?.state, isWaiting, isPlaying });

  return (
    <div className="game-play">
      <div className="game-header">
        <div className="player-name">{gameData.playerName}</div>
        <div className="game-code">Game: {gameData.gameId}</div>
      </div>

      {isWaiting && (
        <WaitingRoom
          gameState={gameState}
          gameData={gameData}
          onStartGame={handleStartGame}
          onLeaveGame={onLeaveGame}
          error={error}
        />
      )}

      {isPlaying && (
        <PlayingGame
          gameState={gameState}
          gameData={gameData}
          onLeaveGame={onLeaveGame}
        />
      )}
    </div>
  );
}

export default GamePlay;
