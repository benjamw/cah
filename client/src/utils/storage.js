const STORAGE_KEY = 'cah_game_data';
const SEEN_TOASTS_KEY_PREFIX = 'cah_seen_toasts_';

// Store game data in both localStorage and cookies
export function storeGameData(data) {
  // Store in localStorage
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  } catch (e) {
    console.error('Failed to store in localStorage:', e);
  }

  // Store in cookies as backup
  try {
    const cookieData = JSON.stringify(data);
    const expires = new Date();
    expires.setDate(expires.getDate() + 7); // 7 days expiry
    document.cookie = `${STORAGE_KEY}=${encodeURIComponent(
      cookieData
    )}; expires=${expires.toUTCString()}; path=/; SameSite=Strict`;
  } catch (e) {
    console.error('Failed to store in cookies:', e);
  }
}

// Get game data from localStorage or cookies
export function getStoredGameData() {
  // Try localStorage first
  try {
    const data = localStorage.getItem(STORAGE_KEY);
    if (data) {
      return JSON.parse(data);
    }
  } catch (e) {
    console.error('Failed to read from localStorage:', e);
  }

  // Fallback to cookies
  try {
    const cookies = document.cookie.split(';');
    for (let cookie of cookies) {
      const [name, value] = cookie.trim().split('=');
      if (name === STORAGE_KEY) {
        return JSON.parse(decodeURIComponent(value));
      }
    }
  } catch (e) {
    console.error('Failed to read from cookies:', e);
  }

  return null;
}

// Clear game data from both localStorage and cookies
export function clearGameData() {
  // Clear localStorage
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch (e) {
    console.error('Failed to clear localStorage:', e);
  }

  // Clear cookies
  try {
    document.cookie = `${STORAGE_KEY}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
  } catch (e) {
    console.error('Failed to clear cookies:', e);
  }
}

// Get seen toasts for a specific game
export function getSeenToasts(gameId) {
  try {
    const key = `${SEEN_TOASTS_KEY_PREFIX}${gameId}`;
    const data = localStorage.getItem(key);
    return data ? JSON.parse(data) : [];
  } catch (e) {
    console.error('Failed to read seen toasts:', e);
    return [];
  }
}

// Save seen toasts for a specific game (FIFO, max 50 entries)
export function saveSeenToasts(gameId, toastIds) {
  try {
    const key = `${SEEN_TOASTS_KEY_PREFIX}${gameId}`;
    // Keep only the last 50 toast IDs (FIFO)
    const trimmedIds = toastIds.slice(-50);
    localStorage.setItem(key, JSON.stringify(trimmedIds));
  } catch (e) {
    console.error('Failed to save seen toasts:', e);
  }
}

// Clear seen toasts for a specific game
export function clearSeenToasts(gameId) {
  try {
    const key = `${SEEN_TOASTS_KEY_PREFIX}${gameId}`;
    localStorage.removeItem(key);
  } catch (e) {
    console.error('Failed to clear seen toasts:', e);
  }
}
