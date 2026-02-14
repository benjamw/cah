import { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCircleXmark, faCircleCheck } from '@fortawesome/free-solid-svg-icons';
import { submitCards } from '../utils/api';
import CardText from './CardText';
import CardView from './CardView';
import { getSubmittedCards, setSubmittedCards } from '../utils/submittedCardsStorage';

function CardSelector({
  selectedCards,
  responseCards,
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
  const [submittedCards, setSubmittedCardsState] = useState([]);

  const canSubmit = selectedCards.length === blanksNeeded && !hasSubmitted;

  // Load submitted cards from localStorage on mount and when submission status changes
  useEffect(() => {
    const { cards } = getSubmittedCards(gameData.gameId, gameState.current_round);
    setSubmittedCardsState(cards);
  }, [gameData.gameId, gameState.current_round, hasSubmitted]);

  const handleSubmit = async () => {
    if (!canSubmit) return;

    setSubmitting(true);
    setError('');

    try {
      const response = await submitCards(gameData.gameId, selectedCards);
      if (response.success) {
        // Store submitted cards with their full data (not just IDs) in localStorage
        const submittedCardsData = selectedCards
          .map((cardId) => {
            return responseCards.find((c) => c.card_id === cardId);
          })
          .filter(Boolean);

        setSubmittedCards(gameData.gameId, gameState.current_round, submittedCardsData);
        setSubmittedCardsState(submittedCardsData);
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
    // Check if all submissions are in (exclude czar and paused players)
    const activePlayers =
      gameState.players?.filter((p) => p.id !== gameState.current_czar_id && !p.is_paused) || [];
    const submissionCount = gameState.submissions?.length || 0;
    const expectedSubmissions = activePlayers.length;
    const allSubmitted = submissionCount >= expectedSubmissions;

    // Get czar name for display
    const czarName = gameState.current_czar_name || 'the czar';

    return (
      <div className="card-selector">
        <div className="submission-status">
          <div className="status-icon">
            <FontAwesomeIcon icon={faCircleCheck} />
          </div>
          <p>
            {allSubmitted
              ? `All cards submitted! Waiting for ${czarName} to pick a winner...`
              : 'Cards submitted! Waiting for other players...'}
          </p>
        </div>

        {submittedCards.length > 0 && (
          <div className="submitted-cards-display">
            <h4>Your Submission:</h4>
            <div className="submitted-cards-list">
              {submittedCards.map((card, index) => {
                if (!card) return null;

                return (
                  <div key={card.card_id} className="submitted-card-preview">
                    <div className="card-order-badge">{index + 1}</div>
                    <CardView
                      copy={card.copy}
                      variant="response"
                      className="card-preview"
                      contentClassName="card-content-small"
                    />
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
                const card = responseCards.find((c) => c.card_id === cardId);
                if (!card) return null;

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
                    <CardText text={card.copy} className="selected-card-text-mini" />
                    <button
                      className="btn-remove-icon"
                      onClick={() => handleRemoveCard(cardId)}
                      aria-label="Remove card"
                    >
                      <FontAwesomeIcon icon={faCircleXmark} />
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
        disabled={!canSubmit || submitting}
      >
        {submitting ? 'Submitting...' : 'Submit Cards'}
      </button>
    </div>
  );
}

export default CardSelector;
