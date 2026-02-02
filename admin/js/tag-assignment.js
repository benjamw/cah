/**
 * Tag assignment page module
 */

import { apiRequest } from './api.js';
import { createStateManager } from './url-state.js';

// State schema for URL parameters
const STATE_SCHEMA = {
    tag: { type: 'int', default: null },
    page: { type: 'int', default: 1 },
    type: { type: 'string', default: '' },
    search: { type: 'string', default: '' },
    status: { type: 'string', default: '' },
    limit: { type: 'int', default: 100 }
};

// State defaults (values to exclude from URL if they match)
const STATE_DEFAULTS = {
    page: 1,
    type: '',
    search: '',
    status: '',
    limit: 100
};

// Create state manager
const stateManager = createStateManager(STATE_SCHEMA, { defaults: STATE_DEFAULTS });

// Local state
let totalCards = 0;

/**
 * Load the tag assignment page
 */
export async function loadTagAssignmentPage() {
    let tagSelect = document.getElementById('tag-assignment-select');
    const cardsContainer = document.getElementById('tag-assignment-cards');
    const quickFiltersDiv = document.getElementById('tag-assignment-quick-filters');
    
    // Get current state from URL
    const state = stateManager.get();
    
    // Clear existing options except the first one
    tagSelect.innerHTML = '<option value="">-- Select a tag --</option>';

    // Load all tags
    try {
        const response = await apiRequest('/tags/list');
        if (response.success && response.data && response.data.tags) {
            response.data.tags.forEach(tag => {
                const option = document.createElement('option');
                option.value = tag.tag_id;
                option.textContent = `${tag.name} (W: ${tag.response_card_count || 0}, B: ${tag.prompt_card_count || 0})`;
                tagSelect.appendChild(option);
            });
            
            // Restore tag selection from URL
            if (state.tag) {
                tagSelect.value = state.tag.toString();
            }
        } else {
            console.error('Failed to load tags:', response);
        }
    } catch (error) {
        console.error('Error loading tags:', error);
    }

    // Setup event listeners AFTER populating the select
    setupTagAssignmentListeners();
    
    // Restore filter values from URL
    stateManager.applyToForm({
        type: 'tag-assignment-type-filter',
        search: 'tag-assignment-search',
        status: 'tag-assignment-status-filter',
        limit: 'tag-assignment-limit-filter'
    });
    
    // Update quick filter button states
    updateQuickFilterButtons();

    // Load cards if tag was selected from URL
    if (state.tag) {
        quickFiltersDiv.style.display = 'block';
        await loadTagAssignment();
    } else {
        // Hide quick filters initially
        quickFiltersDiv.style.display = 'none';
        
        // Reset the cards container
        cardsContainer.innerHTML = '<div class="info-message">Select a tag to view and manage card assignments</div>';
        
        // Reset all pagination controls
        document.querySelectorAll('.pagination-prev').forEach(btn => btn.disabled = true);
        document.querySelectorAll('.pagination-next').forEach(btn => btn.disabled = true);
    }
}

/**
 * Setup event listeners for tag assignment page
 */
