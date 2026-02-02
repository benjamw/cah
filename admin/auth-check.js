/**
 * Auth check module - ensures user is authenticated
 * Include this in every admin page except login
 */

import { getAuthToken } from '/admin/js/api.js';

// Check if user is authenticated
if (!getAuthToken()) {
    // Redirect to login page
    window.location.href = '/admin/login.html';
}

// Handle logout
const logoutBtn = document.getElementById('logout-btn');
if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
        try {
            await fetch('/api/admin/logout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${getAuthToken()}`
                }
            });
        } catch (error) {
            console.error('Logout error:', error);
        }
        
        localStorage.removeItem('admin_token');
        window.location.href = '/admin/login.html';
    });
}
