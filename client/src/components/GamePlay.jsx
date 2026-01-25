import { useState, useEffect, useRef } from 'react';
import { getGameState, startGame } from '../utils/api';
import WaitingRoom from './WaitingRoom';
import PlayingGame from './PlayingGame';
import GameEnd from './GameEnd';

function GamePlay({ gameData, onLeaveGame }) {
  const [gameState, setGameState] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [isCreator, setIsCreator] = useState(gameData.isCreator); // Track if current player is creator
  const [toastMessage, setToastMessage] = useState('');
  const lastModifiedRef = useRef(null);
  const pollIntervalRef = useRef(null);
  const removedRef = useRef(false); // Track if player has been removed
  const hostTransferAlertedRef = useRef(false); // Track if host transfer alert was shown
  const toastTimeoutRef = useRef(null);

  console.log('GamePlay gameData:', gameData);

  const showToast = (message) => {
    if (toastTimeoutRef.current) {
      clearTimeout(toastTimeoutRef.current);
    }
    setToastMessage(message);
    toastTimeoutRef.current = setTimeout(() => {
      setToastMessage('');
    }, 4000);
  };

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
          deck_counts: response.data.deck_counts || { white_cards: 0, black_cards: 0 },
        };
        
        // Check if current player is still in the game
        const playerStillInGame = transformedState.players?.some(
          player => player.id === gameData.playerId
        );
        
        if (!playerStillInGame && !removedRef.current) {
          console.log('Player has been removed from the game');
          removedRef.current = true; // Prevent multiple alerts
          
          // Stop polling
          if (pollIntervalRef.current) {
            clearInterval(pollIntervalRef.current);
          }
          
          alert('You have been removed from the game.');
          onLeaveGame();
          return;
        }

        // Check if player became the host (host transfer)
        const currentPlayer = transformedState.players?.find(
          player => player.id === gameData.playerId
        );
        
        if (currentPlayer && currentPlayer.is_creator && !isCreator && !hostTransferAlertedRef.current) {
          console.log('Player became the new host');
          hostTransferAlertedRef.current = true; // Prevent multiple alerts
          setIsCreator(true);
          showToast('You are now the game host!');
        }

        // Check for skipped players (show toast to everyone)
        if (transformedState.skipped_players && transformedState.skipped_players.names.length > 0) {
          const names = transformedState.skipped_players.names.join(', ');
          showToast(`Player order almost complete! ${names} ${transformedState.skipped_players.names.length === 1 ? 'was' : 'were'} skipped.`);
        }
        
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
          <div className="host-name">Host</div>
          <div className="player-name"><strong>{gameData.playerName}</strong></div>
          <div className="game-code">Game ID: <span className="code">{gameData.gameId}</span></div>
        </div>
        <div className="loading">Loading game...</div>
      </div>
    );
  }

  if (error && !gameState) {
    return (
      <div className="game-play">
        <div className="game-header">
          <div className="host-name">Host</div>
          <div className="player-name"><strong>{gameData.playerName}</strong></div>
          <div className="game-code">Game ID: <span className="code">{gameData.gameId}</span></div>
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
  const isFinished = gameState?.state === 'finished';

  // Find the host player name
  const hostPlayer = gameState?.players?.find(p => p.is_creator);
  const hostName = hostPlayer?.name || 'Host';

  console.log('Game state check:', { state: gameState?.state, isWaiting, isPlaying, isFinished });

  return (
    <div className="game-play">
      {toastMessage && (
        <div className="toast-notification">
          {toastMessage}
        </div>
      )}
      
      <div className="game-header">
        <div className="host-name">
          {isCreator ? (
            <span className="badge">Host</span>
          ) : (
            `Host: ${hostName}`
          )}
        </div>
        <div className="player-name"><strong>{gameData.playerName}</strong></div>
        <div className="game-code">Game ID: <span className="code">{gameData.gameId}</span></div>
      </div>

      {isWaiting && (
        <WaitingRoom
          gameState={gameState}
          gameData={{...gameData, isCreator}}
          onStartGame={handleStartGame}
          onLeaveGame={onLeaveGame}
          error={error}
        />
      )}

      {isPlaying && (
        <PlayingGame
          gameState={gameState}
          gameData={{...gameData, isCreator}}
          onLeaveGame={onLeaveGame}
          showToast={showToast}
        />
      )}

      {isFinished && (
        <GameEnd
          gameState={gameState}
          onLeaveGame={onLeaveGame}
        />
      )}
    </div>
  );
}

export default GamePlay;
