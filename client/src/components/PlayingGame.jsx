import { useState, useEffect } from 'react';
import CardSwiper from './CardSwiper';
import CardSelector from './CardSelector';
import CzarView from './CzarView';
import { removePlayer, transferHost, leaveGame } from '../utils/api';

function PlayingGame({ gameState, gameData, onLeaveGame }) {
  const [selectedCards, setSelectedCards] = useState([]);
  const [submittedCardIds, setSubmittedCardIds] = useState([]);
  const [removing, setRemoving] = useState(null);
  const [showHostTransfer, setShowHostTransfer] = useState(false);
  const [transferring, setTransferring] = useState(false);
  
  console.log('PlayingGame - gameState:', gameState);
  console.log('PlayingGame - gameData:', gameData);
  
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
  const hasSubmittedLocally = !!localStorage.getItem(storageKey);
  
  const hasSubmitted = hasSubmittedInAPI || hasSubmittedLocally;
  
  // Get czar name from the top level (already provided by API)
  const czarName = gameState.current_czar_name || 'Unknown';

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
    ? allWhiteCards.filter(card => !submittedCardIds.includes(card.card_id))
    : allWhiteCards;
    
  const blanksNeeded = blackCard?.choices || 1;

  console.log('Current player:', currentPlayer);
  console.log('Is czar:', isCzar);
  console.log('Black card:', blackCard);
  console.log('All white cards:', allWhiteCards);
  console.log('Filtered white cards:', whiteCards);
  console.log('Submitted card IDs:', submittedCardIds);
  console.log('Czar name:', czarName);

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

    if (!window.confirm('Are you sure you want to remove this player?')) {
      return;
    }

    setRemoving(playerId);
    try {
      const response = await removePlayer(gameData.gameId, playerId);
      if (!response.success) {
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
      // Creator needs to transfer host first
      setShowHostTransfer(true);
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

  // Show czar view if player is czar
  if (isCzar) {
    return (
      <>
        <CzarView
          gameState={gameState}
          gameData={gameData}
          blackCard={blackCard}
          whiteCards={allWhiteCards}
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
    </div>
  );
}

function HostTransferModal({ players, currentPlayerId, onTransfer, onCancel, transferring }) {
  const otherPlayers = players.filter(p => p.id !== currentPlayerId);

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
        onClick={() => setExpanded(!expanded)}
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
  if (!text) return '';

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

export default PlayingGame;
