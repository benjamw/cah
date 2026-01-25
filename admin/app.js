// Configuration
const API_BASE_URL = '/api';
let authToken = localStorage.getItem('admin_token');

// State
let currentPage = 1;
let itemsPerPage = 50;
let currentFilters = {
    type: '',
    active: '1',
    tags: [],
    limit: 50
};

// DOM Elements - will be initialized in init()
let loginScreen, adminScreen, loginForm, loginError, logoutBtn;
let navBtns, contentSections;
let cardsList, cardTypeFilter, cardActiveFilter, cardTagsFilter, cardLimitFilter, applyFiltersBtn;
let prevPageBtn, nextPageBtn, pageInfo;
let importCardsBtn, importModal, uploadCsvBtn, csvFileInput, importStatus, importCardType, importFormatInfo;
let editCardModal, editCardForm, saveCardBtn;
let tagsList, createTagBtn, createTagModal, createTagForm, saveTagBtn;
let gamesList;
let modalCloseBtns;

// Initialize
function init() {
    // Initialize DOM elements
    loginScreen = document.getElementById('login-screen');
    adminScreen = document.getElementById('admin-screen');
    loginForm = document.getElementById('login-form');
    loginError = document.getElementById('login-error');
    logoutBtn = document.getElementById('logout-btn');

    navBtns = document.querySelectorAll('.nav-btn');
    contentSections = document.querySelectorAll('.content-section');

    cardsList = document.getElementById('cards-list');
    cardTypeFilter = document.getElementById('card-type-filter');
    cardActiveFilter = document.getElementById('card-active-filter');
    cardTagsFilter = document.getElementById('card-tags-filter');
    cardLimitFilter = document.getElementById('card-limit-filter');
    applyFiltersBtn = document.getElementById('apply-filters-btn');
    prevPageBtn = document.getElementById('prev-page');
    nextPageBtn = document.getElementById('next-page');
    pageInfo = document.getElementById('page-info');
    importCardsBtn = document.getElementById('import-cards-btn');
    importModal = document.getElementById('import-modal');
    uploadCsvBtn = document.getElementById('upload-csv-btn');
    csvFileInput = document.getElementById('csv-file');
    importStatus = document.getElementById('import-status');
    importCardType = document.getElementById('import-card-type');
    importFormatInfo = document.getElementById('import-format-info');

    editCardModal = document.getElementById('edit-card-modal');
    editCardForm = document.getElementById('edit-card-form');
    saveCardBtn = document.getElementById('save-card-btn');

    tagsList = document.getElementById('tags-list');
    createTagBtn = document.getElementById('create-tag-btn');
    createTagModal = document.getElementById('create-tag-modal');
    createTagForm = document.getElementById('create-tag-form');
    saveTagBtn = document.getElementById('save-tag-btn');

    gamesList = document.getElementById('games-list');

    modalCloseBtns = document.querySelectorAll('.modal-close');

    // Check authentication and show appropriate screen
    if (authToken) {
        showAdminScreen();
        switchSection('cards'); // Force load cards tab
    } else {
        showLoginScreen();
    }

    setupEventListeners();
}

function setupEventListeners() {
    // Login
    loginForm.addEventListener('submit', handleLogin);
    logoutBtn.addEventListener('click', handleLogout);

    // Navigation
    navBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const section = btn.dataset.section;
            switchSection(section);
        });
    });

    // Cards
    applyFiltersBtn.addEventListener('click', () => {
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

    importCardsBtn.addEventListener('click', () => {
        importModal.classList.add('active');
    });

    uploadCsvBtn.addEventListener('click', handleImportCards);

    // Update format info when card type changes
    importCardType.addEventListener('change', updateImportFormatInfo);

    // Tags
    createTagBtn.addEventListener('click', () => {
        // Reset form for creating new tag
        document.getElementById('tag-modal-title').textContent = 'Create Tag';
        document.getElementById('tag-id').value = '';
        document.getElementById('tag-name').value = '';
        document.getElementById('tag-description').value = '';
        document.getElementById('tag-active').checked = true;
        createTagModal.classList.add('active');
    });

    saveTagBtn.addEventListener('click', handleSaveTag);

    // Cards edit
    saveCardBtn.addEventListener('click', handleSaveCard);

    // Modals
    modalCloseBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            importModal.classList.remove('active');
            editCardModal.classList.remove('active');
            createTagModal.classList.remove('active');
        });
    });
}

