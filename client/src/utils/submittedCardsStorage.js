function getStorageKey(gameId, round) {
  return `submitted_cards_${gameId}_${round}`;
}

export function getSubmittedCards(gameId, round) {
  const storageKey = getStorageKey(gameId, round);
  const stored = localStorage.getItem(storageKey);

  if (!stored) {
    return { cardIds: [], cards: [] };
  }

  try {
    const parsed = JSON.parse(stored);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return { cardIds: [], cards: [] };
    }

    const cardIds = Array.isArray(parsed.cardIds) ? parsed.cardIds : [];
    const cards = Array.isArray(parsed.cards) ? parsed.cards : [];
    return { cardIds, cards };
  } catch (err) {
    console.error('Failed to parse submitted cards:', err);
    return { cardIds: [], cards: [] };
  }
}

export function setSubmittedCards(gameId, round, cards) {
  const safeCards = Array.isArray(cards) ? cards.filter(Boolean) : [];
  const cardIds = safeCards
    .map((card) => card?.card_id)
    .filter((id) => id !== undefined && id !== null);

  const payload = { cardIds, cards: safeCards };
  localStorage.setItem(getStorageKey(gameId, round), JSON.stringify(payload));
}

export function hasSubmittedCards(gameId, round) {
  const { cardIds } = getSubmittedCards(gameId, round);
  return cardIds.length > 0;
}
