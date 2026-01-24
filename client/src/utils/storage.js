const STORAGE_KEY = 'cah_game_data';

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
