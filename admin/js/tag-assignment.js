/**
 * Tag assignment page module
 */

import { apiRequest } from './api.js';

// State
let currentTagId = null;
let currentPage = 1;
let itemsPerPage = 100;
let currentFilters = {
    type: '',
    search: '',
    status: '',
    limit: 100
};
let totalCards = 0;

/**
 * Load the tag assignment page
 */
export async function loadTagAssignmentPage() {
    const tagSelect = document.getElementById('tag-assignment-select');
    const cardsContainer = document.getElementById('tag-assignment-cards');
    
    // Setup event listeners
    setupTagAssignmentListeners();

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
        } else {
            console.error('Failed to load tags:', response);
        }
    } catch (error) {
        console.error('Error loading tags:', error);
    }

    // Reset the cards container
    cardsContainer.innerHTML = '<div class="info-message">Select a tag to view and manage card assignments</div>';
    
    // Reset pagination
    document.getElementById('tag-assignment-prev-page').disabled = true;
    document.getElementById('tag-assignment-next-page').disabled = true;
}

/**
 * Setup event listeners for tag assignment page
 */
function setupTagAssignmentListeners() {
    const tagSelect = document.getElementById('tag-assignment-select');
    const applyFiltersBtn = document.getElementById('tag-assignment-apply-filters-btn');
    const prevPageBtn = document.getElementById('tag-assignment-prev-page');
    const nextPageBtn = document.getElementById('tag-assignment-next-page');
    const searchInput = document.getElementById('tag-assignment-search');
    
    // Remove old listeners by cloning elements
    const newTagSelect = tagSelect.cloneNode(true);
    tagSelect.parentNode.replaceChild(newTagSelect, tagSelect);
    
    // Tag selection
    newTagSelect.addEventListener('change', (e) => {
        const tagId = e.target.value;
        if (tagId) {
            currentTagId = parseInt(tagId);
            currentPage = 1;
            loadTagAssignment();
        } else {
            currentTagId = null;
            document.getElementById('tag-assignment-cards').innerHTML = '<div class="info-message">Select a tag to view and manage card assignments</div>';
        }
    });
    
    // Apply filters
    applyFiltersBtn.addEventListener('click', () => {
        if (!currentTagId) {
            alert('Please select a tag first');
            return;
        }
        currentFilters.type = document.getElementById('tag-assignment-type-filter').value;
        currentFilters.search = document.getElementById('tag-assignment-search').value.trim();
        currentFilters.status = document.getElementById('tag-assignment-status-filter').value;
        currentFilters.limit = parseInt(document.getElementById('tag-assignment-limit-filter').value);
        itemsPerPage = currentFilters.limit;
        currentPage = 1;
        loadTagAssignment();
    });
    
    // Search on Enter key
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            applyFiltersBtn.click();
        }
    });
    
    // Pagination
    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            loadTagAssignment();
        }
    });
    
    nextPageBtn.addEventListener('click', () => {
        currentPage++;
        loadTagAssignment();
    });
}

async function loadTagAssignment() {
    if (!currentTagId) return;
    
    const cardsContainer = document.getElementById('tag-assignment-cards');
    cardsContainer.innerHTML = '<div class="info-message">Loading cards...</div>';

    try {
        const offset = (currentPage - 1) * itemsPerPage;
        const params = new URLSearchParams({
            limit: itemsPerPage.toString(),
            offset: offset.toString(),
            active: '1'
        });

        if (currentFilters.type) {
            params.append('type', currentFilters.type);
        }
        if (currentFilters.search) {
            params.append('search', currentFilters.search);
        }
        
        // Handle tag status filter (show only tagged/untagged cards)
        if (currentFilters.status === 'tagged') {
            params.append('tag_id', currentTagId.toString());
        } else if (currentFilters.status === 'untagged') {
            params.append('exclude_tag_id', currentTagId.toString());
        }

        const response = await apiRequest(`/admin/cards/list?${params}`);
        if (!response.success) {
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

        // Render cards with checkboxes
        cardsContainer.innerHTML = cards.map(card => {
            const hasTag = card.tags && card.tags.some(t => t.tag_id === currentTagId);
            return `
                <div class="tag-assignment-card ${hasTag ? 'has-tag' : ''}" data-card-id="${card.card_id}">
                    <label class="tag-assignment-label">
                        <input type="checkbox" 
                               ${hasTag ? 'checked' : ''} 
                               onchange="toggleCardTag(${card.card_id}, ${currentTagId}, this.checked)">
                        <span class="badge badge-${card.type}">${card.type}</span>
                        <span class="card-text">${card.copy}</span>
                    </label>
                </div>
            `;
        }).join('');
        
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
    const prevBtn = document.getElementById('tag-assignment-prev-page');
    const nextBtn = document.getElementById('tag-assignment-next-page');
    const pageInfo = document.getElementById('tag-assignment-page-info');
    
    const totalPages = Math.ceil(totalCards / itemsPerPage);
    const start = (currentPage - 1) * itemsPerPage + 1;
    const end = Math.min(currentPage * itemsPerPage, totalCards);
    
    pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${start}-${end} of ${totalCards} cards)`;
    prevBtn.disabled = currentPage <= 1;
    nextBtn.disabled = currentPage >= totalPages || totalCards === 0;
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
                checkbox.checked = !add;
            }
        }
    } catch (error) {
        alert(error.message || 'Error updating tag assignment');
        // Revert checkbox
        const checkbox = document.querySelector(`.tag-assignment-card[data-card-id="${cardId}"] input[type="checkbox"]`);
        if (checkbox) {
            checkbox.checked = !add;
        }
    }
}