function setupTagAssignmentListeners() {
    const tagSelect = document.getElementById('tag-assignment-select');
    const applyFiltersBtn = document.getElementById('tag-assignment-apply-filters-btn');
    const searchInput = document.getElementById('tag-assignment-search');
    const quickFiltersDiv = document.getElementById('tag-assignment-quick-filters');
    
    // Remove old listeners by cloning elements
    const newTagSelect = tagSelect.cloneNode(true);
    tagSelect.parentNode.replaceChild(newTagSelect, tagSelect);
    
    // Tag selection
    newTagSelect.addEventListener('change', (e) => {
        const tagId = e.target.value;
        if (tagId) {
            stateManager.set({ tag: parseInt(tagId), page: 1 });
            quickFiltersDiv.style.display = 'block';
            loadTagAssignment();
        } else {
            stateManager.set({ tag: null, page: 1 });
            quickFiltersDiv.style.display = 'none';
            document.getElementById('tag-assignment-cards').innerHTML = '<div class="info-message">Select a tag to view and manage card assignments</div>';
        }
    });
    
    // Quick filter buttons
    document.getElementById('tag-assignment-show-all').addEventListener('click', () => {
        setQuickFilter('');
    });
    
    document.getElementById('tag-assignment-show-tagged').addEventListener('click', () => {
        setQuickFilter('tagged');
    });
    
    document.getElementById('tag-assignment-show-untagged').addEventListener('click', () => {
        setQuickFilter('untagged');
    });
    
    // Bulk untag button
    document.getElementById('tag-assignment-bulk-untag').addEventListener('click', async () => {
        const state = stateManager.get();
        if (!state.tag) return;
        
        const taggedCards = document.querySelectorAll('.tag-assignment-card.has-tag');
        if (taggedCards.length === 0) {
            alert('No tagged cards on this page to untag');
            return;
        }
        
        const confirmMsg = `Remove this tag from ${taggedCards.length} card(s) on this page?`;
        if (!confirm(confirmMsg)) return;
        
        let successCount = 0;
        let failCount = 0;
        
        for (const cardEl of taggedCards) {
            const cardId = parseInt(cardEl.dataset.cardId);
            try {
                const data = await apiRequest(`/admin/cards/${cardId}/tags/${state.tag}`, {
                    method: 'DELETE'
                });
                if (data.success) {
                    successCount++;
                    cardEl.classList.remove('has-tag');
                    const checkbox = cardEl.querySelector('input[type="checkbox"]');
                    if (checkbox) checkbox.checked = false;
                } else {
                    failCount++;
                }
            } catch (error) {
                failCount++;
            }
        }
        
        alert(`Bulk untag complete!\nSuccess: ${successCount}\nFailed: ${failCount}`);
        
        // Reload the page if we're filtering by tagged only
        if (state.status === 'tagged') {
            loadTagAssignment();
        }
    });
    
    // Apply filters
    applyFiltersBtn.addEventListener('click', () => {
        const state = stateManager.get();
        if (!state.tag) {
            alert('Please select a tag first');
            return;
        }
        
        stateManager.set({
            type: document.getElementById('tag-assignment-type-filter').value,
            search: document.getElementById('tag-assignment-search').value.trim(),
            status: document.getElementById('tag-assignment-status-filter').value,
            limit: parseInt(document.getElementById('tag-assignment-limit-filter').value),
            page: 1
        });
        
        updateQuickFilterButtons();
        loadTagAssignment();
    });
    
    // Search on Enter key
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            applyFiltersBtn.click();
        }
    });
    
    // Pagination (handles all pagination controls via class selectors)
    document.querySelectorAll('.pagination-prev').forEach(btn => {
        btn.addEventListener('click', () => {
            const state = stateManager.get();
            if (state.page > 1) {
                stateManager.setValue('page', state.page - 1);
                loadTagAssignment();
            }
        });
    });
    
    document.querySelectorAll('.pagination-next').forEach(btn => {
        btn.addEventListener('click', () => {
            const state = stateManager.get();
            stateManager.setValue('page', state.page + 1);
            loadTagAssignment();
        });
    });
}

/**
 * Set quick filter and reload
 */
function setQuickFilter(status) {
    const state = stateManager.get();
    if (!state.tag) return;
    
    stateManager.set({ status, page: 1 });
    document.getElementById('tag-assignment-status-filter').value = status;
    updateQuickFilterButtons();
    loadTagAssignment();
}

/**
 * Update quick filter button active states
 */
function updateQuickFilterButtons() {
    const state = stateManager.get();
    document.getElementById('tag-assignment-show-all').classList.toggle('active', state.status === '');
    document.getElementById('tag-assignment-show-tagged').classList.toggle('active', state.status === 'tagged');
    document.getElementById('tag-assignment-show-untagged').classList.toggle('active', state.status === 'untagged');
}