// Screen Management
function showLoginScreen() {
    loginScreen.classList.add('active');
    adminScreen.classList.remove('active');
}

function showAdminScreen() {
    loginScreen.classList.remove('active');
    adminScreen.classList.add('active');
}

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
    } else if (section === 'games') {
        loadGames();
    } else if (section === 'tag-assignment') {
        loadTagAssignmentPage();
    }
}




// Authentication
async function handleLogin(e) {
    e.preventDefault();
    const password = document.getElementById('password').value;

    try {
        const response = await fetch(`${API_BASE_URL}/admin/login`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ password })
        });

        const data = await response.json();

        if (data.success) {
            authToken = data.data.token;
            localStorage.setItem('admin_token', authToken);
            showAdminScreen();
            switchSection('cards'); // Force load cards tab
            loginError.classList.remove('active');
        } else {
            showError(loginError, 'Invalid password');
        }
    } catch (error) {
        showError(loginError, 'Login failed. Please try again.');
    }
}

async function handleLogout() {
    try {
        await fetch(`${API_BASE_URL}/admin/logout`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${authToken}`
            }
        });
    } catch (error) {
        console.error('Logout error:', error);
    }

    authToken = null;
    localStorage.removeItem('admin_token');
    showLoginScreen();
}

// API Helpers
async function apiRequest(endpoint, options = {}) {
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
        showLoginScreen();
        throw new Error('Session expired');
    }

    return response.json();
}

// Cards Management
async function loadTagsFilter() {
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

async function loadCards() {
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
            // Send first tag only - backend supports single tag_id parameter
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
                    <span class="badge badge-${card.card_type}">${card.card_type}</span>
                    <span class="badge badge-${card.active ? 'active' : 'inactive'}">
                        ${card.active ? 'Active' : 'Inactive'}
                    </span>
                    ${card.value}
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
    pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${total} total)`;
    prevPageBtn.disabled = currentPage === 1;
    nextPageBtn.disabled = currentPage >= totalPages;
}

async function editCard(card) {
    document.getElementById('edit-card-id').value = card.card_id;
    document.getElementById('edit-card-text').value = card.value;
    document.getElementById('edit-card-type').value = card.card_type;
    document.getElementById('edit-card-active').checked = card.active;

    // Load all tags and populate the multi-select
    const tagsSelect = document.getElementById('edit-card-tags');
    tagsSelect.innerHTML = '';

    try {
        // Fetch all available tags
        const tagsData = await apiRequest('/tags/list');
        const allTags = tagsData.data?.tags || [];

        // Fetch current tags for this card
        const cardTagsData = await apiRequest(`/admin/cards/${card.card_id}/tags`);
        const cardTagIds = (cardTagsData.data?.tags || []).map(t => t.tag_id);

        // Populate the select with all tags and pre-select current ones
        allTags.forEach(tag => {
            const option = document.createElement('option');
            option.value = tag.tag_id;
            option.textContent = tag.name;
            if (cardTagIds.includes(tag.tag_id)) {
                option.selected = true;
            }
            tagsSelect.appendChild(option);
        });

        // Store original tag IDs for comparison when saving
        tagsSelect.dataset.originalTags = JSON.stringify(cardTagIds);

    } catch (error) {
        console.error('Error loading tags for card:', error);
        alert('Error loading tags for this card');
    }

    editCardModal.classList.add('active');
}

async function handleSaveCard() {
    const cardId = document.getElementById('edit-card-id').value;
    const text = document.getElementById('edit-card-text').value;
    const type = document.getElementById('edit-card-type').value;
    const active = document.getElementById('edit-card-active').checked;

    try {
        saveCardBtn.disabled = true;

        // First, update the card itself
        const data = await apiRequest(`/admin/cards/edit/${cardId}`, {
            method: 'PUT',
            body: JSON.stringify({ text, type, active })
        });

        if ( ! data.success) {
            alert('Failed to update card');
            return;
        }

        // Now handle tag changes
        const tagsSelect = document.getElementById('edit-card-tags');
        const selectedOptions = Array.from(tagsSelect.selectedOptions);
        const selectedTagIds = selectedOptions.map(opt => parseInt(opt.value));
        const originalTagIds = JSON.parse(tagsSelect.dataset.originalTags || '[]');

        // Determine which tags to add and remove
        const tagsToAdd = selectedTagIds.filter(id => ! originalTagIds.includes(id));
        const tagsToRemove = originalTagIds.filter(id => ! selectedTagIds.includes(id));

        // Add new tags
        for (const tagId of tagsToAdd) {
            try {
                await apiRequest(`/admin/cards/${cardId}/tags/${tagId}`, {
                    method: 'POST'
                });
            } catch (error) {
                console.error(`Failed to add tag ${tagId} to card ${cardId}:`, error);
            }
        }

        // Remove tags
        for (const tagId of tagsToRemove) {
            try {
                await apiRequest(`/admin/cards/${cardId}/tags/${tagId}`, {
                    method: 'DELETE'
                });
            } catch (error) {
                console.error(`Failed to remove tag ${tagId} from card ${cardId}:`, error);
            }
        }

        // Close modal and reload cards
        editCardModal.classList.remove('active');
        loadCards();

    } catch (error) {
        console.error('Error updating card:', error);
        alert('Error updating card');
    } finally {
        saveCardBtn.disabled = false;
    }
}

async function deleteCard(cardId) {
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
            alert('Failed to delete card');
        }
    } catch (error) {
        alert('Error deleting card');
    }
}

