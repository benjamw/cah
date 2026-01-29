/**
 * Utility functions module
 */

/**
 * Show an error message on an element
 * @param {HTMLElement} element - Element to show error on
 * @param {string} message - Error message
 */
export function showError(element, message) {
    element.textContent = message;
    element.classList.add('active');
    setTimeout(() => {
        element.classList.remove('active');
    }, 5000);
}

/**
 * Show a modal by ID
 * @param {string} modalId - Modal element ID
 */
export function showModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

/**
 * Hide a modal by ID
 * @param {string} modalId - Modal element ID
 */
export function hideModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

/**
 * Escape single quotes for use in inline onclick handlers
 * @param {string} str - String to escape
 * @returns {string} Escaped string
 */
export function escapeQuotes(str) {
    return (str || '').replace(/'/g, "\\'");
}

