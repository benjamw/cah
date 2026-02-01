import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTrophy, faTag, faBoxArchive } from '@fortawesome/free-solid-svg-icons';
import { pickWinner, setNextCzar, forceEarlyReview } from '../utils/api';
import CardSwiper from './CardSwiper';

function CzarView({ gameState, gameData, promptCard, responseCards, showToast, onOpenTagEditor }) {
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
  // Kept for potential future use in czar-specific UI features
  // const czarName = gameState.current_czar_name || 'Unknown';
  
  // Calculate how many players should submit (exclude czar and paused players)
  const activePlayers = gameState.players?.filter(p => 
    p.id !== gameState.current_czar_id && ! p.is_paused
  ) || [];
  const expectedSubmissions = activePlayers.length;
  const allSubmitted = submissions.length >= expectedSubmissions && submissions.length > 0;
  const forcedReview = gameState.forced_early_review === true;
  const canForceReview = submissions.length > 0 && ! allSubmitted && ! forcedReview;
  const canSkipRound = submissions.length === 0 && expectedSubmissions > 0;

  const handleForceReview = async () => {
    if ( ! window.confirm(`Only ${submissions.length} out of ${expectedSubmissions} players have submitted. Review anyway?`)) {
      return;
    }
    
    try {
      const response = await forceEarlyReview(gameData.gameId);
      if ( ! response.success) {
        if (showToast) {
          showToast(response.message || 'Failed to force early review');
        }
      }
      // Don't set local state - wait for next poll to get updated game state
    } catch (err) {
      console.error('Error forcing early review:', err);
      if (showToast) {
        showToast('Error forcing early review');
      }
    }
  };

  const handleSkipRound = async () => {
    if ( ! window.confirm('No submissions received. Skip to next round without a winner?')) {
      return;
    }
    
    setSettingCzar(true);
    setError('');
    
    try {
      const response = await setNextCzar(gameData.gameId);
      if ( ! response.success) {
        setError(response.message || 'Failed to advance round');
      }
    } catch (err) {
      setError(err.message || 'Failed to advance round');
    } finally {
      setSettingCzar(false);
    }
  };

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

    if (isLeftSwipe && currentSubmissionIndex < submissions.length - 1) {
      setCurrentSubmissionIndex((prev) => prev + 1);
    }
    if (isRightSwipe && currentSubmissionIndex > 0) {
      setCurrentSubmissionIndex((prev) => prev - 1);
    }
  };

  const handlePickWinner = async () => {
    // Allow picking winner if all submitted OR forced early review
    if ( ! allSubmitted && ! forcedReview) return;

    const currentSubmission = submissions[currentSubmissionIndex];
    
    setSelecting(true);
    setError('');

    try {
      const response = await pickWinner(
        gameData.gameId,
        currentSubmission.player_id
      );
      if ( ! response.success) {
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
      if ( ! response.success) {
        setError(response.message || 'Failed to set next czar');
      } else {
        setShowCzarSelection(false);
        
        // Check if order was locked (without skipped players - those are handled separately)
        if (response.data.order_locked && ! response.data.skipped_players) {
          showToast('Player order locked! The czar will now rotate automatically.');
        }
      }
    } catch (err) {
      setError(err.message || 'Failed to set next czar');
    } finally {
      setSettingCzar(false);
    }
  };

  const renderCardWithBlanks = (promptCardText, responseCardTexts) => {
    const blanks = (promptCardText.match(/_+/g) || []).length;

    if (blanks === 0) {
      // No blanks, show prompt card and response cards below
      return (
        <div className="card-combination">
          <div
            className="card-text prompt-text"
            dangerouslySetInnerHTML={{ __html: formatCardText(promptCardText) }}
          />
          <div className="response-cards-below">
            {responseCardTexts.map((text, index) => (
              <div
                key={index}
                className="card-text response-text-inline"
                dangerouslySetInnerHTML={{ __html: formatCardText(text) }}
              />
            ))}
          </div>
        </div>
      );
    }

    // Replace blanks with response card text
    let result = promptCardText;
    let responseIndex = 0;

    result = result.replace(/_+/g, () => {
      if (responseIndex < responseCardTexts.length) {
        // Trim trailing periods from response cards since prompt cards usually have punctuation
        const responseText = responseCardTexts[responseIndex].replace(/\.+$/, '');
        const replacement = `<span class="response-text-inline">${formatCardText(
          responseText
        )}</span>`;
        responseIndex++;
        return replacement;
      }
      return '_____';
    });

    return (
      <div
        className="card-text prompt-text"
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

      { ! allSubmitted && (
        <div className="card-section">
          {promptCard ? (
            <div className="card card-prompt">
              <div className="card-content" dangerouslySetInnerHTML={{ __html: formatCardText(promptCard.copy) }} />
              {promptCard.choices > 1 && (
                <div className="card-pick">Pick {promptCard.choices}</div>
              )}
              
              {onOpenTagEditor && (
                <button
                  className="card-tag-btn"
                  onClick={() => onOpenTagEditor(promptCard)}
                  title="Edit tags for this card"
                  aria-label="Edit tags"
                >
                  <FontAwesomeIcon icon={faTag} />
                </button>
              )}
              
              {promptCard.packs && promptCard.packs.length > 0 && (
                <button
                  className="card-packs-btn-prompt"
                  onClick={(e) => {
                    e.stopPropagation();
                    const btn = e.currentTarget;
                    const display = btn.nextElementSibling;
                    if (display) {
                      display.classList.toggle('hidden');
                    }
                  }}
                  title="View packs this card belongs to"
                  aria-label="View packs"
                >
                  <FontAwesomeIcon icon={faBoxArchive} />
                </button>
              )}
              
              {promptCard.packs && promptCard.packs.length > 0 && (
                <div className="card-packs-display-prompt hidden">
                  <div className="card-packs-header">
                    <strong>Card packs containing this card:</strong>
                  </div>
                  <div className="card-packs-list">
                    {promptCard.packs.map((pack, index) => (
                      <div key={index} className="card-pack-item">
                        {pack.name} {pack.version && `(${pack.version})`}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          ) : (
            <div className="card card-empty">No prompt card available</div>
          )}
        </div>
      )}

      { ! allSubmitted && ! forcedReview ? (
        <>
          <div className="waiting-for-submissions">
            <p>Waiting for players to submit their cards...</p>
            <div className="submission-progress">
              {submissions.length} / {expectedSubmissions} submitted
            </div>
            {canForceReview && (
              <button 
                className="btn-skip-czar btn-review-anyway"
                onClick={handleForceReview}
              >
                Review Anyway
              </button>
            )}
            {canSkipRound && (
              <button 
                className="btn-skip-czar btn-review-anyway"
                onClick={handleSkipRound}
                disabled={settingCzar}
              >
                {settingCzar ? 'Skipping...' : 'Skip Round'}
              </button>
            )}
          </div>

          <div className="card-section">
            <CardSwiper
              cards={responseCards}
              selectedCards={[]}
              onCardSelect={null}
              cardType="response"
              disabled={true}
              onOpenTagEditor={onOpenTagEditor}
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

            {submissions.length > 0 && submissions[currentSubmissionIndex] ? (
              <>
                <div className="card card-prompt card-submission">
                  {renderCardWithBlanks(
                    promptCard.copy,
                    submissions[currentSubmissionIndex].cards.map((c) => c.copy)
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
                  <FontAwesomeIcon icon={faTrophy} /> {selecting ? 'Selecting...' : 'This is the Winner!'}
                </button>
              </>
            ) : (
              <div className="card card-empty">
                No submissions available to review
              </div>
            )}
          </div>

          <div className="card-section">
            <CardSwiper
              cards={responseCards}
              selectedCards={[]}
              onCardSelect={null}
              cardType="response"
              disabled={true}
              onOpenTagEditor={onOpenTagEditor}
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

function CzarSelectionModal({ players, currentCzarId, onSelectCzar, onCancel, selecting }) {
  // Filter out current czar and Rando
  const eligiblePlayers = players.filter(p => p.id !== currentCzarId && ! p.is_rando);

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
