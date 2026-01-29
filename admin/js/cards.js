/**
 * Cards management module
 */

import { apiRequest, getApiBaseUrl, getAuthToken } from './api.js';
import { hideModal } from './utils.js';

// State
let currentPage = 1;
let itemsPerPage = 50;
let currentFilters = {
    type: '',
    active: '1',
    tags: [],
    limit: 50
};

// DOM elements (initialized in initCards)
let cardsList, cardTypeFilter, cardActiveFilter, cardTagsFilter, cardLimitFilter;
let prevPageBtn, nextPageBtn, pageInfo;
let importModal, uploadCsvBtn, csvFileInput, importStatus, importCardType, importFormatInfo;
let editCardModal, saveCardBtn;

/**
 * Initialize cards module DOM elements
 */
export function initCards() {
    cardsList = document.getElementById('cards-list');
    cardTypeFilter = document.getElementById('card-type-filter');
    cardActiveFilter = document.getElementById('card-active-filter');
    cardTagsFilter = document.getElementById('card-tags-filter');
    cardLimitFilter = document.getElementById('card-limit-filter');
    prevPageBtn = document.getElementById('prev-page');
    nextPageBtn = document.getElementById('next-page');
    pageInfo = document.getElementById('page-info');
    importModal = document.getElementById('import-modal');
    uploadCsvBtn = document.getElementById('upload-csv-btn');
    csvFileInput = document.getElementById('csv-file');
    importStatus = document.getElementById('import-status');
    importCardType = document.getElementById('import-card-type');
    importFormatInfo = document.getElementById('import-format-info');
    editCardModal = document.getElementById('edit-card-modal');
    saveCardBtn = document.getElementById('save-card-btn');
}

/**
 * Setup cards event listeners
 */
export function setupCardsListeners() {
    document.getElementById('apply-filters-btn').addEventListener('click', () => {
        currentFilters.type = cardTypeFilter.value;
        currentFilters.active = cardActiveFilter.value;
        currentFilters.tags = Array.from(cardTagsFilter.selectedOptions).map(opt => opt.value).filter(v => v);
        currentFilters.limit = parseInt(cardLimitFilter.value);
        itemsPerPage = currentFilters.limit;
        currentPage = 1;
        loadCards();
    });

    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            loadCards();
        }
    });

    nextPageBtn.addEventListener('click', () => {
        currentPage++;
        loadCards();
    });

    document.getElementById('import-cards-btn').addEventListener('click', () => {
        importModal.classList.add('active');
    });

    uploadCsvBtn.addEventListener('click', handleImportCards);
    importCardType.addEventListener('change', updateImportFormatInfo);
    saveCardBtn.addEventListener('click', handleSaveCard);
}

/**
 * Load tags for the filter dropdown
 */
export async function loadTagsFilter() {
    try {
        const data = await apiRequest('/tags/list');

        if (data.success && data.data.tags) {
            cardTagsFilter.innerHTML = '<option value="">All Tags</option>' +
                '<option value="none">None (No Tags)</option>' +
                data.data.tags.map(tag =>
                    `<option value="${tag.tag_id}">${tag.name} (${tag.total_card_count || 0})</option>`
                ).join('');
        }
    } catch (error) {
        cardTagsFilter.innerHTML = '<option value="">Error loading tags</option>';
    }
}

/**
 * Load and display cards
 */
export async function loadCards() {
    cardsList.innerHTML = '<div class="loading">Loading cards...</div>';

    try {
        const offset = (currentPage - 1) * itemsPerPage;
        const params = new URLSearchParams({
            limit: itemsPerPage.toString(),
            offset: offset.toString()
        });

        if (currentFilters.type) {
            params.append('type', currentFilters.type);
        }
        if (currentFilters.active !== '') {
            params.append('active', currentFilters.active);
        }
        if (currentFilters.tags && currentFilters.tags.length > 0) {
            params.append('tag_id', currentFilters.tags[0]);
        }

        const data = await apiRequest(`/admin/cards/list?${params}`);

        if (data.success) {
            renderCards(data.data.cards);
            updatePagination(data.data.total);
        } else {
            cardsList.innerHTML = '<div class="loading">Failed to load cards</div>';
        }
    } catch (error) {
        cardsList.innerHTML = '<div class="loading">Error loading cards</div>';
    }
}

