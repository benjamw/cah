/**
 * Tags management module
 */

import { apiRequest } from './api.js';
import { escapeQuotes } from './utils.js';

// DOM elements (initialized in initTags)
let tagsList, createTagModal, createTagForm, saveTagBtn;

/**
 * Initialize tags module DOM elements
 */
export function initTags() {
    tagsList = document.getElementById('tags-list');
    createTagModal = document.getElementById('create-tag-modal');
    createTagForm = document.getElementById('create-tag-form');
    saveTagBtn = document.getElementById('save-tag-btn');
}

/**
 * Setup tags event listeners
 */
export function setupTagsListeners() {
    document.getElementById('create-tag-btn').addEventListener('click', () => {
        document.getElementById('tag-modal-title').textContent = 'Create Tag';
        document.getElementById('tag-id').value = '';
        document.getElementById('tag-name').value = '';
        document.getElementById('tag-description').value = '';
        document.getElementById('tag-active').checked = true;
        createTagModal.classList.add('active');
    });

    saveTagBtn.addEventListener('click', handleSaveTag);
}

/**
 * Load and display tags
 */
export async function loadTags() {
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

    tagsList.innerHTML = tags.map(tag => {
        const isActive = tag.active === 1 || tag.active === '1' || tag.active === true;
        return `
            <div class="tag-item" data-id="${tag.tag_id}">
                <div class="item-info">
                    <div class="item-title">
                        <span class="badge badge-${isActive ? 'active' : 'inactive'}">
                            ${isActive ? 'Active' : 'Inactive'}
                        </span>
                        ${tag.name}
                    </div>
                    <div class="item-meta">
                        ${tag.description || 'No description'} |
                        Response Cards: ${tag.response_card_count || 0} |
                        Prompt Cards: ${tag.prompt_card_count || 0}
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-small btn-primary" onclick="editTag(${tag.tag_id}, '${escapeQuotes(tag.name)}', '${escapeQuotes(tag.description || '')}', ${isActive})">Edit</button>
                    <button class="btn btn-small btn-secondary" onclick="toggleTag(${tag.tag_id}, ${!isActive})">
                        ${isActive ? 'Deactivate' : 'Activate'}
                    </button>
                    <button class="btn btn-small btn-danger" onclick="deleteTag(${tag.tag_id})">Delete</button>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Edit a tag - opens modal with tag data
 */
export function editTag(tagId, name, description, active) {
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
            data = await apiRequest(`/admin/tags/edit/${tagId}`, {
                method: 'PUT',
                body: JSON.stringify({ name, description, active })
            });
        } else {
            data = await apiRequest('/admin/tags/create', {
                method: 'POST',
                body: JSON.stringify({ name, description, active })
            });
        }

        if (data.success) {
            createTagModal.classList.remove('active');
            createTagForm.reset();
            loadTags();
            // Refresh tags filter in cards section
            const { loadTagsFilter } = await import('./cards.js');
            loadTagsFilter();
        } else {
            alert(data.message || (tagId ? 'Failed to update tag' : 'Failed to create tag'));
        }
    } catch (error) {
        alert(error.message || (tagId ? 'Error updating tag' : 'Error creating tag'));
    } finally {
        saveTagBtn.disabled = false;
    }
}

/**
 * Toggle tag active status
 */
export async function toggleTag(tagId, active) {
    try {
        const data = await apiRequest(`/admin/tags/edit/${tagId}`, {
            method: 'PUT',
            body: JSON.stringify({ active })
        });

        if (data.success) {
            loadTags();
        } else {
            alert(data.message || 'Failed to update tag');
        }
    } catch (error) {
        alert(error.message || 'Error updating tag');
    }
}

/**
 * Delete a tag
 */
export async function deleteTag(tagId) {
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
            alert(data.message || 'Failed to delete tag');
        }
    } catch (error) {
        alert(error.message || 'Error deleting tag');
    }
}
