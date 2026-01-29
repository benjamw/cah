/**
 * Tag assignment page module
 */

import { apiRequest } from './api.js';

/**
 * Load the tag assignment page
 */
export async function loadTagAssignmentPage() {
    const tagSelect = document.getElementById('tag-assignment-select');
    const cardsContainer = document.getElementById('tag-assignment-cards');

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

    // Remove old event listeners by cloning the element
    const newTagSelect = tagSelect.cloneNode(true);
    tagSelect.parentNode.replaceChild(newTagSelect, tagSelect);

    // Add event listener for tag selection
    newTagSelect.addEventListener('change', (e) => {
        const tagId = e.target.value;
        if (tagId) {
            loadTagAssignment(parseInt(tagId));
        } else {
            cardsContainer.innerHTML = '<div class="info-message">Select a tag to view and manage card assignments</div>';
        }
    });

    // Reset the cards container
    cardsContainer.innerHTML = '<div class="info-message">Select a tag to view and manage card assignments</div>';
}

async function loadTagAssignment(tagId) {
    const cardsContainer = document.getElementById('tag-assignment-cards');
    cardsContainer.innerHTML = '<div class="info-message">Loading cards...</div>';

    // Load all cards with a high limit
    const response = await apiRequest('/admin/cards/list?limit=500');
    if ( ! response.success) {
        cardsContainer.innerHTML = '<div class="error-message">Failed to load cards</div>';
        return;
    }

    const cards = response.data.cards;
    if (cards.length === 0) {
        cardsContainer.innerHTML = '<div class="info-message">No cards found</div>';
        return;
    }

    // Render cards with checkboxes
    cardsContainer.innerHTML = cards.map(card => {
        const hasTag = card.tags && card.tags.some(t => t.tag_id === tagId);
        return `
            <div class="tag-assignment-card ${hasTag ? 'has-tag' : ''}" data-card-id="${card.card_id}">
                <label class="tag-assignment-label">
                    <input type="checkbox" 
                           ${hasTag ? 'checked' : ''} 
                           onchange="toggleCardTag(${card.card_id}, ${tagId}, this.checked)">
                    <span class="badge badge-${card.type}">${card.type}</span>
                    <span class="card-text">${card.copy}</span>
                </label>
            </div>
        `;
    }).join('');
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

