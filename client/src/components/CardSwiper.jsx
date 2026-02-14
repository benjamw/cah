import { useState, useRef, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowsRotate, faTags, faBoxArchive } from '@fortawesome/free-solid-svg-icons';
import CardView from './CardView';

function CardSwiper({
  cards,
  selectedCards,
  onCardSelect,
  cardType,
  disabled,
  onRefreshHand,
  refreshing,
}) {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [touchStart, setTouchStart] = useState(null);
  const [touchEnd, setTouchEnd] = useState(null);
  const [showPacks, setShowPacks] = useState(false);
  const [showTags, setShowTags] = useState(false);
  const containerRef = useRef(null);
  const packsDisplayRef = useRef(null);
  const tagsDisplayRef = useRef(null);

  const minSwipeDistance = 50;
  const cardsLength = cards.length;
  const activeIndex = cardsLength > 0 ? Math.min(currentIndex, cardsLength - 1) : 0;

  // Close metadata displays when clicking outside
  useEffect(() => {
    if (!showPacks && !showTags) return;

    const handleClickOutside = (event) => {
      const clickedPacksButton = event.target.closest('.card-packs-btn');
      const clickedTagsButton = event.target.closest('.card-tags-btn');
      const clickedInsidePacks = packsDisplayRef.current?.contains(event.target);
      const clickedInsideTags = tagsDisplayRef.current?.contains(event.target);

      if (!clickedPacksButton && !clickedTagsButton && !clickedInsidePacks && !clickedInsideTags) {
        setShowPacks(false);
        setShowTags(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [showPacks, showTags]);

  const onTouchStart = (e) => {
    setTouchEnd(null);
    setTouchStart(e.targetTouches[0].clientX);
  };

  const onTouchMove = (e) => {
    setTouchEnd(e.targetTouches[0].clientX);
  };

  const onTouchEnd = () => {
    if (!touchStart || !touchEnd) return;

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
    const currentCard = cards[activeIndex];
    if (currentCard && onCardSelect) {
      onCardSelect(currentCard.card_id);
    }
  };

  if (!cards || cards.length === 0) {
    return (
      <div className="card-swiper">
        <div className="card card-empty">No cards available</div>
      </div>
    );
  }

  const currentCard = cards[activeIndex];
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
        {currentCard.packs && currentCard.packs.length > 0 && (
          <button
            className="card-packs-btn"
            onClick={(e) => {
              e.stopPropagation();
              setShowPacks(!showPacks);
              setShowTags(false);
            }}
            title="View packs this card belongs to"
            aria-label="View packs"
          >
            <FontAwesomeIcon icon={faBoxArchive} />
          </button>
        )}

        {currentCard.tags && currentCard.tags.length > 0 && (
          <button
            className="card-tags-btn"
            onClick={(e) => {
              e.stopPropagation();
              setShowTags(!showTags);
              setShowPacks(false);
            }}
            title="View tags on this card"
            aria-label="View tags"
          >
            <FontAwesomeIcon icon={faTags} />
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

      {showTags && currentCard.tags && (
        <div className="card-tags-display" ref={tagsDisplayRef}>
          <div className="card-packs-header">
            <strong>Card tags on this card:</strong>
          </div>
          <div className="card-packs-list">
            {currentCard.tags.map((tag, index) => (
              <div key={index} className="card-tag-item">
                {tag.name}
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
                className={`dot ${index === activeIndex ? 'active' : ''}`}
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
        {activeIndex + 1} / {cards.length}
      </div>
    </div>
  );
}

export default CardSwiper;
