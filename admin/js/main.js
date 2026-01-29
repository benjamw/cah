/**
 * Main entry point for the admin SPA
 * Initializes all modules and sets up global event handlers
 */

import { getAuthToken } from './api.js';
import { initAuth, setupAuthListeners, showLoginScreen, showAdminScreen, isAuthenticated } from './auth.js';
import { initCards, setupCardsListeners, loadCards, loadTagsFilter, editCard, deleteCard } from './cards.js';
import { initTags, setupTagsListeners, loadTags, editTag, toggleTag, deleteTag } from './tags.js';
import { initPacks, setupPacksListeners, loadPacks, editPack, togglePack, deletePack } from './packs.js';
import { initGames, loadGames, deleteGame } from './games.js';
import { loadTagAssignmentPage, toggleCardTag } from './tag-assignment.js';
import { hideModal } from './utils.js';

// DOM elements
let navBtns, contentSections;

/**
 * Initialize the application
 */
function init() {
    // Initialize DOM element references
    navBtns = document.querySelectorAll('.nav-btn');
    contentSections = document.querySelectorAll('.content-section');

    // Initialize all modules
    initAuth();
    initCards();
    initTags();
    initPacks();
    initGames();

    // Setup event listeners
    setupAuthListeners(() => switchSection('cards'));
    setupCardsListeners();
    setupTagsListeners();
    setupPacksListeners();
    setupNavigation();
    setupModalCloseButtons();

    // Check authentication and show appropriate screen
    if (isAuthenticated()) {
        showAdminScreen();
        switchSection('cards');
    } else {
        showLoginScreen();
    }
}

/**
 * Setup navigation button listeners
 */
function setupNavigation() {
    navBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const section = btn.dataset.section;
            switchSection(section);
        });
    });
}

/**
 * Setup modal close button listeners
 */
function setupModalCloseButtons() {
    const modalCloseBtns = document.querySelectorAll('.modal-close');
    modalCloseBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            hideModal('import-modal');
            hideModal('edit-card-modal');
            hideModal('create-tag-modal');
            hideModal('create-pack-modal');
        });
    });
}

/**
 * Switch to a different section
 */
function switchSection(section) {
    navBtns.forEach(btn => btn.classList.remove('active'));
    contentSections.forEach(sec => sec.classList.remove('active'));

    document.querySelector(`[data-section="${section}"]`).classList.add('active');
    document.getElementById(`${section}-section`).classList.add('active');

    // Load data for the section
    if (section === 'cards') {
        loadTagsFilter();
        loadCards();
    } else if (section === 'tags') {
        loadTags();
    } else if (section === 'packs') {
        loadPacks();
    } else if (section === 'games') {
        loadGames();
    } else if (section === 'tag-assignment') {
        loadTagAssignmentPage();
    }
}

// Expose functions to window for inline onclick handlers
window.editCard = editCard;
window.deleteCard = deleteCard;
window.editTag = editTag;
window.toggleTag = toggleTag;
window.deleteTag = deleteTag;
window.editPack = editPack;
window.togglePack = togglePack;
window.deletePack = deletePack;
window.deleteGame = deleteGame;
window.toggleCardTag = toggleCardTag;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', init);

