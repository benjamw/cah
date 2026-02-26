/**
 * Packs management module
 */

import { apiRequest } from './api.js';
import { escapeQuotes } from './utils.js';
import { createStateManager } from './url-state.js';

// State schema for URL parameters
const STATE_SCHEMA = {
    page: { type: 'int', default: 1 },
    active: { type: 'string', default: '' },
    search: { type: 'string', default: '' },
    sort: { type: 'string', default: 'name' },
    order: { type: 'string', default: 'asc' },
    limit: { type: 'int', default: 50 }
};

// State defaults (values to exclude from URL if they match)
const STATE_DEFAULTS = {
    page: 1,
    active: '',
    search: '',
    sort: 'name',
    order: 'asc',
    limit: 50
};

// Create state manager
const stateManager = createStateManager(STATE_SCHEMA, { defaults: STATE_DEFAULTS });

// DOM elements (initialized in initPacks)
let packsList, createPackModal, createPackForm, savePackBtn;
let selectAllPacksCheckbox, bulkActivateBtn, bulkDeactivateBtn, bulkControlsDiv;
let packActiveFilter, packSearchFilter, packSortFilter, packSortOrderFilter, packLimitFilter;

/**
 * Initialize packs module DOM elements
 */
export function initPacks() {
    packsList = document.getElementById('packs-list');
    createPackModal = document.getElementById('create-pack-modal');
    createPackForm = document.getElementById('create-pack-form');
    savePackBtn = document.getElementById('save-pack-btn');
    selectAllPacksCheckbox = document.getElementById('select-all-packs');
    bulkActivateBtn = document.getElementById('bulk-activate-packs-btn');
    bulkDeactivateBtn = document.getElementById('bulk-deactivate-packs-btn');
    bulkControlsDiv = document.querySelector('.pack-bulk-controls');
    packActiveFilter = document.getElementById('pack-active-filter');
    packSearchFilter = document.getElementById('pack-search-filter');
    packSortFilter = document.getElementById('pack-sort-filter');
    packSortOrderFilter = document.getElementById('pack-sort-order-filter');
    packLimitFilter = document.getElementById('pack-limit-filter');
}

/**
 * Setup packs event listeners
 */
export function setupPacksListeners() {
    document.getElementById('create-pack-btn').addEventListener('click', () => {
        const returnUrl = window.location.pathname + window.location.search;
        const createUrl = `/admin/edit-pack.html?return=${encodeURIComponent(returnUrl)}`;
        window.location.href = createUrl;
    });

    // Apply filters button
    document.getElementById('apply-pack-filters-btn').addEventListener('click', () => {
        stateManager.set({
            active: packActiveFilter.value,
            search: packSearchFilter.value.trim(),
            sort: packSortFilter.value,
            order: packSortOrderFilter.value,
            limit: parseInt(packLimitFilter.value),
            page: 1
        });
        loadPacks();
    });

    // Search on Enter key
    packSearchFilter.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            document.getElementById('apply-pack-filters-btn').click();
        }
    });

    // Select all checkbox
    selectAllPacksCheckbox.addEventListener('change', (e) => {
        const checkboxes = document.querySelectorAll('.pack-checkbox');
        checkboxes.forEach(cb => cb.checked = e.target.checked);
        updateBulkActionButtons();
    });

    // Bulk action buttons
    bulkActivateBtn.addEventListener('click', () => bulkTogglePacks(true));
    bulkDeactivateBtn.addEventListener('click', () => bulkTogglePacks(false));

    // Pagination
    document.querySelectorAll('.pagination-prev').forEach(btn => {
        btn.addEventListener('click', () => {
            const state = stateManager.get();
            if (state.page > 1) {
                stateManager.setValue('page', state.page - 1);
                loadPacks();
            }
        });
    });

    document.querySelectorAll('.pagination-next').forEach(btn => {
        btn.addEventListener('click', () => {
            const state = stateManager.get();
            stateManager.setValue('page', state.page + 1);
            loadPacks();
        });
    });

    // Event delegation for pack action buttons and checkboxes
    packsList.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (button) {
            const action = button.dataset.action;
            const packId = parseInt(button.dataset.packId);

            if (action === 'edit') {
                editPack(packId);
            } else if (action === 'toggle') {
                const active = button.dataset.active === 'true';
                togglePack(packId, active);
            } else if (action === 'delete') {
                deletePack(packId);
            }
        }
    });

    // Event delegation for checkbox changes
    packsList.addEventListener('change', (e) => {
        if (e.target.classList.contains('pack-checkbox')) {
            updateBulkActionButtons();
        }
    });
}

/**
 * Restore filters from URL state
 */
export function restoreFiltersFromURL() {
    // Apply state to form elements
    stateManager.applyToForm({
        active: packActiveFilter,
        search: packSearchFilter,
        sort: packSortFilter,
        order: packSortOrderFilter,
        limit: packLimitFilter
    });
}

/**
 * Load and display packs with filters and sorting
 */
