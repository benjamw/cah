/**
 * API module - handles all API requests and authentication state
 */

const API_BASE_URL = '/api';
let authToken = localStorage.getItem('admin_token');

/**
 * Make an authenticated API request
 * @param {string} endpoint - API endpoint (without base URL)
 * @param {Object} options - Fetch options
 * @returns {Promise<Object>} JSON response
 */
export async function apiRequest(endpoint, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...options.headers
    };

    if (authToken) {
        headers['Authorization'] = `Bearer ${authToken}`;
    }

    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
        ...options,
        headers
    });

    if (response.status === 401) {
        // Token expired
        authToken = null;
        localStorage.removeItem('admin_token');
        // Import dynamically to avoid circular dependency
        const { showLoginScreen } = await import('./auth.js');
        showLoginScreen();
        throw new Error('Session expired');
    }

    return response.json();
}

/**
 * Get the current auth token
 * @returns {string|null}
 */
export function getAuthToken() {
    return authToken;
}

/**
 * Set the auth token
 * @param {string|null} token
 */
export function setAuthToken(token) {
    authToken = token;
    if (token) {
        localStorage.setItem('admin_token', token);
    } else {
        localStorage.removeItem('admin_token');
    }
}

/**
 * Get the API base URL
 * @returns {string}
 */
export function getApiBaseUrl() {
    return API_BASE_URL;
}

