/**
 * Authentication module - handles login/logout
 */

import { getApiBaseUrl, getAuthToken, setAuthToken } from './api.js';
import { showError } from './utils.js';

// DOM elements (initialized in initAuth)
let loginScreen, adminScreen, loginForm, loginError, logoutBtn;

/**
 * Initialize auth module DOM elements
 */
export function initAuth() {
    loginScreen = document.getElementById('login-screen');
    adminScreen = document.getElementById('admin-screen');
    loginForm = document.getElementById('login-form');
    loginError = document.getElementById('login-error');
    logoutBtn = document.getElementById('logout-btn');
}

/**
 * Setup auth event listeners
 * @param {Function} onLoginSuccess - Callback when login succeeds
 */
export function setupAuthListeners(onLoginSuccess) {
    loginForm.addEventListener('submit', (e) => handleLogin(e, onLoginSuccess));
    logoutBtn.addEventListener('click', handleLogout);
}

/**
 * Show the login screen
 */
export function showLoginScreen() {
    loginScreen.classList.add('active');
    adminScreen.classList.remove('active');
}

/**
 * Show the admin screen
 */
export function showAdminScreen() {
    loginScreen.classList.remove('active');
    adminScreen.classList.add('active');
}

/**
 * Check if user is authenticated
 * @returns {boolean}
 */
export function isAuthenticated() {
    return !!getAuthToken();
}

/**
 * Handle login form submission
 * @param {Event} e - Form submit event
 * @param {Function} onSuccess - Callback on successful login
 */
async function handleLogin(e, onSuccess) {
    e.preventDefault();
    const password = document.getElementById('password').value;

    try {
        const response = await fetch(`${getApiBaseUrl()}/admin/login`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ password })
        });

        const data = await response.json();

        if (data.success) {
            setAuthToken(data.data.token);
            showAdminScreen();
            loginError.classList.remove('active');
            if (onSuccess) onSuccess();
        } else {
            showError(loginError, 'Invalid password');
        }
    } catch (error) {
        showError(loginError, 'Login failed. Please try again.');
    }
}

/**
 * Handle logout
 */
async function handleLogout() {
    try {
        await fetch(`${getApiBaseUrl()}/admin/logout`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${getAuthToken()}`
            }
        });
    } catch (error) {
        console.error('Logout error:', error);
    }

    setAuthToken(null);
    showLoginScreen();
}