async function loadTagAssignment() {
    const state = stateManager.get();
    if (!state.tag) return;
    
    const cardsContainer = document.getElementById('tag-assignment-cards');
    cardsContainer.innerHTML = '<div class="info-message">Loading cards...</div>';

    try {
        const offset = (state.page - 1) * state.limit;
        const params = new URLSearchParams({
            limit: state.limit.toString(),
            offset: offset.toString(),
            active: '1'
        });

        if (state.type) {
            params.append('type', state.type);
        }
        if (state.search) {
            params.append('search', state.search);
        }
        
        // Handle tag status filter (show only tagged/untagged cards)
        if (state.status === 'tagged') {
            params.append('tag_id', state.tag.toString());
        } else if (state.status === 'untagged') {
            params.append('exclude_tag_id', state.tag.toString());
        }

        const response = await apiRequest(`/admin/cards/list?${params}`);
        if ( ! response.success) {
            cardsContainer.innerHTML = '<div class="error-message">Failed to load cards</div>';
            return;
        }

        const cards = response.data.cards;
        totalCards = response.data.total;
        
        if (cards.length === 0) {
            cardsContainer.innerHTML = '<div class="info-message">No cards found</div>';
            updatePagination();
            return;
        }

        // Sort cards: tagged cards first, then untagged
        const sortedCards = cards.sort((a, b) => {
            const aHasTag = a.tags && a.tags.some(t => t.tag_id === state.tag);
            const bHasTag = b.tags && b.tags.some(t => t.tag_id === state.tag);
            if (aHasTag && !bHasTag) return -1;
            if (!aHasTag && bHasTag) return 1;
            return 0;
        });

        // Count tagged vs untagged in current page
        const taggedCount = sortedCards.filter(card => 
            card.tags && card.tags.some(t => t.tag_id === state.tag)
        ).length;

        // Render cards with checkboxes
        cardsContainer.innerHTML = sortedCards.map(card => {
            const hasTag = card.tags && card.tags.some(t => t.tag_id === state.tag);
            return `
                <div class="tag-assignment-card ${hasTag ? 'has-tag' : ''}" data-card-id="${card.card_id}">
                    <label class="tag-assignment-label">
                        <input type="checkbox" 
                               ${hasTag ? 'checked' : ''} 
                               onchange="toggleCardTag(${card.card_id}, ${state.tag}, this.checked)">
                        <span class="badge badge-${card.type}">${card.type}</span>
                        <span class="card-text">${card.copy}</span>
                    </label>
                </div>
            `;
        }).join('');
        
        // Add count indicator
        if (state.status === '') {
            const countInfo = document.createElement('div');
            countInfo.className = 'tag-count-info';
            countInfo.innerHTML = `<strong>On this page:</strong> ${taggedCount} with tag, ${cards.length - taggedCount} without tag`;
            cardsContainer.insertBefore(countInfo, cardsContainer.firstChild);
        }
        
        updatePagination();
    } catch (error) {
        console.error('Error loading tag assignment:', error);
        cardsContainer.innerHTML = '<div class="error-message">Error loading cards</div>';
    }
}

/**
 * Update pagination controls
 */
function updatePagination() {
    const state = stateManager.get();
    
    const totalPages = Math.ceil(totalCards / state.limit);
    const start = (state.page - 1) * state.limit + 1;
    const end = Math.min(state.page * state.limit, totalCards);
    const pageText = `Page ${state.page} of ${totalPages} (${start}-${end} of ${totalCards} cards)`;
    const prevDisabled = state.page <= 1;
    const nextDisabled = state.page >= totalPages || totalCards === 0;
    
    // Update all pagination controls
    document.querySelectorAll('.pagination-info').forEach(el => {
        el.textContent = pageText;
    });
    document.querySelectorAll('.pagination-prev').forEach(btn => {
        btn.disabled = prevDisabled;
    });
    document.querySelectorAll('.pagination-next').forEach(btn => {
        btn.disabled = nextDisabled;
    });
}

/**
 * Toggle a tag on a card
 */
export async function toggleCardTag(cardId, tagId, add) {
    try {
        const endpoint = add 
            ? `/admin/cards/${cardId}/tags/${tagId}`
            : `/admin/cards/${cardId}/tags/${tagId}`;
        
        const data = await apiRequest(endpoint, {
            method: add ? 'POST' : 'DELETE'
        });

        if (data.success) {
            // Update the visual state
            const cardEl = document.querySelector(`.tag-assignment-card[data-card-id="${cardId}"]`);
            if (cardEl) {
                if (add) {
                    cardEl.classList.add('has-tag');
                } else {
                    cardEl.classList.remove('has-tag');
                }
            }
        } else {
            alert(data.message || 'Failed to update tag assignment');
            // Revert checkbox
            const checkbox = document.querySelector(`.tag-assignment-card[data-card-id="${cardId}"] input[type="checkbox"]`);
            if (checkbox) {
                checkbox.checked = ! add;
            }
        }
    } catch (error) {
        alert(error.message || 'Error updating tag assignment');
        // Revert checkbox
        const checkbox = document.querySelector(`.tag-assignment-card[data-card-id="${cardId}"] input[type="checkbox"]`);
        if (checkbox) {
            checkbox.checked = ! add;
        }
    }
}