export async function loadPacks() {
    const state = stateManager.get();
    packsList.innerHTML = '<div class="loading">Loading packs...</div>';

    try {
        // Fetch all packs (API returns all, we'll filter and sort client-side)
        const data = await apiRequest('/packs/list-all');

        if (data.success) {
            let packs = data.data.packs;

            // Apply filters
            if (state.active !== '') {
                const activeVal = state.active === '1';
                packs = packs.filter(pack => (pack.active === 1 || pack.active === '1' || pack.active === true) === activeVal);
            }

            if (state.search) {
                const searchLower = state.search.toLowerCase();
                packs = packs.filter(pack =>
                    pack.name.toLowerCase().includes(searchLower) ||
                    (pack.version && pack.version.toLowerCase().includes(searchLower))
                );
            }

            // Apply sorting
            packs = sortPacks(packs, state.sort, state.order);

            // Render with pagination
            renderPacks(packs, state);
        } else {
            packsList.innerHTML = '<div class="loading">Failed to load packs</div>';
        }
    } catch (error) {
        packsList.innerHTML = '<div class="loading">Error loading packs</div>';
    }
}

/**
 * Sort packs by specified field and order
 */
function sortPacks(packs, sortBy, order) {
    const sorted = [...packs].sort((a, b) => {
        let aVal, bVal;

        switch (sortBy) {
            case 'name':
                aVal = a.name.toLowerCase();
                bVal = b.name.toLowerCase();
                break;
            case 'release_date':
                aVal = a.release_date ? new Date(a.release_date).getTime() : 0;
                bVal = b.release_date ? new Date(b.release_date).getTime() : 0;
                break;
            case 'white_cards':
                aVal = parseInt(a.response_card_count) || 0;
                bVal = parseInt(b.response_card_count) || 0;
                break;
            case 'black_cards':
                aVal = parseInt(a.prompt_card_count) || 0;
                bVal = parseInt(b.prompt_card_count) || 0;
                break;
            case 'total_cards':
                aVal = (parseInt(a.response_card_count) || 0) + (parseInt(a.prompt_card_count) || 0);
                bVal = (parseInt(b.response_card_count) || 0) + (parseInt(b.prompt_card_count) || 0);
                break;
            default:
                aVal = a.name.toLowerCase();
                bVal = b.name.toLowerCase();
        }

        if (aVal < bVal) return order === 'asc' ? -1 : 1;
        if (aVal > bVal) return order === 'asc' ? 1 : -1;
        return 0;
    });

    return sorted;
}

function renderPacks(packs, state) {
    if (packs.length === 0) {
        packsList.innerHTML = '<div class="loading">No packs found</div>';
        bulkControlsDiv.style.display = 'none';
        updatePagination(0, state);
        return;
    }

    // Show bulk controls if we have packs
    bulkControlsDiv.style.display = 'block';

    // Paginate
    const startIndex = (state.page - 1) * state.limit;
    const endIndex = startIndex + state.limit;
    const paginatedPacks = packs.slice(startIndex, endIndex);

    packsList.innerHTML = paginatedPacks.map(pack => {
        const releaseDate = pack.release_date ? new Date(pack.release_date).toLocaleDateString() : 'N/A';
        const version = pack.version ? (pack.version.toLowerCase().startsWith('v') ? pack.version : `v${pack.version}`) : '';
        const isActive = pack.active === 1 || pack.active === '1' || pack.active === true;
        const whiteCount = parseInt(pack.response_card_count) || 0;
        const blackCount = parseInt(pack.prompt_card_count) || 0;
        const totalCount = whiteCount + blackCount;

        return `
            <div class="pack-item" data-id="${pack.pack_id}">
                <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                    <input type="checkbox" class="pack-checkbox" data-pack-id="${pack.pack_id}">
                    <div class="item-info" style="flex: 1;">
                        <div class="item-title">
                            <span class="badge badge-${isActive ? 'active' : 'inactive'}">
                                ${isActive ? 'Active' : 'Inactive'}
                            </span>
                            <a href="/admin/cards.html?pack=${pack.pack_id}" class="pack-name-link" title="View cards in this pack">
                                ${pack.name} ${version}
                            </a>
                        </div>
                        <div class="item-meta">
                            White: ${whiteCount} |
                            Black: ${blackCount} |
                            Total: ${totalCount} |
                            Released: ${releaseDate}
                        </div>
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-small btn-primary" data-action="edit" data-pack-id="${pack.pack_id}">Edit</button>
                    <button class="btn btn-small btn-secondary" data-action="toggle" data-pack-id="${pack.pack_id}" data-active="${!isActive}">
                        ${isActive ? 'Deactivate' : 'Activate'}
                    </button>
                    <button class="btn btn-small btn-danger" data-action="delete" data-pack-id="${pack.pack_id}">Delete</button>
                </div>
            </div>
        `;
    }).join('');

    // Reset checkbox states
    selectAllPacksCheckbox.checked = false;
    updateBulkActionButtons();
    updatePagination(packs.length, state);
}

