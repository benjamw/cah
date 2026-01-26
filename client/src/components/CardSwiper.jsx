import { useState, useRef } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowsRotate } from '@fortawesome/free-solid-svg-icons';

function CardSwiper({ cards, selectedCards, onCardSelect, cardType, disabled, onRefreshHand, refreshing }) {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [touchStart, setTouchStart] = useState(null);
  const [touchEnd, setTouchEnd] = useState(null);
  const containerRef = useRef(null);

  const minSwipeDistance = 50;

  const onTouchStart = (e) => {
    setTouchEnd(null);
    setTouchStart(e.targetTouches[0].clientX);
  };

  const onTouchMove = (e) => {
    setTouchEnd(e.targetTouches[0].clientX);
  };

  const onTouchEnd = () => {
    if ( ! touchStart || ! touchEnd) return;

    const distance = touchStart - touchEnd;
    const isLeftSwipe = distance > minSwipeDistance;
    const isRightSwipe = distance < -minSwipeDistance;

    if (isLeftSwipe) {
      setCurrentIndex((prev) => (prev + 1) % cards.length);
    }
    if (isRightSwipe) {
      setCurrentIndex((prev) => (prev - 1 + cards.length) % cards.length);
    }
  };

  const goToCard = (index) => {
    setCurrentIndex(index);
  };

  const handleCardClick = () => {
    if (disabled) return;
    const currentCard = cards[currentIndex];
    if (currentCard && onCardSelect) {
      onCardSelect(currentCard.card_id);
    }
  };

  // Reset to first card when cards array changes
  const cardsLength = cards.length;
  if (currentIndex >= cardsLength && cardsLength > 0) {
    setCurrentIndex(0);
  }

  if ( ! cards || cards.length === 0) {
    return (
      <div className="card-swiper">
        <div className="card card-empty">No cards available</div>
      </div>
    );
  }

  const currentCard = cards[currentIndex];
  const isSelected = selectedCards?.includes(currentCard.card_id);
  const cardClass = cardType === 'white' ? 'card-white' : 'card-black';

  return (
    <div
      className="card-swiper"
      ref={containerRef}
      onTouchStart={onTouchStart}
      onTouchMove={onTouchMove}
      onTouchEnd={onTouchEnd}
    >
      <div
        className={`card ${cardClass} ${isSelected ? 'card-selected' : ''} ${
          disabled ? 'card-disabled' : ''
        }`}
        onClick={handleCardClick}
      >
        <div
          className="card-content"
          dangerouslySetInnerHTML={{ __html: formatCardText(currentCard.value) }}
        />
        {currentCard.choices > 1 && (
          <div className="card-pick">Pick {currentCard.choices}</div>
        )}
      </div>

      <div className="card-controls-row">
        {cards.length > 1 && (
          <div className="card-dots">
            {cards.map((_, index) => (
              <button
                key={index}
                className={`dot ${index === currentIndex ? 'active' : ''}`}
                onClick={() => goToCard(index)}
                aria-label={`Go to card ${index + 1}`}
              />
            ))}
          </div>
        )}

        {onRefreshHand && cardType === 'white' && (
          <button
            className="btn-refresh-hand"
            onClick={onRefreshHand}
            disabled={refreshing}
            title="Refresh your hand (discard all cards and draw new ones)"
            aria-label="Refresh hand"
          >
            {refreshing ? '...' : <FontAwesomeIcon icon={faArrowsRotate} />}
          </button>
        )}
      </div>

      <div className="card-counter">
        {currentIndex + 1} / {cards.length}
      </div>
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

export default CardSwiper;
