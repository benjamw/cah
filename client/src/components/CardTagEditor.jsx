import { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faXmark, faPlus, faTimes, faTag, faSearch } from '@fortawesome/free-solid-svg-icons';
import { getTags, addCardTag, removeCardTag } from '../utils/api';
import { getCachedTags, setCachedTags } from '../utils/tagCache';

function CardTagEditor({ card, onClose, onTagsUpdated }) {
  const [availableTags, setAvailableTags] = useState([]);
  const [cardTags, setCardTags] = useState(card.tags || []);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [updating, setUpdating] = useState(null);
  const [searchQuery, setSearchQuery] = useState('');

  useEffect(() => {
    loadTags();
  }, []);

  const loadTags = async () => {
    setLoading(true);
    
    // Check cache first
    const cachedTags = getCachedTags();
    if (cachedTags) {
      setAvailableTags(cachedTags);
      setLoading(false);
      return;
    }
    
    // Cache miss - fetch from API
    try {
      const response = await getTags();
      if (response.success && response.data && response.data.tags) {
        const tags = response.data.tags;
        setAvailableTags(tags);
        // Store in cache for future use
        setCachedTags(tags);
      } else {
        setError('Failed to load tags');
      }
    } catch (err) {
      setError('Error loading tags');
      console.error('Error loading tags:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleAddTag = async (tagId) => {
    setUpdating(tagId);
    setError('');
    
    try {
      const response = await addCardTag(card.card_id, tagId);
      if (response.success) {
        // Update local state with the new tags from the response
        setCardTags(response.data.tags || []);
        
        // Notify parent component of the update
        if (onTagsUpdated) {
          onTagsUpdated(card.card_id, response.data.tags || []);
        }
      } else {
        setError(response.message || 'Failed to add tag');
      }
    } catch (err) {
      setError('Error adding tag');
      console.error('Error adding tag:', err);
    } finally {
      setUpdating(null);
    }
  };

  const handleRemoveTag = async (tagId) => {
    setUpdating(tagId);
    setError('');
    
    try {
      const response = await removeCardTag(card.card_id, tagId);
      if (response.success) {
        // Update local state with the new tags from the response
        setCardTags(response.data.tags || []);
        
        // Notify parent component of the update
        if (onTagsUpdated) {
          onTagsUpdated(card.card_id, response.data.tags || []);
        }
      } else {
        setError(response.message || 'Failed to remove tag');
      }
    } catch (err) {
      setError('Error removing tag');
      console.error('Error removing tag:', err);
    } finally {
      setUpdating(null);
    }
  };

  // Get tag IDs that are currently on the card
  const cardTagIds = cardTags.map(t => t.tag_id);

  // Filter available tags based on search query
  const filteredTags = availableTags.filter(tag => 
    tag.name.toLowerCase().includes(searchQuery.toLowerCase())
  );

  // Separate tags into those on the card and those available to add
  const tagsOnCard = filteredTags.filter(tag => cardTagIds.includes(tag.tag_id));
  const tagsToAdd = filteredTags.filter(tag => ! cardTagIds.includes(tag.tag_id));

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-content tag-editor-modal" onClick={(e) => e.stopPropagation()}>
        <div className="tag-editor-header">
          <h3>
            <FontAwesomeIcon icon={faTag} /> Edit Card Tags
          </h3>
          <button
            className="modal-close-btn"
            onClick={onClose}
            aria-label="Close"
          >
            <FontAwesomeIcon icon={faXmark} />
          </button>
        </div>

        <div className="tag-editor-card-preview">
          <div className="card card-response card-preview">
            <div className="card-content-small">
              {card.copy}
            </div>
          </div>
        </div>

        <div className="tag-editor-done-button-top">
          <button className="btn btn-primary" onClick={onClose}>
            Done
          </button>
        </div>

        {error && (
          <div className="error-message">{error}</div>
        )}

        {loading ? (
          <div className="loading">Loading tags...</div>
        ) : (
          <>
            <div className="tag-search-box">
              <FontAwesomeIcon icon={faSearch} className="tag-search-icon" />
              <input
                type="text"
                className="tag-search-input"
                placeholder="Search tags..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              {searchQuery && (
                <button
                  className="tag-search-clear"
                  onClick={() => setSearchQuery('')}
                  aria-label="Clear search"
                >
                  <FontAwesomeIcon icon={faTimes} />
                </button>
              )}
            </div>

            <div className="tag-editor-section">
              <h4>Current Tags ({tagsOnCard.length})</h4>
              {tagsOnCard.length === 0 ? (
                <p className="tag-editor-empty">No tags on this card yet</p>
              ) : (
                <div className="tag-chip-list">
                  {tagsOnCard.map((tag) => (
                    <div key={tag.tag_id} className="tag-chip tag-chip-active">
                      <span className="tag-chip-name">{tag.name}</span>
                      <button
                        className="tag-chip-remove"
                        onClick={() => handleRemoveTag(tag.tag_id)}
                        disabled={updating === tag.tag_id}
                        aria-label="Remove tag"
                      >
                        {updating === tag.tag_id ? '...' : <FontAwesomeIcon icon={faTimes} />}
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="tag-editor-section">
              <h4>Add Tags ({tagsToAdd.length} available)</h4>
              {tagsToAdd.length === 0 ? (
                <p className="tag-editor-empty">
                  {searchQuery ? 'No matching tags found' : 'All tags are already on this card'}
                </p>
              ) : (
                <div className="tag-chip-list">
                  {tagsToAdd.map((tag) => (
                    <button
                      key={tag.tag_id}
                      className="tag-chip tag-chip-add"
                      onClick={() => handleAddTag(tag.tag_id)}
                      disabled={updating === tag.tag_id}
                    >
                      <FontAwesomeIcon icon={faPlus} className="tag-chip-icon" />
                      <span className="tag-chip-name">{tag.name}</span>
                      {updating === tag.tag_id && <span className="tag-chip-loading">...</span>}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}

export default CardTagEditor;
