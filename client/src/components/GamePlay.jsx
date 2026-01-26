import { useState, useEffect, useRef, useCallback } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faXmark, faTrophy, faPause, faPlay, faForward, faArrowsRotate } from '@fortawesome/free-solid-svg-icons';
import { getGameState, startGame } from '../utils/api';
import { getSeenToasts, saveSeenToasts } from '../utils/storage';
import WaitingRoom from './WaitingRoom';
import PlayingGame from './PlayingGame';
import GameEnd from './GameEnd';

// Map icon names from backend to FontAwesome icons
const iconMap = {
  trophy: faTrophy,
  pause: faPause,
  play: faPlay,
  skip: faForward,
  refresh: faArrowsRotate,
};

function GamePlay({ gameData, onLeaveGame }) {
  const [gameState, setGameState] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [isCreator, setIsCreator] = useState(gameData.isCreator); // Track if current player is creator
  const [toastMessage, setToastMessage] = useState('');
  const [toastIcon, setToastIcon] = useState(null);
  const lastModifiedRef = useRef(null);
  const pollIntervalRef = useRef(null);
  const removedRef = useRef(false); // Track if player has been removed
  const hostTransferAlertedRef = useRef(false); // Track if host transfer alert was shown
  const toastTimeoutRef = useRef(null);
  const seenToastsRef = useRef(getSeenToasts(gameData.gameId)); // Track seen toast IDs

  const showToast = useCallback((message, icon = null) => {
    if (toastTimeoutRef.current) {
      clearTimeout(toastTimeoutRef.current);
    }
    setToastMessage(message);
    setToastIcon(icon);
    toastTimeoutRef.current = setTimeout(() => {
      setToastMessage('');
      setToastIcon(null);
    }, 7000); // 7 seconds display time
  }, []);

  const fetchGameState = useCallback(async () => {
    try {
      const response = await getGameState(lastModifiedRef.current);
      
      // Handle 304 Not Modified
      if (response.notModified) {
        return;
      }

      if (response.success && response.data) {
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
        
        if ( ! playerStillInGame && ! removedRef.current) {
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
        
        if (currentPlayer && currentPlayer.is_creator && ! isCreator && ! hostTransferAlertedRef.current) {
          hostTransferAlertedRef.current = true; // Prevent multiple alerts
          setIsCreator(true);
          showToast('You are now the game host!');
        }

        // Handle toasts from backend
        if (transformedState.toasts && Array.isArray(transformedState.toasts)) {
          const seenToasts = seenToastsRef.current;
          const newToasts = transformedState.toasts.filter(toast => !seenToasts.includes(toast.id));
          
          if (newToasts.length > 0) {
            // Show the first new toast (FIFO)
            const toast = newToasts[0];
            const icon = toast.icon && iconMap[toast.icon] ? iconMap[toast.icon] : null;
            showToast(toast.message, icon);
            
            // Mark all new toasts as seen
            const updatedSeenToasts = [...seenToasts, ...newToasts.map(t => t.id)];
            seenToastsRef.current = updatedSeenToasts;
            saveSeenToasts(gameData.gameId, updatedSeenToasts);
          }
        }
        
        setGameState(transformedState);
        setError('');
        
        // Update last modified timestamp if provided
        if (response.lastModified) {
          lastModifiedRef.current = response.lastModified;
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
  }, [gameData.playerId, isCreator, onLeaveGame, showToast]);

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
  }, [fetchGameState]); // Add fetchGameState to dependencies

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

  if (loading && ! gameState) {
    return (
      <div className="game-play">
        <div className="game-header">
          <div className="host-name">Host</div>
          <div className="player-name">
            <strong>{gameData.playerName}</strong>
          </div>
          <div className="game-code">Game ID: <span className="code">{gameData.gameId}</span></div>
        </div>
        <div className="loading">Loading game...</div>
      </div>
    );
  }

  if (error && ! gameState) {
    return (
      <div className="game-play">
        <div className="game-header">
          <div className="host-name">Host</div>
          <div className="player-name">
            <strong>{gameData.playerName}</strong>
          </div>
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

  // Find current player to get their score
  const currentPlayer = gameState?.players?.find(p => p.id === gameData.playerId);

  return (
    <div className="game-play">
      {toastMessage && (
        <div className="toast-notification">
          {toastIcon && <FontAwesomeIcon icon={toastIcon} />}
          <span>{toastMessage}</span>
          <button 
            className="toast-close-btn" 
            onClick={() => {
              if (toastTimeoutRef.current) {
                clearTimeout(toastTimeoutRef.current);
              }
              setToastMessage('');
              setToastIcon(null);
            }}
            aria-label="Close notification"
          >
            <FontAwesomeIcon icon={faXmark} />
          </button>
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
        <div className="player-name">
          <strong>{gameData.playerName}</strong>
          {currentPlayer && <span className="player-score"> ({currentPlayer.score} {currentPlayer.score === 1 ? 'pt' : 'pts'})</span>}
        </div>
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
