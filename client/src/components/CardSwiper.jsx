import { useState, useRef, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowsRotate, faTag, faBoxArchive } from '@fortawesome/free-solid-svg-icons';
import CardView from './CardView';

function CardSwiper({ cards, selectedCards, onCardSelect, cardType, disabled, onRefreshHand, refreshing, onOpenTagEditor }) {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [touchStart, setTouchStart] = useState(null);
  const [touchEnd, setTouchEnd] = useState(null);
  const [showPacks, setShowPacks] = useState(false);
  const containerRef = useRef(null);
  const packsDisplayRef = useRef(null);

  const minSwipeDistance = 50;

  // Close packs display when clicking outside
  useEffect(() => {
    if (!showPacks) return;

    const handleClickOutside = (event) => {
      if (packsDisplayRef.current && !packsDisplayRef.current.contains(event.target)) {
        // Check if the click was on the packs button
        const packsButton = event.target.closest('.card-packs-btn');
        if (!packsButton) {
          setShowPacks(false);
        }
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [showPacks]);

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
  const cardVariant = cardType === 'response' ? 'response' : 'prompt';

  return (
    <div
      className="card-swiper"
      ref={containerRef}
      onTouchStart={onTouchStart}
      onTouchMove={onTouchMove}
      onTouchEnd={onTouchEnd}
    >
      <CardView
        copy={currentCard.copy}
        variant={cardVariant}
        selected={isSelected}
        disabled={disabled}
        choices={currentCard.choices}
        onClick={handleCardClick}
      >
        {onOpenTagEditor && (
          <button
            className="card-tag-btn"
            onClick={(e) => {
              e.stopPropagation();
              onOpenTagEditor(currentCard);
            }}
            title="Edit tags for this card"
            aria-label="Edit tags"
          >
            <FontAwesomeIcon icon={faTag} />
          </button>
        )}
        
        {currentCard.packs && currentCard.packs.length > 0 && (
          <button
            className="card-packs-btn"
            onClick={(e) => {
              e.stopPropagation();
              setShowPacks(!showPacks);
            }}
            title="View packs this card belongs to"
            aria-label="View packs"
          >
            <FontAwesomeIcon icon={faBoxArchive} />
          </button>
        )}
      </CardView>

      {showPacks && currentCard.packs && (
        <div className="card-packs-display" ref={packsDisplayRef}>
          <div className="card-packs-header">
            <strong>Card packs containing this card:</strong>
          </div>
          <div className="card-packs-list">
            {currentCard.packs.map((pack, index) => (
              <div key={index} className="card-pack-item">
                {pack.name} {pack.version && `(${pack.version})`}
              </div>
            ))}
          </div>
        </div>
      )}

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

        {onRefreshHand && cardType === 'response' && (
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

export default CardSwiper;
