import { useState } from 'react';
import { pickWinner, setNextCzar } from '../utils/api';
import CardSwiper from './CardSwiper';

function CzarView({ gameState, gameData, blackCard, whiteCards, showToast }) {
  const [currentSubmissionIndex, setCurrentSubmissionIndex] = useState(0);
  const [selecting, setSelecting] = useState(false);
  const [error, setError] = useState('');
  const [touchStart, setTouchStart] = useState(null);
  const [touchEnd, setTouchEnd] = useState(null);
  const [showCzarSelection, setShowCzarSelection] = useState(false);
  const [settingCzar, setSettingCzar] = useState(false);

  const submissions = gameState.submissions || [];
  const minSwipeDistance = 50;
  
  // Get czar name from the top level (already provided by API)
  const czarName = gameState.current_czar_name || 'Unknown';
  
  // Calculate how many players should submit (all players except czar)
  const totalPlayers = gameState.players?.length || 0;
  const expectedSubmissions = totalPlayers - 1; // Everyone except the czar
  const allSubmitted = submissions.length >= expectedSubmissions && submissions.length > 0;

  console.log('CzarView - gameState:', gameState);
  console.log('CzarView - blackCard:', blackCard);
  console.log('CzarView - whiteCards:', whiteCards);
  console.log('CzarView - submissions:', submissions);
  console.log('CzarView - czarName:', czarName);
  console.log('CzarView - expected submissions:', expectedSubmissions, 'received:', submissions.length);

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

    if (isLeftSwipe && currentSubmissionIndex < submissions.length - 1) {
      setCurrentSubmissionIndex((prev) => prev + 1);
    }
    if (isRightSwipe && currentSubmissionIndex > 0) {
      setCurrentSubmissionIndex((prev) => prev - 1);
    }
  };

  const handlePickWinner = async () => {
    if (!allSubmitted) return;

    const currentSubmission = submissions[currentSubmissionIndex];
    
    setSelecting(true);
    setError('');

    try {
      const response = await pickWinner(
        gameData.gameId,
        currentSubmission.player_id
      );
      if (!response.success) {
        setError(response.message || 'Failed to advance round');
      } else {
        // Check if we need to select next czar
        if (response.data.needs_czar_selection) {
          setShowCzarSelection(true);
        }
        // Reset for next round
        setCurrentSubmissionIndex(0);
      }
    } catch (err) {
      setError(err.message || 'Failed to advance round');
    } finally {
      setSelecting(false);
    }
  };

  const handleSelectNextCzar = async (nextCzarId) => {
    setSettingCzar(true);
    setError('');

    try {
      const response = await setNextCzar(gameData.gameId, nextCzarId);
      if (!response.success) {
        setError(response.message || 'Failed to set next czar');
      } else {
        setShowCzarSelection(false);
        
        // Check if order was locked (without skipped players - those are handled separately)
        if (response.data.order_locked && !response.data.skipped_players) {
          showToast('Player order locked! The czar will now rotate automatically.');
        }
      }
    } catch (err) {
      setError(err.message || 'Failed to set next czar');
    } finally {
      setSettingCzar(false);
    }
  };

  const renderCardWithBlanks = (blackCardText, whiteCardTexts) => {
    const blanks = (blackCardText.match(/_+/g) || []).length;

    if (blanks === 0) {
      // No blanks, show black card and white cards below
      return (
        <div className="card-combination">
          <div
            className="card-text black-text"
            dangerouslySetInnerHTML={{ __html: formatCardText(blackCardText) }}
          />
          <div className="white-cards-below">
            {whiteCardTexts.map((text, index) => (
              <div
                key={index}
                className="card-text white-text-inline"
                dangerouslySetInnerHTML={{ __html: formatCardText(text) }}
              />
            ))}
          </div>
        </div>
      );
    }

    // Replace blanks with white card text
    let result = blackCardText;
    let whiteIndex = 0;

    result = result.replace(/_+/g, () => {
      if (whiteIndex < whiteCardTexts.length) {
        // Trim trailing periods from white cards since black cards usually have punctuation
        const whiteText = whiteCardTexts[whiteIndex].replace(/\.+$/, '');
        const replacement = `<span class="white-text-inline">${formatCardText(
          whiteText
        )}</span>`;
        whiteIndex++;
        return replacement;
      }
      return '_____';
    });

    return (
      <div
        className="card-text black-text"
        dangerouslySetInnerHTML={{ __html: result }}
      />
    );
  };

  return (
    <div className="czar-view">
      <div className="czar-badge">
        <span className="crown-icon">👑</span>
        <span>You are the Card Czar</span>
      </div>

      {!allSubmitted && (
        <div className="card-section">
          {blackCard ? (
            <div className="card card-black">
              <div className="card-content" dangerouslySetInnerHTML={{ __html: formatCardText(blackCard.value) }} />
              {blackCard.choices > 1 && (
                <div className="card-pick">Pick {blackCard.choices}</div>
              )}
            </div>
          ) : (
            <div className="card card-empty">No black card available</div>
          )}
        </div>
      )}

      {!allSubmitted ? (
        <>
          <div className="waiting-for-submissions">
            <p>Waiting for players to submit their cards...</p>
            <div className="submission-progress">
              {submissions.length} / {expectedSubmissions} submitted
            </div>
          </div>

          <div className="card-section">
            <CardSwiper
              cards={whiteCards}
              selectedCards={[]}
              onCardSelect={null}
              cardType="white"
              disabled={true}
            />
          </div>
        </>
      ) : (
        <>
          <div
            className="submissions-viewer"
            onTouchStart={onTouchStart}
            onTouchMove={onTouchMove}
            onTouchEnd={onTouchEnd}
          >
            <h3>Review Submissions</h3>

            <div className="card card-black card-submission">
              {renderCardWithBlanks(
                blackCard.value,
                submissions[currentSubmissionIndex].cards.map((c) => c.value)
              )}
            </div>

            {submissions.length > 1 && (
              <div className="card-dots">
                {submissions.map((_, index) => (
                  <button
                    key={index}
                    className={`dot ${
                      index === currentSubmissionIndex ? 'active' : ''
                    }`}
                    onClick={() => setCurrentSubmissionIndex(index)}
                    aria-label={`Go to submission ${index + 1}`}
                  />
                ))}
              </div>
            )}

            <div className="submission-counter">
              {currentSubmissionIndex + 1} / {submissions.length}
            </div>

            {error && <div className="error-message">{error}</div>}

            <button
              className="btn btn-primary pick-winner-btn"
              onClick={handlePickWinner}
              disabled={selecting}
            >
              Pick This as Winner
            </button>
          </div>

          <div className="card-section">
            <CardSwiper
              cards={whiteCards}
              selectedCards={[]}
              onCardSelect={null}
              cardType="white"
              disabled={true}
            />
          </div>
        </>
      )}

      {showCzarSelection && (
        <CzarSelectionModal
          players={gameState.players || []}
          currentCzarId={gameState.current_czar_id}
          onSelectCzar={handleSelectNextCzar}
          onCancel={() => setShowCzarSelection(false)}
          selecting={settingCzar}
        />
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

function CzarSelectionModal({ players, currentCzarId, onSelectCzar, onCancel, selecting }) {
  // Filter out current czar and Rando
  const eligiblePlayers = players.filter(p => p.id !== currentCzarId && !p.is_rando);

  return (
    <div className="modal-overlay" onClick={onCancel}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <h2>Select Next Card Czar</h2>
        <p>Choose the player to your <strong>left</strong> (clockwise around the table):</p>
        
        <div className="czar-selection-list">
          {eligiblePlayers.map((player) => (
            <button
              key={player.id}
              className="czar-selection-item btn btn-primary"
              onClick={() => onSelectCzar(player.id)}
              disabled={selecting}
            >
              {player.name}
              {player.is_creator && <span className="badge">Host</span>}
            </button>
          ))}
        </div>
        
        {eligiblePlayers.length === 0 && (
          <p className="no-players">No eligible players available</p>
        )}
      </div>
    </div>
  );
}

export default CzarView;
