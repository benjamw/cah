/**
 * Packs management module
 */

import { apiRequest } from './api.js';
import { escapeQuotes } from './utils.js';

// DOM elements (initialized in initPacks)
let packsList, createPackModal, createPackForm, savePackBtn;
let selectAllPacksCheckbox, bulkActivateBtn, bulkDeactivateBtn, bulkControlsDiv;

// Pagination state
let currentPage = 1;
let itemsPerPage = 20;
let allPacks = [];

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
            if (currentPage > 1) {
                currentPage--;
                renderPacks(allPacks);
            }
        });
    });
    
    document.querySelectorAll('.pagination-next').forEach(btn => {
        btn.addEventListener('click', () => {
            const totalPages = Math.ceil(allPacks.length / itemsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                renderPacks(allPacks);
            }
        });
    });
}

/**
 * Load and display packs
 */
export async function loadPacks() {
    packsList.innerHTML = '<div class="loading">Loading packs...</div>';

    try {
        const data = await apiRequest('/packs/list-all');

        if (data.success) {
            allPacks = data.data.packs;
            currentPage = 1;
            renderPacks(allPacks);
        } else {
            packsList.innerHTML = '<div class="loading">Failed to load packs</div>';
        }
    } catch (error) {
        packsList.innerHTML = '<div class="loading">Error loading packs</div>';
    }
}

function renderPacks(packs) {
    if (packs.length === 0) {
        packsList.innerHTML = '<div class="loading">No packs found</div>';
        bulkControlsDiv.style.display = 'none';
        updatePagination(0);
        return;
    }

    // Show bulk controls if we have packs
    bulkControlsDiv.style.display = 'block';

    // Paginate
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const paginatedPacks = packs.slice(startIndex, endIndex);

    packsList.innerHTML = paginatedPacks.map(pack => {
        const releaseDate = pack.release_date ? new Date(pack.release_date).toLocaleDateString() : 'N/A';
        const version = pack.version ? (pack.version.toLowerCase().startsWith('v') ? pack.version : `v${pack.version}`) : '';
        const isActive = pack.active === 1 || pack.active === '1' || pack.active === true;
        
        return `
            <div class="pack-item" data-id="${pack.pack_id}">
                <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                    <input type="checkbox" class="pack-checkbox" data-pack-id="${pack.pack_id}" onchange="updateBulkActionButtons()">
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
                            Response Cards: ${pack.response_card_count || 0} |
                            Prompt Cards: ${pack.prompt_card_count || 0} |
                            Released: ${releaseDate}
                        </div>
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-small btn-primary" onclick="editPack(${pack.pack_id})">Edit</button>
                    <button class="btn btn-small btn-secondary" onclick="togglePack(${pack.pack_id}, ${!isActive})">
                        ${isActive ? 'Deactivate' : 'Activate'}
                    </button>
                    <button class="btn btn-small btn-danger" onclick="deletePack(${pack.pack_id})">Delete</button>
                </div>
            </div>
        `;
    }).join('');
    
    // Reset checkbox states
    selectAllPacksCheckbox.checked = false;
    updateBulkActionButtons();
    updatePagination(packs.length);
}

function updatePagination(total) {
    const totalPages = Math.ceil(total / itemsPerPage);
    const pageText = `Page ${currentPage} of ${totalPages} (${total} packs)`;
    const prevDisabled = currentPage <= 1;
    const nextDisabled = currentPage >= totalPages || total === 0;
    
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

    const mysqlReleaseDate = releaseDate ? releaseDate.replace('T', ' ') + ':00' : null;

    try {
        savePackBtn.disabled = true;

        let result;
        if (packId) {
            result = await apiRequest(`/admin/packs/edit/${packId}`, {
                method: 'PUT',
                body: JSON.stringify({ name, version, release_date: mysqlReleaseDate, data, active })
            });
        } else {
            result = await apiRequest('/admin/packs/create', {
                method: 'POST',
                body: JSON.stringify({ name, version, release_date: mysqlReleaseDate, data, active })
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
