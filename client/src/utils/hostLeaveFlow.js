import { leaveGame, transferHost } from './api';
import { reportActionFailure } from './errorFeedback';

export async function handleLeaveWithOptionalTransfer({
  requiresTransfer,
  gameId,
  onLeaveGame,
  setShowHostTransfer,
  showToast,
}) {
  if (requiresTransfer) {
    if (window.confirm('Are you sure you want to leave the game? You will need to transfer host to another player.')) {
      setShowHostTransfer(true);
    }
    return;
  }

  if (window.confirm('Are you sure you want to leave the game?')) {
    try {
      await leaveGame(gameId);
    } catch (err) {
      reportActionFailure({
        error: err,
        fallbackMessage: 'Could not notify server before leaving',
        showToast,
        logPrefix: 'Error leaving game',
      });
    } finally {
      // Always clear local storage, even if API call failed
      onLeaveGame();
    }
  }
}

export async function handleTransferAndLeave({
  gameId,
  newHostId,
  onLeaveGame,
  setShowHostTransfer,
  setTransferring,
  showToast,
}) {
  setTransferring(true);
  try {
    await transferHost(gameId, newHostId, true);
  } catch (err) {
    reportActionFailure({
      error: err,
      fallbackMessage: 'Could not transfer host before leaving',
      showToast,
      logPrefix: 'Error transferring host',
    });
  } finally {
    setTransferring(false);
    setShowHostTransfer(false);
    // Always clear local storage, even if API call failed
    onLeaveGame();
  }
}
