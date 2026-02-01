/**
 * Tag caching utilities using localStorage
 * Tags don't change often, so we cache them to reduce API calls
 */

const TAG_CACHE_KEY = 'cah_tags_cache';
const TAG_CACHE_TIMESTAMP_KEY = 'cah_tags_cache_timestamp';
const CACHE_DURATION = 24 * 60 * 60 * 1000; // 24 hours in milliseconds

/**
 * Get tags from cache if available and not expired
 * @returns {Array|null} Array of tags or null if cache is empty/expired
 */
export function getCachedTags() {
  try {
    const cached = localStorage.getItem(TAG_CACHE_KEY);
    const timestamp = localStorage.getItem(TAG_CACHE_TIMESTAMP_KEY);
    
    if (!cached || !timestamp) {
      return null;
    }
    
    const cacheAge = Date.now() - parseInt(timestamp, 10);
    if (cacheAge > CACHE_DURATION) {
      // Cache expired
      clearTagCache();
      return null;
    }
    
    return JSON.parse(cached);
  } catch (error) {
    console.error('Error reading tag cache:', error);
    clearTagCache();
    return null;
  }
}

/**
 * Store tags in cache
 * @param {Array} tags - Array of tag objects
 */
export function setCachedTags(tags) {
  try {
    localStorage.setItem(TAG_CACHE_KEY, JSON.stringify(tags));
    localStorage.setItem(TAG_CACHE_TIMESTAMP_KEY, Date.now().toString());
  } catch (error) {
    console.error('Error storing tag cache:', error);
  }
}

/**
 * Clear the tag cache
 */
export function clearTagCache() {
  try {
    localStorage.removeItem(TAG_CACHE_KEY);
    localStorage.removeItem(TAG_CACHE_TIMESTAMP_KEY);
  } catch (error) {
    console.error('Error clearing tag cache:', error);
  }
}

/**
 * Check if tag cache exists and is valid
 * @returns {boolean}
 */
export function hasValidTagCache() {
  const timestamp = localStorage.getItem(TAG_CACHE_TIMESTAMP_KEY);
  if (!timestamp) {
    return false;
  }
  
  const cacheAge = Date.now() - parseInt(timestamp, 10);
  return cacheAge <= CACHE_DURATION;
}
