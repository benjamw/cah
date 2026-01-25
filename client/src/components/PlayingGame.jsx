import { useState, useEffect } from 'react';
import CardSwiper from './CardSwiper';
import CardSelector from './CardSelector';
import CzarView from './CzarView';
import { removePlayer, transferHost, leaveGame, placeSkippedPlayer } from '../utils/api';

function PlayingGame({ gameState, gameData, onLeaveGame, showToast }) {
  const [selectedCards, setSelectedCards] = useState([]);
  const [submittedCardIds, setSubmittedCardIds] = useState([]);
  const [removing, setRemoving] = useState(null);
  const [showHostTransfer, setShowHostTransfer] = useState(false);
  const [transferring, setTransferring] = useState(false);
  const [showSkippedPlayerModal, setShowSkippedPlayerModal] = useState(false);
  const [placingPlayer, setPlacingPlayer] = useState(false);
  
  const currentPlayer = gameState.players?.find(
    (p) => p.id === gameData.playerId
  );
  
  // Check if current player is czar using current_czar_id
  const isCzar = gameState.current_czar_id === gameData.playerId;
  const blackCard = gameState.current_black_card;
  
  // Check if player has submitted by looking in submissions array
  const hasSubmittedInAPI = gameState.submissions?.some(
    (sub) => sub.player_id === gameData.playerId
  ) || false;
  
  // Also check localStorage for persistent tracking across refreshes
  const storageKey = `submitted_cards_${gameData.gameId}_${gameState.current_round}`;
  const hasSubmittedLocally = !! localStorage.getItem(storageKey);
  
  const hasSubmitted = hasSubmittedInAPI || hasSubmittedLocally;
  
  // Get czar name from the top level (already provided by API)
  const czarName = gameState.current_czar_name || 'Unknown';

  // Check if there are skipped players and current player is host
  const hasSkippedPlayers = gameState.skipped_players && gameState.skipped_players.ids.length > 0;
  const shouldShowSkippedModal = hasSkippedPlayers && gameData.isCreator && ! showSkippedPlayerModal;

  useEffect(() => {
    if (shouldShowSkippedModal) {
      setShowSkippedPlayerModal(true);
    }
  }, [shouldShowSkippedModal]);

  // Load submitted cards from localStorage and filter them out of the hand
  useEffect(() => {
    const storageKey = `submitted_cards_${gameData.gameId}_${gameState.current_round}`;
    const stored = localStorage.getItem(storageKey);
    if (stored && hasSubmitted) {
      try {
        const submitted = JSON.parse(stored);
        // Extract card IDs from the stored card objects
        const cardIds = submitted.map(card => card.card_id);
        setSubmittedCardIds(cardIds);
      } catch (e) {
        console.error('Failed to parse submitted cards:', e);
      }
    } else {
      setSubmittedCardIds([]);
    }
  }, [gameData.gameId, gameState.current_round, hasSubmitted]);

  // Filter out submitted cards from the hand
  const allWhiteCards = currentPlayer?.hand || [];
  const whiteCards = hasSubmitted 
    ? allWhiteCards.filter(card => ! submittedCardIds.includes(card.card_id))
    : allWhiteCards;
    
  const blanksNeeded = blackCard?.choices || 1;

  // Calculate if cards were added to hand
  const cardsInHand = whiteCards.length;
  const normalHandSize = gameState.hand_size || 10;
  const cardsAdded = Math.max(0, cardsInHand - normalHandSize);

  const handleCardSelect = (cardId) => {
    if (hasSubmitted || isCzar) return;

    setSelectedCards((prev) => {
      if (prev.includes(cardId)) {
        return prev.filter((id) => id !== cardId);
      }
      if (prev.length < blanksNeeded) {
        return [...prev, cardId];
      }
      return prev;
    });
  };

  const handleCardReorder = (fromIndex, toIndex) => {
    setSelectedCards((prev) => {
      const newOrder = [...prev];
      const [moved] = newOrder.splice(fromIndex, 1);
      newOrder.splice(toIndex, 0, moved);
      return newOrder;
    });
  };

  const handleRemovePlayer = async (playerId) => {
    if (playerId === gameData.playerId) return; // Can't remove self

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
    if (currentPlayer?.is_creator) {
      // Creator with other players - confirm first, then show transfer modal
      if (window.confirm('Are you sure you want to leave the game? You will need to transfer host to another player.')) {
        setShowHostTransfer(true);
      }
    } else {
      // Non-creator calls API to leave
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

  const handlePlaceSkippedPlayer = async (skippedPlayerId, beforePlayerId) => {
    setPlacingPlayer(true);
    try {
      const response = await placeSkippedPlayer(gameData.gameId, skippedPlayerId, beforePlayerId);
      if ( ! response.success) {
        console.error('Failed to place skipped player:', response);
        showToast('Failed to place player in order');
      } else {
        // Check if all players are now placed
        const updatedState = response.data.game_state;
        if (updatedState.order_locked) {
          showToast('Player order is now complete and locked!');
          setShowSkippedPlayerModal(false);
        }
        // State will update via polling
      }
    } catch (err) {
      console.error('Error placing skipped player:', err);
      showToast('Error placing player in order');
    } finally {
      setPlacingPlayer(false);
    }
  };

  // Show czar view if player is czar
  if (isCzar) {
    return (
      <>
        <CzarView
          gameState={gameState}
          gameData={gameData}
          blackCard={blackCard}
          whiteCards={allWhiteCards}
          showToast={showToast}
        />
        {currentPlayer?.is_creator && (
          <PlayerManagement
            players={gameState.players || []}
            gameData={gameData}
            onRemovePlayer={handleRemovePlayer}
            removing={removing}
          />
        )}
        <div className="leave-game-section">
          <button 
            className="btn btn-danger"
            onClick={handleLeaveClick}
          >
            Leave Game
          </button>
        </div>
        {showHostTransfer && (
          <HostTransferModal
            players={gameState.players || []}
            currentPlayerId={gameData.playerId}
            onTransfer={handleTransferAndLeave}
            onCancel={() => setShowHostTransfer(false)}
            transferring={transferring}
          />
        )}
      </>
    );
  }

  // Show regular player view
  return (
    <div className="playing-game">
      {cardsAdded > 0 && (
        <div className="notification-banner">
          {cardsAdded} card{cardsAdded > 1 ? 's have' : ' has'} been added to your hand. Choose {blanksNeeded}.
        </div>
      )}

      <CardSelector
        selectedCards={selectedCards}
        whiteCards={allWhiteCards}
        onCardSelect={handleCardSelect}
        onCardReorder={handleCardReorder}
        blanksNeeded={blanksNeeded}
        hasSubmitted={hasSubmitted}
        gameState={gameState}
        gameData={gameData}
        onCardsSubmitted={() => setSelectedCards([])}
      />

      <div className="card-section">
        <CardSwiper
          cards={whiteCards}
          selectedCards={selectedCards}
          onCardSelect={handleCardSelect}
          cardType="white"
          disabled={hasSubmitted}
        />
      </div>

      <div className="card-section black-card-section">
        <h3>Card Czar: {czarName}</h3>
        {blackCard ? (
          <div className="card card-black">
            <div className="card-content" dangerouslySetInnerHTML={{ __html: formatCardText(blackCard.value) }} />
            {blanksNeeded > 1 && (
              <div className="card-pick">Pick {blanksNeeded}</div>
            )}
          </div>
        ) : (
          <div className="card card-empty">No black card available</div>
        )}
      </div>

      {currentPlayer?.is_creator && (
        <PlayerManagement
          players={gameState.players || []}
          gameData={gameData}
          onRemovePlayer={handleRemovePlayer}
          removing={removing}
        />
      )}

      <div className="leave-game-section">
        <button 
          className="btn btn-danger"
          onClick={handleLeaveClick}
        >
          Leave Game
        </button>
      </div>

      {showHostTransfer && (
        <HostTransferModal
          players={gameState.players || []}
          currentPlayerId={gameData.playerId}
          onTransfer={handleTransferAndLeave}
          onCancel={() => setShowHostTransfer(false)}
          transferring={transferring}
        />
      )}

      {showSkippedPlayerModal && hasSkippedPlayers && (
        <SkippedPlayerModal
          skippedPlayers={gameState.skipped_players}
          players={gameState.players || []}
          playerOrder={gameState.player_order || []}
          onPlacePlayer={handlePlaceSkippedPlayer}
          onCancel={() => setShowSkippedPlayerModal(false)}
          placing={placingPlayer}
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

function PlayerManagement({ players, gameData, onRemovePlayer, removing }) {
  const [expanded, setExpanded] = useState(false);

  return (
    <div className="player-management">
      <button 
        className="btn btn-secondary toggle-players-btn"
        onClick={() => setExpanded( ! expanded)}
      >
        {expanded ? 'Hide' : 'Manage'} Players ({players.length})
      </button>

      {expanded && (
        <div className="players-list-inline">
          {players.map((player) => (
            <div key={player.id} className="player-inline-item">
              <span className="player-inline-name">{player.name}</span>
              {player.id !== gameData.playerId && (
                <button
                  className="btn-remove-inline"
                  onClick={() => onRemovePlayer(player.id)}
                  disabled={removing === player.id}
                  aria-label="Remove player"
                >
                  {removing === player.id ? '...' : '×'}
                </button>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function formatCardText(text) {
  if ( ! text) return '';

  // Protect sequences of 3+ underscores (blanks) by replacing them temporarily
  // Using vertical tab character (U+000B) which won't appear in cards or be processed by markdown
  const blankPlaceholder = '\u000B';
  let formatted = text.replace(/_{3,}/g, blankPlaceholder);
  
  // Convert newlines to <br>
  formatted = formatted.replace(/\n/g, '<br>');
  
  // Simple markdown-like formatting
  // Bold: *text*
  formatted = formatted.replace(/\*(.+?)\*/g, '<strong>$1</strong>');
  
  // Italic: _text_
  formatted = formatted.replace(/_(.+?)_/g, '<em>$1</em>');
  
  // Restore the blanks
  formatted = formatted.replace(new RegExp(blankPlaceholder, 'g'), '_____');
  
  return formatted;
}

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

export default PlayingGame;
