import { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPause, faPlay, faRightFromBracket, faBoxArchive } from '@fortawesome/free-solid-svg-icons';
import CardSwiper from './CardSwiper';
import CardSelector from './CardSelector';
import CzarView from './CzarView';
import { removePlayer, placeSkippedPlayer, voteSkipCzar, togglePlayerPause, refreshHand } from '../utils/api';
import CardView from './CardView';
import Scoreboard from './Scoreboard';
import PlayerManagement from './PlayerManagement';
import SkippedPlayerModal from './SkippedPlayerModal';
import SkipCzarButton from './SkipCzarButton';
import HostTransferModal from './HostTransferModal';
import { handleLeaveWithOptionalTransfer, handleTransferAndLeave } from '../utils/hostLeaveFlow';

function PlayingGame({ gameState, gameData, onLeaveGame, showToast }) {
  const [selectedCards, setSelectedCards] = useState([]);
  const [submittedCardIds, setSubmittedCardIds] = useState([]);
  const [removing, setRemoving] = useState(null);
  const [showHostTransfer, setShowHostTransfer] = useState(false);
  const [transferring, setTransferring] = useState(false);
  const [showSkippedPlayerModal, setShowSkippedPlayerModal] = useState(false);
  const [placingPlayer, setPlacingPlayer] = useState(false);
  const [votingToSkip, setVotingToSkip] = useState(false);
  const [pausing, setPausing] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  
  const currentPlayer = gameState.players?.find(
    (p) => p.id === gameData.playerId
  );
  
  // Check if current player is czar using current_czar_id
  const isCzar = gameState.current_czar_id === gameData.playerId;
  const promptCard = gameState.current_prompt_card;
  
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

  // Get all response cards (player's hand)
  const allResponseCards = currentPlayer?.hand || [];

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
  const responseCards = hasSubmitted 
    ? allResponseCards.filter(card => ! submittedCardIds.includes(card.card_id))
    : allResponseCards;
    
  const blanksNeeded = promptCard?.choices || 1;

  // Calculate if cards were added to hand
  const cardsInHand = responseCards.length;
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

  const handlePlaceSkippedPlayer = async (skippedPlayerId, beforePlayerId) => {
    setPlacingPlayer(true);
    try {
      const response = await placeSkippedPlayer(gameData.gameId, skippedPlayerId, beforePlayerId);
      if (response.success) {
        // Close modal if no more skipped players
        if ( ! response.data.game_state?.skipped_players || response.data.game_state.skipped_players.ids.length === 0) {
          setShowSkippedPlayerModal(false);
          if (showToast) {
            showToast('All players have been placed in the rotation!');
          }
        }
      } else {
        console.error('Failed to place skipped player:', response);
        if (showToast) {
          showToast('Failed to place player. Please try again.');
        }
      }
    } catch (err) {
      console.error('Error placing skipped player:', err);
      if (showToast) {
        showToast('Error placing player. Please try again.');
      }
    } finally {
      setPlacingPlayer(false);
    }
  };

  const handleVoteSkipCzar = async () => {
    if ( ! window.confirm('Vote to skip the current czar?\n\nTwo players must vote to skip. The round will reset and move to the next czar.')) {
      return;
    }
    
    setVotingToSkip(true);
    try {
      const response = await voteSkipCzar(gameData.gameId);
      if ( ! response.success) {
        console.error('Failed to vote skip czar:', response);
        if (showToast) {
          showToast(response.message || 'Failed to vote');
        }
      }
      // State will update via polling
    } catch (err) {
      console.error('Error voting to skip czar:', err);
      if (showToast) {
        showToast('Error voting to skip czar');
      }
    } finally {
      setVotingToSkip(false);
    }
  };

  const handleTogglePause = async (targetPlayerId) => {
    // Only show confirmation if it's the host pausing another player (not themselves)
    if (targetPlayerId !== gameData.playerId) {
      const player = gameState.players?.find(p => p.id === targetPlayerId);
      const isPaused = player?.is_paused;
      const action = isPaused ? 'unpause' : 'pause';
      
      if ( ! window.confirm(`${action === 'pause' ? 'Pause' : 'Unpause'} ${player?.name}?`)) {
        return;
      }
    }
    
    setPausing(targetPlayerId);
    try {
      const response = await togglePlayerPause(gameData.gameId, targetPlayerId);
      if ( ! response.success) {
        console.error('Failed to toggle pause:', response);
        if (showToast) {
          showToast(response.message || 'Failed to pause/unpause player');
        }
      }
      // State will update via polling
    } catch (err) {
      console.error('Error toggling pause:', err);
      if (showToast) {
        showToast('Error pausing/unpausing player');
      }
    } finally {
      setPausing(null);
    }
  };

  const handlePauseMyself = async () => {
    const isPaused = currentPlayer?.is_paused;
    
    if ( ! isPaused) {
      if ( ! window.confirm('Pause your game?\n\nYou will be skipped as czar and your submissions will not be required. You can return and unpause at any time.')) {
        return;
      }
    } else {
      if ( ! window.confirm('Unpause and rejoin the game?')) {
        return;
      }
    }
    
    await handleTogglePause(gameData.playerId);
  };

  const handleRefreshHand = async () => {
    if ( ! window.confirm('You wish to refresh your hand? This will give you all new cards.')) {
      return;
    }
    
    setRefreshing(true);
    try {
      const response = await refreshHand(gameData.gameId);
      if ( ! response.success) {
        console.error('Failed to refresh hand:', response);
        if (showToast) {
          showToast(response.message || 'Failed to refresh hand');
        }
      } else {
        // Clear selected cards
        setSelectedCards([]);
      }
      // State will update via polling
    } catch (err) {
      console.error('Error refreshing hand:', err);
      if (showToast) {
        showToast('Error refreshing hand');
      }
    } finally {
      setRefreshing(false);
    }
  };

  const onLeaveClick = async () => {
    await handleLeaveWithOptionalTransfer({
      requiresTransfer: !! currentPlayer?.is_creator,
      gameId: gameData.gameId,
      onLeaveGame,
      setShowHostTransfer,
    });
  };

  const onTransferAndLeave = async (newHostId) => {
    await handleTransferAndLeave({
      gameId: gameData.gameId,
      newHostId,
      onLeaveGame,
      setShowHostTransfer,
      setTransferring,
    });
  };

  // Show czar view if player is czar
  if (isCzar) {
    return (
      <>
        <div className={`game-content ${currentPlayer?.is_paused ? 'paused-overlay' : ''}`}>
          <CzarView
            gameState={gameState}
            gameData={gameData}
            promptCard={promptCard}
            responseCards={allResponseCards}
            showToast={showToast}
            onRefreshHand={handleRefreshHand}
            refreshing={refreshing}
          />
        <Scoreboard 
          players={gameState.players || []} 
          currentPlayerId={gameData.playerId}
        />
        {currentPlayer?.is_creator && (
          <PlayerManagement
            players={gameState.players || []}
            gameData={gameData}
            onRemovePlayer={handleRemovePlayer}
            onTogglePause={handleTogglePause}
            removing={removing}
            pausing={pausing}
          />
        )}
        </div>
        
        {currentPlayer?.is_paused && (
          <div className="paused-message">
            <h2><FontAwesomeIcon icon={faPause} /> Game Paused</h2>
            <p>You are currently paused.</p>
            <p>Unpause to continue playing. Scroll down for Unpause button</p>
          </div>
        )}
        
      <div className="leave-game-section">
        <button
          className={`btn ${currentPlayer?.is_paused ? 'btn-success' : 'btn-warning'}`}
          onClick={handlePauseMyself}
          disabled={pausing === gameData.playerId}
        >
          {currentPlayer?.is_paused ? <><FontAwesomeIcon icon={faPlay} /> Resume Game</> : <><FontAwesomeIcon icon={faPause} /> Pause Game</>}
        </button>
        <button 
          className="btn btn-danger"
          onClick={onLeaveClick}
        >
          <FontAwesomeIcon icon={faRightFromBracket} /> Leave Game
        </button>
      </div>
      {showHostTransfer && (
          <HostTransferModal
            players={gameState.players || []}
            currentPlayerId={gameData.playerId}
            onTransfer={onTransferAndLeave}
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
        
      </>
    );
  }

  // Show regular player view
  return (
    <div className="playing-game">
      <div className={`game-content ${currentPlayer?.is_paused ? 'paused-overlay' : ''}`}>
        {cardsAdded > 0 && (
          <div className="notification-banner">
            {cardsAdded} card{cardsAdded > 1 ? 's have' : ' has'} been added to your hand. Choose {blanksNeeded}.
          </div>
        )}

        <CardSelector
          selectedCards={selectedCards}
          responseCards={allResponseCards}
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
            cards={responseCards}
            selectedCards={selectedCards}
            onCardSelect={handleCardSelect}
            cardType="response"
            disabled={hasSubmitted}
            onRefreshHand={handleRefreshHand}
            refreshing={refreshing}
          />
        </div>

        <div className="card-section prompt-card-section">
          <div className="prompt-card-header">
            <h3>Card Czar: {czarName}</h3>
            { ! isCzar && (
              <SkipCzarButton 
                voteCount={gameState.skip_czar_votes?.length || 0}
                hasVoted={gameState.skip_czar_votes?.includes(gameData.playerId) || false}
                onVote={handleVoteSkipCzar}
                voting={votingToSkip}
              />
            )}
          </div>
          {promptCard ? (
            <CardView copy={promptCard.copy} variant="prompt" choices={blanksNeeded}>
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
            </CardView>
          ) : (
            <div className="card card-empty">No black card available</div>
          )}
        </div>

        <Scoreboard 
          players={gameState.players || []} 
          currentPlayerId={gameData.playerId}
        />

        {currentPlayer?.is_creator && (
          <PlayerManagement
            players={gameState.players || []}
            gameData={gameData}
            onRemovePlayer={handleRemovePlayer}
            onTogglePause={handleTogglePause}
            removing={removing}
            pausing={pausing}
          />
        )}
      </div>
      
      {currentPlayer?.is_paused && (
        <div className="paused-message">
          <h2>⏸️ Game Paused</h2>
          <p>You are currently paused. Unpause to continue playing.</p>
        </div>
      )}

      <div className="leave-game-section">
        <button 
          className={`btn ${currentPlayer?.is_paused ? 'btn-success' : 'btn-warning'}`}
          onClick={handlePauseMyself}
          disabled={pausing === gameData.playerId}
        >
          {currentPlayer?.is_paused ? <><FontAwesomeIcon icon={faPlay} /> Resume Game</> : <><FontAwesomeIcon icon={faPause} /> Pause Game</>}
        </button>
        <button 
          className="btn btn-danger"
          onClick={onLeaveClick}
        >
          <FontAwesomeIcon icon={faRightFromBracket} /> Leave Game
        </button>
      </div>

      {showHostTransfer && (
        <HostTransferModal
          players={gameState.players || []}
          currentPlayerId={gameData.playerId}
          onTransfer={onTransferAndLeave}
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

export default PlayingGame;
