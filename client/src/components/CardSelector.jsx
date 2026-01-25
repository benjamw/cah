import { useState, useEffect } from 'react';
import { submitCards } from '../utils/api';

function CardSelector({
  selectedCards,
  whiteCards,
  onCardSelect,
  onCardReorder,
  blanksNeeded,
  hasSubmitted,
  gameState,
  gameData,
  onCardsSubmitted,
}) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [draggedIndex, setDraggedIndex] = useState(null);
  const [submittedCardIds, setSubmittedCardIds] = useState([]);

  const canSubmit = selectedCards.length === blanksNeeded && ! hasSubmitted;

  // Load submitted cards from localStorage on mount and when submission status changes
  useEffect(() => {
    const storageKey = `submitted_cards_${gameData.gameId}_${gameState.current_round}`;
    const stored = localStorage.getItem(storageKey);
    
    if (stored) {
      try {
        const parsedCards = JSON.parse(stored);
        setSubmittedCardIds(parsedCards);
      } catch (e) {
        console.error('Failed to parse submitted cards:', e);
      }
    } else {
      setSubmittedCardIds([]);
    }
  }, [gameData.gameId, gameState.current_round, hasSubmitted]);

  const handleSubmit = async () => {
    if ( ! canSubmit) return;

    setSubmitting(true);
    setError('');

    try {
      const response = await submitCards(gameData.gameId, selectedCards);
      if (response.success) {
        // Store submitted cards with their full data (not just IDs) in localStorage
        const submittedCardsData = selectedCards.map(cardId => {
          return whiteCards.find(c => c.card_id === cardId);
        }).filter(Boolean);
        
        const storageKey = `submitted_cards_${gameData.gameId}_${gameState.current_round}`;
        localStorage.setItem(storageKey, JSON.stringify(submittedCardsData));
        setSubmittedCardIds(submittedCardsData);
        onCardsSubmitted();
      } else {
        setError(response.message || 'Failed to submit cards');
      }
    } catch (err) {
      setError(err.message || 'Failed to submit cards');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDragStart = (e, index) => {
    setDraggedIndex(index);
    e.dataTransfer.effectAllowed = 'move';
  };

  const handleDragOver = (e, index) => {
    e.preventDefault();
    if (draggedIndex === null || draggedIndex === index) return;

    onCardReorder(draggedIndex, index);
    setDraggedIndex(index);
  };

  const handleDragEnd = () => {
    setDraggedIndex(null);
  };

  const handleRemoveCard = (cardId) => {
    if (hasSubmitted) return;
    onCardSelect(cardId);
  };

  if (hasSubmitted) {
    return (
      <div className="card-selector">
        <div className="submission-status">
          <div className="status-icon">✓</div>
          <p>Cards submitted! Waiting for other players...</p>
        </div>
        
        {submittedCardIds.length > 0 && (
          <div className="submitted-cards-display">
            <h4>Your Submission:</h4>
            <div className="submitted-cards-list">
              {submittedCardIds.map((card, index) => {
                if ( ! card) return null;

                return (
                  <div key={card.card_id} className="submitted-card-preview">
                    <div className="card-order-badge">{index + 1}</div>
                    <div className="card card-white card-preview">
                      <div className="card-content-small">
                        {card.value}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="card-selector">
      <div className="selected-cards-container-compact">
        {selectedCards.length === 0 ? (
          <p className="selection-prompt">Tap cards below to select them</p>
        ) : (
          <>
            <p className="selection-counter-small">
              {selectedCards.length} / {blanksNeeded}
            </p>

            <div className="selected-cards-list-compact">
              {selectedCards.map((cardId, index) => {
                const card = whiteCards.find((c) => c.card_id === cardId);
                if ( ! card) return null;

                return (
                  <div
                    key={cardId}
                    className="selected-card-mini"
                    draggable
                    onDragStart={(e) => handleDragStart(e, index)}
                    onDragOver={(e) => handleDragOver(e, index)}
                    onDragEnd={handleDragEnd}
                  >
                    <div className="card-order-number-mini">{index + 1}</div>
                    <div className="selected-card-text-mini">
                      {card.value}
                    </div>
                    <button
                      className="remove-card-btn-mini"
                      onClick={() => handleRemoveCard(cardId)}
                      aria-label="Remove card"
                    >
                      ×
                    </button>
                  </div>
                );
              })}
            </div>

            {blanksNeeded > 1 && selectedCards.length > 1 && (
              <p className="drag-hint-small">Drag to reorder</p>
            )}
          </>
        )}
      </div>

      {error && <div className="error-message">{error}</div>}

      <button
        className="btn btn-primary submit-cards-btn"
        onClick={handleSubmit}
        disabled={ ! canSubmit || submitting}
      >
        {submitting ? 'Submitting...' : 'Submit Cards'}
      </button>
    </div>
  );
}

export default CardSelector;
