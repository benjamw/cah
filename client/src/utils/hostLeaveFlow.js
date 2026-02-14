import { leaveGame, transferHost } from './api';

export async function handleLeaveWithOptionalTransfer({
  requiresTransfer,
  gameId,
  onLeaveGame,
  setShowHostTransfer,
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
      console.error('Error leaving game:', err);
      // Don't show error - we're leaving anyway
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
}) {
  setTransferring(true);
  try {
    await transferHost(gameId, newHostId, true);
  } catch (err) {
    console.error('Error transferring host:', err);
    // Don't show error - we're leaving anyway
  } finally {
    setTransferring(false);
    setShowHostTransfer(false);
    // Always clear local storage, even if API call failed
    onLeaveGame();
  }
}
