/**
 * Packs management module
 */

import { apiRequest } from './api.js';
import { escapeQuotes } from './utils.js';

// DOM elements (initialized in initPacks)
let packsList, createPackModal, createPackForm, savePackBtn;

/**
 * Initialize packs module DOM elements
 */
export function initPacks() {
    packsList = document.getElementById('packs-list');
    createPackModal = document.getElementById('create-pack-modal');
    createPackForm = document.getElementById('create-pack-form');
    savePackBtn = document.getElementById('save-pack-btn');
}

/**
 * Setup packs event listeners
 */
export function setupPacksListeners() {
    document.getElementById('create-pack-btn').addEventListener('click', () => {
        document.getElementById('pack-modal-title').textContent = 'Create Pack';
        document.getElementById('pack-id').value = '';
        document.getElementById('pack-name').value = '';
        document.getElementById('pack-version').value = '';
        document.getElementById('pack-release-date').value = '';
        document.getElementById('pack-data').value = '';
        document.getElementById('pack-active').checked = true;
        createPackModal.classList.add('active');
    });

    savePackBtn.addEventListener('click', handleSavePack);
}

/**
 * Load and display packs
 */
export async function loadPacks() {
    packsList.innerHTML = '<div class="loading">Loading packs...</div>';

    try {
        const data = await apiRequest('/packs/list-all');

        if (data.success) {
            renderPacks(data.data.packs);
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
        return;
    }

    packsList.innerHTML = packs.map(pack => {
        const releaseDate = pack.release_date ? new Date(pack.release_date).toLocaleDateString() : 'N/A';
        const version = pack.version ? (pack.version.toLowerCase().startsWith('v') ? pack.version : `v${pack.version}`) : '';
        const isActive = pack.active === 1 || pack.active === '1' || pack.active === true;
        
        return `
            <div class="pack-item" data-id="${pack.pack_id}">
                <div class="item-info">
                    <div class="item-title">
                        <span class="badge badge-${isActive ? 'active' : 'inactive'}">
                            ${isActive ? 'Active' : 'Inactive'}
                        </span>
                        ${pack.name} ${version}
                    </div>
                    <div class="item-meta">
                        Response Cards: ${pack.response_card_count || 0} |
                        Prompt Cards: ${pack.prompt_card_count || 0} |
                        Released: ${releaseDate}
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-small btn-primary" onclick="editPack(${pack.pack_id}, '${escapeQuotes(pack.name)}', '${escapeQuotes(pack.version || '')}', '${pack.release_date || ''}', '${escapeQuotes(pack.data || '')}', ${isActive})">Edit</button>
                    <button class="btn btn-small btn-secondary" onclick="togglePack(${pack.pack_id}, ${!isActive})">
                        ${isActive ? 'Deactivate' : 'Activate'}
                    </button>
                    <button class="btn btn-small btn-danger" onclick="deletePack(${pack.pack_id})">Delete</button>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Edit a pack - opens modal with pack data
 */
export function editPack(packId, name, version, releaseDate, data, active) {
    document.getElementById('pack-modal-title').textContent = 'Edit Pack';
    document.getElementById('pack-id').value = packId;
    document.getElementById('pack-name').value = name;
    document.getElementById('pack-version').value = version;
    
    if (releaseDate && releaseDate !== 'null') {
        const date = new Date(releaseDate);
        const localDatetime = date.toISOString().slice(0, 16);
        document.getElementById('pack-release-date').value = localDatetime;
    } else {
        document.getElementById('pack-release-date').value = '';
    }
    
    document.getElementById('pack-data').value = data === 'null' ? '' : data;
    document.getElementById('pack-active').checked = active;
    createPackModal.classList.add('active');
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