function updateImportFormatInfo() {
    const cardType = importCardType.value;

    if (cardType === 'mixed') {
        importFormatInfo.innerHTML = `
            <p><strong>Mixed format:</strong> <code>type,text,tags</code></p>
            <p>Example: <code>white,"Card text",tag1,tag2</code></p>
        `;
    } else if (cardType === 'white') {
        importFormatInfo.innerHTML = `
            <p><strong>White cards format:</strong> <code>text,tags</code></p>
            <p>Example: <code>"Card text",tag1,tag2</code></p>
        `;
    } else if (cardType === 'black') {
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

        const cardType = importCardType.value === 'mixed' ? 'white' : importCardType.value;
        const url = `${API_BASE_URL}/admin/cards/import?type=${cardType}`;

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${authToken}`
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

// Tags Management
async function loadTags() {
    tagsList.innerHTML = '<div class="loading">Loading tags...</div>';

    try {
        const data = await apiRequest('/tags/list');

        if (data.success) {
            renderTags(data.data.tags);
        } else {
            tagsList.innerHTML = '<div class="loading">Failed to load tags</div>';
        }
    } catch (error) {
        tagsList.innerHTML = '<div class="loading">Error loading tags</div>';
    }
}

function renderTags(tags) {
    if (tags.length === 0) {
        tagsList.innerHTML = '<div class="loading">No tags found</div>';
        return;
    }

    tagsList.innerHTML = tags.map(tag => `
        <div class="tag-item" data-id="${tag.tag_id}">
            <div class="item-info">
                <div class="item-title">
                    <span class="badge badge-${tag.active ? 'active' : 'inactive'}">
                        ${tag.active ? 'Active' : 'Inactive'}
                    </span>
                    ${tag.name}
                </div>
                <div class="item-meta">
                    ${tag.description || 'No description'} |
                    White Cards: ${tag.white_card_count || 0} |
                    Black Cards: ${tag.black_card_count || 0}
                </div>
            </div>
            <div class="item-actions">
                <button class="btn btn-small btn-primary" onclick="editTag(${tag.tag_id}, '${tag.name.replace(/'/g, "\\'")}', '${(tag.description || '').replace(/'/g, "\\'")}', ${tag.active})">Edit</button>
                <button class="btn btn-small btn-secondary" onclick="toggleTag(${tag.tag_id}, ${ ! tag.active})">
                    ${tag.active ? 'Deactivate' : 'Activate'}
                </button>
                <button class="btn btn-small btn-danger" onclick="deleteTag(${tag.tag_id})">Delete</button>
            </div>
        </div>
    `).join('');
}

function editTag(tagId, name, description, active) {
    document.getElementById('tag-modal-title').textContent = 'Edit Tag';
    document.getElementById('tag-id').value = tagId;
    document.getElementById('tag-name').value = name;
    document.getElementById('tag-description').value = description;
    document.getElementById('tag-active').checked = active;
    createTagModal.classList.add('active');
}

async function handleSaveTag() {
    const tagId = document.getElementById('tag-id').value;
    const name = document.getElementById('tag-name').value;
    const description = document.getElementById('tag-description').value;
    const active = document.getElementById('tag-active').checked;

    try {
        saveTagBtn.disabled = true;

        let data;
        if (tagId) {
            // Edit existing tag
            data = await apiRequest(`/admin/tags/edit/${tagId}`, {
                method: 'PUT',
                body: JSON.stringify({ name, description, active })
            });
        } else {
            // Create new tag
            data = await apiRequest('/admin/tags/create', {
                method: 'POST',
                body: JSON.stringify({ name, description, active })
            });
        }

        if (data.success) {
            createTagModal.classList.remove('active');
            createTagForm.reset();
            loadTags();
            loadTagsFilter(); // Refresh tags filter dropdown
        } else {
            alert(tagId ? 'Failed to update tag' : 'Failed to create tag');
        }
    } catch (error) {
        alert(tagId ? 'Error updating tag' : 'Error creating tag');
    } finally {
        saveTagBtn.disabled = false;
    }
}

async function toggleTag(tagId, active) {
    try {
        const data = await apiRequest(`/admin/tags/edit/${tagId}`, {
            method: 'PUT',
            body: JSON.stringify({ active })
        });

        if (data.success) {
            loadTags();
        } else {
            alert('Failed to update tag');
        }
    } catch (error) {
        alert('Error updating tag');
    }
}

async function deleteTag(tagId) {
    if ( ! confirm('Are you sure you want to delete this tag?')) {
        return;
    }

    try {
        const data = await apiRequest(`/admin/tags/delete/${tagId}`, {
            method: 'DELETE'
        });

        if (data.success) {
            loadTags();
        } else {
            alert('Failed to delete tag');
        }
    } catch (error) {
        alert('Error deleting tag');
    }
}

// Games Management
async function loadGames() {
    gamesList.innerHTML = '<div class="loading">Loading games...</div>';

    try {
        const data = await apiRequest('/admin/games/list');

        if (data.success) {
            renderGames(data.data.games);
        } else {
            gamesList.innerHTML = '<div class="loading">Failed to load games</div>';
        }
    } catch (error) {
        gamesList.innerHTML = '<div class="loading">Error loading games</div>';
    }
}

function renderGames(games) {
    if (games.length === 0) {
        gamesList.innerHTML = '<div class="loading">No active games</div>';
        return;
    }

    gamesList.innerHTML = games.map(game => {
        const playerData = typeof game.player_data === 'string'
            ? JSON.parse(game.player_data)
            : game.player_data;

        const playerCount = playerData.players?.length || 0;
        const state = playerData.state || 'unknown';
        const created_at = new Date(game.created_at).toLocaleString();

        return `
            <div class="game-item" data-id="${game.game_id}">
                <div class="item-info">
                    <div class="item-title">
                        Game ID: ${game.game_id}
                    </div>
                    <div class="item-meta">
                        State: ${state} |
                        Players: ${playerCount} |
                        Started: ${created_at}
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-small btn-danger" onclick="deleteGame('${game.game_id}')">Delete</button>
                </div>
            </div>
        `;
    }).join('');
}

async function deleteGame(gameId) {
    if ( ! confirm('Are you sure you want to delete this game?')) {
        return;
    }

    try {
        const data = await apiRequest(`/admin/games/delete/${gameId}`, {
            method: 'DELETE'
        });

        if (data.success) {
            loadGames();
        } else {
            alert('Failed to delete game');
        }
    } catch (error) {
        alert('Error deleting game');
    }
}

// Utility Functions
function showError(element, message) {
    element.textContent = message;
    element.classList.add('active');
    setTimeout(() => {
        element.classList.remove('active');
    }, 5000);
}


// ===========================
// Tag Assignment Page
// ===========================

async function loadTagAssignmentPage() {
    const tagSelect = document.getElementById('tag-assignment-select');
    const cardsContainer = document.getElementById('tag-assignment-cards');

    // Clear existing options except the first one
    tagSelect.innerHTML = '<option value="">-- Select a tag --</option>';

    // Load all tags
    const response = await apiRequest('/api/admin/tags/list');
    if (response.success) {
        response.data.forEach(tag => {
            const option = document.createElement('option');
            option.value = tag.tag_id;
            option.textContent = `${tag.name} (W: ${tag.white_card_count}, B: ${tag.black_card_count})`;
            tagSelect.appendChild(option);
        });
    }

    // Add event listener for tag selection
    tagSelect.addEventListener('change', (e) => {
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
    const response = await apiRequest('/api/admin/cards/list?limit=500');
    if ( ! response.success) {
        cardsContainer.innerHTML = '<div class="error-message">Failed to load cards</div>';
        return;
    }

    const cards = response.data;
    if (cards.length === 0) {
        cardsContainer.innerHTML = '<div class="info-message">No cards found</div>';
        return;
    }

    // Clear container
    cardsContainer.innerHTML = '';

    // Render each card
    cards.forEach(card => {
        const cardItem = document.createElement('div');
        cardItem.className = 'card-item';
        cardItem.dataset.cardId = card.card_id;

        // Check if card has the selected tag
        const hasTag = card.tags && card.tags.some(tag => tag.tag_id === tagId);
        if (hasTag) {
            cardItem.classList.add('highlighted');
        }

        // Card type badge
        const typeBadge = document.createElement('div');
        typeBadge.className = `card-item-type ${card.type}`;
        typeBadge.textContent = card.type === 'white' ? 'White Card' : 'Black Card';

        // Card text
        const cardText = document.createElement('div');
        cardText.className = 'card-item-text';
        cardText.textContent = card.text;

        cardItem.appendChild(typeBadge);
        cardItem.appendChild(cardText);

        // Add click event to toggle tag
        cardItem.addEventListener('click', () => {
            toggleCardTag(card.card_id, tagId, cardItem);
        });

        cardsContainer.appendChild(cardItem);
    });
}

async function toggleCardTag(cardId, tagId, cardElement) {
    const hasTag = cardElement.classList.contains('highlighted');

    // Optimistically update UI
    cardElement.classList.toggle('highlighted');

    try {
        let response;
        if (hasTag) {
            // Remove tag
            response = await apiRequest(`/api/admin/cards/${cardId}/tags/${tagId}`, 'DELETE');
        } else {
            // Add tag
            response = await apiRequest(`/api/admin/cards/${cardId}/tags/${tagId}`, 'POST');
        }

        if (response.success) {
            showMessage(hasTag ? 'Tag removed from card' : 'Tag added to card', 'success');
        } else {
            // Revert UI on failure
            cardElement.classList.toggle('highlighted');
            showMessage(response.message || 'Failed to update card tag', 'error');
        }
    } catch (error) {
        // Revert UI on error
        cardElement.classList.toggle('highlighted');
        showMessage('Error updating card tag', 'error');
    }
}

// Initialize app when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