function updatePagination(total, state) {
    const totalPages = Math.ceil(total / state.limit);
    const pageText = `Page ${state.page} of ${totalPages} (${total} packs)`;
    const prevDisabled = state.page <= 1;
    const nextDisabled = state.page >= totalPages || total === 0;

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
 * Edit a pack - navigate to edit page
 */
export function editPack(packId) {
    const returnUrl = window.location.pathname + window.location.search;
    const editUrl = `/admin/edit-pack.html?id=${packId}&return=${encodeURIComponent(returnUrl)}`;
    window.location.href = editUrl;
}

async function handleSavePack() {
    const packId = document.getElementById('pack-id').value;
    const name = document.getElementById('pack-name').value;
    const version = document.getElementById('pack-version').value;
    const releaseDate = document.getElementById('pack-release-date').value;
    const data = document.getElementById('pack-data').value;
    const active = document.getElementById('pack-active').checked;

    try {
        savePackBtn.disabled = true;

        let result;
        if (packId) {
            result = await apiRequest(`/admin/packs/edit/${packId}`, {
                method: 'PUT',
                body: JSON.stringify({ name, version, release_date: releaseDate || null, data, active })
            });
        } else {
            result = await apiRequest('/admin/packs/create', {
                method: 'POST',
                body: JSON.stringify({ name, version, release_date: releaseDate || null, data, active })
            });
        }

        if (result.success) {
            createPackModal.classList.remove('active');
            createPackForm.reset();
            loadPacks();
        } else {
            alert(result.message || (packId ? 'Failed to update pack' : 'Failed to create pack'));
        }
    } catch (error) {
        alert(error.message || (packId ? 'Error updating pack' : 'Error creating pack'));
    } finally {
        savePackBtn.disabled = false;
    }
}

/**
 * Toggle pack active status
 */
export async function togglePack(packId, active) {
    try {
        const packItem = document.querySelector(`.pack-item[data-id="${packId}"]`);
        if (packItem) {
            const buttons = packItem.querySelectorAll('button');
            buttons.forEach(btn => btn.disabled = true);
        }

        const data = await apiRequest(`/admin/packs/toggle/${packId}`, {
            method: 'PUT',
            body: JSON.stringify({ active })
        });

        if (data.success) {
            loadPacks();
        } else {
            alert(data.message || 'Failed to toggle pack status');
            if (packItem) {
                const buttons = packItem.querySelectorAll('button');
                buttons.forEach(btn => btn.disabled = false);
            }
        }
    } catch (error) {
        alert(error.message || 'Error toggling pack status');
        const packItem = document.querySelector(`.pack-item[data-id="${packId}"]`);
        if (packItem) {
            const buttons = packItem.querySelectorAll('button');
            buttons.forEach(btn => btn.disabled = false);
        }
    }
}

/**
 * Delete a pack
 */
export async function deletePack(packId) {
    if ( ! confirm('Are you sure you want to delete this pack? This will remove all card associations.')) {
        return;
    }

    try {
        const data = await apiRequest(`/admin/packs/delete/${packId}`, {
            method: 'DELETE'
        });

        if (data.success) {
            loadPacks();
        } else {
            alert(data.message || 'Failed to delete pack');
        }
    } catch (error) {
        alert(error.message || 'Error deleting pack');
    }
}

/**
 * Update visibility of bulk action buttons based on checkbox selection
 */
export function updateBulkActionButtons() {
    const selectedCheckboxes = document.querySelectorAll('.pack-checkbox:checked');
    const hasSelection = selectedCheckboxes.length > 0;

    bulkActivateBtn.style.display = hasSelection ? 'inline-block' : 'none';
    bulkDeactivateBtn.style.display = hasSelection ? 'inline-block' : 'none';
}

/**
 * Bulk toggle pack active status (idempotent)
 */
async function bulkTogglePacks(active) {
    const selectedCheckboxes = document.querySelectorAll('.pack-checkbox:checked');

    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one pack');
        return;
    }

    const packIds = Array.from(selectedCheckboxes).map(cb => parseInt(cb.dataset.packId));
    const action = active ? 'activate' : 'deactivate';

    if ( ! confirm(`Are you sure you want to ${action} ${packIds.length} pack(s)?`)) {
        return;
    }

    try {
        // Disable bulk buttons during request
        bulkActivateBtn.disabled = true;
        bulkDeactivateBtn.disabled = true;

        const data = await apiRequest('/admin/packs/bulk-toggle', {
            method: 'PUT',
            body: JSON.stringify({
                pack_ids: packIds,
                active: active
            })
        });

        if (data.success) {
            // Success - reload packs without scrolling to top
            await loadPacks();
            alert(`Successfully ${action}d ${data.data.updated_count} pack(s)`);
        } else {
            alert(data.message || `Failed to ${action} packs`);
        }
    } catch (error) {
        alert(error.message || `Error ${action}ing packs`);
    } finally {
        bulkActivateBtn.disabled = false;
        bulkDeactivateBtn.disabled = false;
    }
}
