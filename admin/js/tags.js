/**
 * Tags management module
 */

import { apiRequest } from './api.js';
import { escapeQuotes } from './utils.js';

// DOM elements (initialized in initTags)
let tagsList, createTagModal, createTagForm, saveTagBtn;

// Pagination state
let currentPage = 1;
let itemsPerPage = 50;
let allTags = [];

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
        const returnUrl = window.location.pathname + window.location.search;
        const createUrl = `/admin/edit-tag.html?return=${encodeURIComponent(returnUrl)}`;
        window.location.href = createUrl;
    });

    // Pagination
    document.querySelectorAll('.pagination-prev').forEach(btn => {
        btn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                renderTags(allTags);
            }
        });
    });

    document.querySelectorAll('.pagination-next').forEach(btn => {
        btn.addEventListener('click', () => {
            const totalPages = Math.ceil(allTags.length / itemsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                renderTags(allTags);
            }
        });
    });

    // Event delegation for tag action buttons
    tagsList.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button) return;

        const action = button.dataset.action;
        const tagId = parseInt(button.dataset.tagId);

        if (action === 'edit') {
            editTag(tagId);
        } else if (action === 'toggle') {
            const active = button.dataset.active === 'true';
            toggleTag(tagId, active);
        } else if (action === 'delete') {
            deleteTag(tagId);
        }
    });
}

/**
 * Load and display tags
 */
export async function loadTags() {
    tagsList.innerHTML = '<div class="loading">Loading tags...</div>';

    try {
        const data = await apiRequest('/tags/list');

        if (data.success) {
            allTags = data.data.tags;
            currentPage = 1;
            renderTags(allTags);
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
        updatePagination(0);
        return;
    }

    // Paginate
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const paginatedTags = tags.slice(startIndex, endIndex);

    tagsList.innerHTML = paginatedTags.map(tag => {
        const isActive = tag.active === 1 || tag.active === '1' || tag.active === true;
        return `
            <div class="tag-item" data-id="${tag.tag_id}">
                <div class="item-info">
                    <div class="item-title">
                        <span class="badge badge-${isActive ? 'active' : 'inactive'}">
                            ${isActive ? 'Active' : 'Inactive'}
                        </span>
                        <a href="/admin/tag-assignment.html?tag=${tag.tag_id}" class="tag-name-link" title="Manage tag assignments">
                            ${tag.name}
                        </a>
                    </div>
                    <div class="item-meta">
                        ${tag.description || 'No description'} |
                        Response Cards: ${tag.response_card_count || 0} |
                        Prompt Cards: ${tag.prompt_card_count || 0}
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-small btn-primary" data-action="edit" data-tag-id="${tag.tag_id}">Edit</button>
                    <button class="btn btn-small btn-secondary" data-action="toggle" data-tag-id="${tag.tag_id}" data-active="${!isActive}">
                        ${isActive ? 'Deactivate' : 'Activate'}
                    </button>
                    <button class="btn btn-small btn-danger" data-action="delete" data-tag-id="${tag.tag_id}">Delete</button>
                </div>
            </div>
        `;
    }).join('');

    updatePagination(tags.length);
}

function updatePagination(total) {
    const totalPages = Math.ceil(total / itemsPerPage);
    const pageText = `Page ${currentPage} of ${totalPages} (${total} tags)`;
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
 * Edit a tag - navigate to edit page
 */
export function editTag(tagId) {
    const returnUrl = window.location.pathname + window.location.search;
    const editUrl = `/admin/edit-tag.html?id=${tagId}&return=${encodeURIComponent(returnUrl)}`;
    window.location.href = editUrl;
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
