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
    packs: [],
    packStatus: '',
    search: '',
    limit: 50
};

// DOM elements (initialized in initCards)
let cardsList, cardTypeFilter, cardActiveFilter, cardTagsFilter, cardPackFilter, cardPackStatusFilter, cardSearchFilter, cardLimitFilter;
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
    cardPackFilter = document.getElementById('card-pack-filter');
    cardPackStatusFilter = document.getElementById('card-pack-status-filter');
    cardSearchFilter = document.getElementById('card-search-filter');
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
        currentFilters.packs = Array.from(cardPackFilter.selectedOptions).map(opt => opt.value).filter(v => v);
        currentFilters.packStatus = cardPackStatusFilter.value;
        currentFilters.search = cardSearchFilter.value.trim();
        currentFilters.limit = parseInt(cardLimitFilter.value);
        itemsPerPage = currentFilters.limit;
        currentPage = 1;
        loadCards();
    });
    
    // Search on Enter key
    cardSearchFilter.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            document.getElementById('apply-filters-btn').click();
        }
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
 * Load packs for the filter dropdown
 */
export async function loadPacksFilter() {
    try {
        const data = await apiRequest('/packs/list-all');

        if (data.success && data.data.packs) {
            cardPackFilter.innerHTML = '<option value="">All Packs</option>' +
                '<option value="none">None (No Packs)</option>' +
                data.data.packs.map(pack => {
                    const version = pack.version ? ` v${pack.version}` : '';
                    const count = (pack.response_card_count || 0) + (pack.prompt_card_count || 0);
                    return `<option value="${pack.pack_id}">${pack.name}${version} (${count})</option>`;
                }).join('');
        }
    } catch (error) {
        cardPackFilter.innerHTML = '<option value="">Error loading packs</option>';
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
        if (currentFilters.packs && currentFilters.packs.length > 0) {
            params.append('pack_id', currentFilters.packs[0]);
        }
        if (currentFilters.packStatus !== '') {
            params.append('pack_active', currentFilters.packStatus);
        }
        if (currentFilters.search) {
            params.append('search', currentFilters.search);
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
                    <span class="badge badge-${card.type}">${card.type === 'response' ? 'Response' : 'Prompt'}</span>
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
export async function editCard(card) {
    document.getElementById('edit-card-id').value = card.card_id;
    document.getElementById('edit-card-text').value = card.copy;
    document.getElementById('edit-card-type').value = card.type;
    document.getElementById('edit-card-active').checked = card.active;
    
    // Load tags and packs for the select boxes
    await loadCardTagsAndPacks(card.card_id);
    
    editCardModal.classList.add('active');
}

/**
 * Load available tags/packs and current card assignments
 */
async function loadCardTagsAndPacks(cardId) {
    const tagsSelect = document.getElementById('edit-card-tags');
    const packsSelect = document.getElementById('edit-card-packs');
    
    try {
        // Load all available tags and packs in parallel
        const [tagsResponse, packsResponse, cardTagsResponse, cardPacksResponse] = await Promise.all([
            apiRequest('/tags/list'),
            apiRequest('/packs/list-all'),
            apiRequest(`/admin/cards/${cardId}/tags`),
            apiRequest(`/admin/cards/${cardId}/packs`)
        ]);
        
        // Populate tags select box
        if (tagsResponse.success && tagsResponse.data.tags) {
            const currentTagIds = cardTagsResponse.success 
                ? cardTagsResponse.data.tags.map(t => t.tag_id) 
                : [];
            
            tagsSelect.innerHTML = tagsResponse.data.tags.map(tag => {
                const selected = currentTagIds.includes(tag.tag_id) ? 'selected' : '';
                return `<option value="${tag.tag_id}" ${selected}>${tag.name}</option>`;
            }).join('');
        } else {
            tagsSelect.innerHTML = '<option value="">Error loading tags</option>';
        }
        
        // Populate packs select box
        if (packsResponse.success && packsResponse.data.packs) {
            const currentPackIds = cardPacksResponse.success 
                ? cardPacksResponse.data.packs.map(p => p.pack_id) 
                : [];
            
            packsSelect.innerHTML = packsResponse.data.packs.map(pack => {
                const selected = currentPackIds.includes(pack.pack_id) ? 'selected' : '';
                const version = pack.version ? ` v${pack.version}` : '';
                return `<option value="${pack.pack_id}" ${selected}>${pack.name}${version}</option>`;
            }).join('');
        } else {
            packsSelect.innerHTML = '<option value="">Error loading packs</option>';
        }
    } catch (error) {
        console.error('Error loading tags/packs:', error);
        tagsSelect.innerHTML = '<option value="">Error loading tags</option>';
        packsSelect.innerHTML = '<option value="">Error loading packs</option>';
    }
}

async function handleSaveCard() {
    const cardId = document.getElementById('edit-card-id').value;
    const text = document.getElementById('edit-card-text').value;
    const type = document.getElementById('edit-card-type').value;
    const active = document.getElementById('edit-card-active').checked;
    
    const selectedTagIds = Array.from(document.getElementById('edit-card-tags').selectedOptions)
        .map(opt => parseInt(opt.value));
    const selectedPackIds = Array.from(document.getElementById('edit-card-packs').selectedOptions)
        .map(opt => parseInt(opt.value));

    try {
        saveCardBtn.disabled = true;

        // Update card basic info
        const data = await apiRequest(`/admin/cards/edit/${cardId}`, {
            method: 'PUT',
            body: JSON.stringify({ copy: text, type, active })
        });

        if ( ! data.success) {
            alert(data.message || 'Failed to update card');
            return;
        }
        
        // Sync tags and packs
        await syncCardTagsAndPacks(cardId, selectedTagIds, selectedPackIds);

        hideModal('edit-card-modal');
        loadCards();
    } catch (error) {
        alert(error.message || 'Error updating card');
    } finally {
        saveCardBtn.disabled = false;
    }
}

/**
 * Sync card tags and packs with selected values
 */
async function syncCardTagsAndPacks(cardId, selectedTagIds, selectedPackIds) {
    try {
        // Get current assignments
        const [cardTagsResponse, cardPacksResponse] = await Promise.all([
            apiRequest(`/admin/cards/${cardId}/tags`),
            apiRequest(`/admin/cards/${cardId}/packs`)
        ]);
        
        const currentTagIds = cardTagsResponse.success 
            ? cardTagsResponse.data.tags.map(t => t.tag_id) 
            : [];
        const currentPackIds = cardPacksResponse.success 
            ? cardPacksResponse.data.packs.map(p => p.pack_id) 
            : [];
        
        // Determine which tags to add and remove
        const tagsToAdd = selectedTagIds.filter(id => ! currentTagIds.includes(id));
        const tagsToRemove = currentTagIds.filter(id => ! selectedTagIds.includes(id));
        
        // Determine which packs to add and remove
        const packsToAdd = selectedPackIds.filter(id => ! currentPackIds.includes(id));
        const packsToRemove = currentPackIds.filter(id => ! selectedPackIds.includes(id));
        
        // Execute all changes in parallel
        const promises = [];
        
        tagsToAdd.forEach(tagId => {
            promises.push(apiRequest(`/admin/cards/${cardId}/tags/${tagId}`, { method: 'POST' }));
        });
        
        tagsToRemove.forEach(tagId => {
            promises.push(apiRequest(`/admin/cards/${cardId}/tags/${tagId}`, { method: 'DELETE' }));
        });
        
        packsToAdd.forEach(packId => {
            promises.push(apiRequest(`/admin/cards/${cardId}/packs/${packId}`, { method: 'POST' }));
        });
        
        packsToRemove.forEach(packId => {
            promises.push(apiRequest(`/admin/cards/${cardId}/packs/${packId}`, { method: 'DELETE' }));
        });
        
        if (promises.length > 0) {
            await Promise.all(promises);
        }
    } catch (error) {
        console.error('Error syncing tags/packs:', error);
        throw error;
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