function renderCards(cards) {
    if (cards.length === 0) {
        cardsList.innerHTML = '<div class="loading">No cards found</div>';
        return;
    }

    cardsList.innerHTML = cards.map(card => `
        <div class="card-item" data-id="${card.card_id}">
            <div class="item-info">
                <div class="item-title">
                    <span class="badge badge-${card.type}">${card.type}</span>
                    <span class="badge badge-${card.active ? 'active' : 'inactive'}">
                        ${card.active ? 'Active' : 'Inactive'}
                    </span>
                    ${card.copy}
                </div>
                <div class="item-meta">
                    ID: ${card.card_id} |
                    ${card.choices ? `Choices: ${card.choices} | ` : ''}
                    Tags: ${card.tags?.map(t => t.name).join(', ') || 'None'}
                </div>
            </div>
            <div class="item-actions">
                <button class="btn btn-small btn-primary" onclick='editCard(${JSON.stringify(card)})'>Edit</button>
                <button class="btn btn-small btn-danger" onclick="deleteCard(${card.card_id})">Delete</button>
            </div>
        </div>
    `).join('');
}

function updatePagination(total) {
    const totalPages = Math.ceil(total / itemsPerPage);
    pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${total} cards)`;
    prevPageBtn.disabled = currentPage <= 1;
    nextPageBtn.disabled = currentPage >= totalPages;
}

/**
 * Edit a card - opens modal with card data
 * @param {Object} card - Card data
 */
export function editCard(card) {
    document.getElementById('edit-card-id').value = card.card_id;
    document.getElementById('edit-card-text').value = card.copy;
    document.getElementById('edit-card-type').value = card.type;
    document.getElementById('edit-card-active').checked = card.active;
    editCardModal.classList.add('active');
}

async function handleSaveCard() {
    const cardId = document.getElementById('edit-card-id').value;
    const text = document.getElementById('edit-card-text').value;
    const type = document.getElementById('edit-card-type').value;
    const active = document.getElementById('edit-card-active').checked;

    try {
        saveCardBtn.disabled = true;

        const data = await apiRequest(`/admin/cards/edit/${cardId}`, {
            method: 'PUT',
            body: JSON.stringify({ copy: text, type, active })
        });

        if ( ! data.success) {
            alert(data.message || 'Failed to update card');
            return;
        }

        hideModal('edit-card-modal');
        loadCards();
    } catch (error) {
        alert(error.message || 'Error updating card');
    } finally {
        saveCardBtn.disabled = false;
    }
}

/**
 * Delete a card
 * @param {number} cardId - Card ID
 */
export async function deleteCard(cardId) {
    if ( ! confirm('Are you sure you want to delete this card?')) {
        return;
    }

    try {
        const data = await apiRequest(`/admin/cards/delete/${cardId}`, {
            method: 'DELETE'
        });

        if (data.success) {
            loadCards();
        } else {
            alert(data.message || 'Failed to delete card');
        }
    } catch (error) {
        alert(error.message || 'Error deleting card');
    }
}

function updateImportFormatInfo() {
    const cardType = importCardType.value;

    if (cardType === 'mixed') {
        importFormatInfo.innerHTML = `
            <p><strong>Mixed format:</strong> <code>type,text,tags</code></p>
            <p>Example: <code>response,"Card text",tag1,tag2</code></p>
        `;
    } else if (cardType === 'response') {
        importFormatInfo.innerHTML = `
            <p><strong>White cards format:</strong> <code>text,tags</code></p>
            <p>Example: <code>"Card text",tag1,tag2</code></p>
        `;
    } else if (cardType === 'prompt') {
        importFormatInfo.innerHTML = `
            <p><strong>Black cards format:</strong> <code>text,tags</code></p>
            <p>Example: <code>"Question with _ blank?",tag1,tag2</code></p>
        `;
    }
}

async function handleImportCards() {
    const file = csvFileInput.files[0];
    if ( ! file) {
        importStatus.textContent = 'Please select a file';
        importStatus.className = 'status-message error';
        return;
    }

    const formData = new FormData();
    formData.append('file', file);

    try {
        uploadCsvBtn.disabled = true;
        importStatus.textContent = 'Uploading...';
        importStatus.className = 'status-message';

        const cardType = importCardType.value === 'mixed' ? 'response' : importCardType.value;
        const url = `${getApiBaseUrl()}/admin/cards/import?type=${cardType}`;

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${getAuthToken()}`
            },
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            importStatus.textContent = `Success! Imported: ${data.data.imported}, Failed: ${data.data.failed}`;
            importStatus.className = 'status-message success';
            csvFileInput.value = '';
            setTimeout(() => {
                importModal.classList.remove('active');
                loadCards();
            }, 2000);
        } else {
            importStatus.textContent = 'Import failed';
            importStatus.className = 'status-message error';
        }
    } catch (error) {
        importStatus.textContent = 'Error uploading file';
        importStatus.className = 'status-message error';
    } finally {
        uploadCsvBtn.disabled = false;
    }
}
