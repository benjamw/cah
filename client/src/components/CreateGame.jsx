import { useState, useEffect, useMemo } from 'react';
import { createGame, getTags, getPacks, previewCreateGame } from '../utils/api';
import { getCachedTags, setCachedTags } from '../utils/tagCache';

const TAG_GROUP_ORDER = ['rating', 'advisory', 'other', 'source', 'location'];
const TAG_GROUP_LABELS = {
  rating: 'Rating',
  advisory: 'Advisory',
  other: 'Other',
  source: 'Source',
  location: 'Location',
};

function formatTypeLabel(type) {
  if (!type) {
    return 'Uncategorized';
  }

  return String(type)
    .trim()
    .toLowerCase()
    .split(/[\s_-]+/)
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

function CreateGame({ onGameCreated, onSwitchToJoin, playerName, setPlayerName }) {
  const [maxPlayers, setMaxPlayers] = useState(20);
  const [maxScore, setMaxScore] = useState(8);
  const [handSize, setHandSize] = useState(10);
  const [randoEnabled, setRandoEnabled] = useState(true);
  const [allowLateJoin, setAllowLateJoin] = useState(true);
  const [tags, setTags] = useState([]);
  const [selectedTags, setSelectedTags] = useState([]);
  const [tagsLoading, setTagsLoading] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [packs, setPacks] = useState([]);
  const [selectedPacks, setSelectedPacks] = useState([]);
  const [packsLoading, setPacksLoading] = useState(true);
  const [packSearch, setPackSearch] = useState('');

  // Filter out dangerous characters that could be used for attacks or cause display issues
  const handleNameChange = (e) => {
    let value = e.target.value;

    // Remove control characters (0x00-0x1F, 0x7F-0x9F)
    // Remove zero-width and invisible characters (U+200B-U+200F)
    // Remove bidirectional text override characters (U+202A-U+202E)
    // Remove line/paragraph separators (U+2028-U+2029)
    // Remove byte order mark (U+FEFF)
    // eslint-disable-next-line no-control-regex
    value = value.replace(/[\x00-\x1F\x7F-\x9F\u200B-\u200F\u202A-\u202E\u2028\u2029\uFEFF]/g, '');

    setPlayerName(value);
  };

  useEffect(() => {
    // Load available tags
    setTagsLoading(true);

    // Check cache first
    const cachedTags = getCachedTags();
    if (cachedTags) {
      setTags(cachedTags);
      // Select all tags by default
      setSelectedTags(cachedTags.map((tag) => tag.tag_id));
      setTagsLoading(false);
      return;
    }

    // Cache miss - fetch from API
    getTags()
      .then((response) => {
        if (response.success && response.data && response.data.tags) {
          const tagList = response.data.tags;
          setTags(tagList);
          // Select all tags by default
          setSelectedTags(tagList.map((tag) => tag.tag_id));
          // Store in cache for future use
          setCachedTags(tagList);
        }
      })
      .catch((err) => {
        console.error('Failed to load tags:', err);
      })
      .finally(() => {
        setTagsLoading(false);
      });
  }, []);

  useEffect(() => {
    // Load active packs for pack-level card filtering
    setPacksLoading(true);

    getPacks()
      .then((response) => {
        if (response.success && response.data && response.data.packs) {
          const activePacks = response.data.packs;
          setPacks(activePacks);
          // All active packs are selected by default.
          setSelectedPacks(activePacks.map((pack) => pack.pack_id));
        }
      })
      .catch((err) => {
        console.error('Failed to load packs:', err);
      })
      .finally(() => {
        setPacksLoading(false);
      });
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      if (selectedTags.length === 0) {
        setError('Please select at least one card tag before creating a game.');
        return;
      }

      if (selectedPacks.length === 0) {
        setError('Please select at least one card pack before creating a game.');
        return;
      }

      const preview = await previewCreateGame(selectedTags, selectedPacks);
      if (!preview.success) {
        setError(preview.message || 'Unable to validate selected card pool');
        return;
      }

      const cardCounts = preview.data?.card_counts;
      const responseCards = cardCounts?.response_cards ?? 0;
      const promptCards = cardCounts?.prompt_cards ?? 0;

      if (responseCards === 0 || promptCards === 0) {
        setError(
          `Cannot create game with current selection: ${responseCards} white cards and ${promptCards} black cards. `
            + 'Please choose tags that include at least one of each.'
        );
        return;
      }

      const lowWhiteThreshold = cardCounts?.warning_thresholds?.response_cards ?? 200;
      const lowBlackThreshold = cardCounts?.warning_thresholds?.prompt_cards ?? 25;
      const isLowPool = responseCards < lowWhiteThreshold || promptCards < lowBlackThreshold;

      if (isLowPool) {
        const warningMessage =
          `Warning: Your selected cards include ${responseCards} white cards and ${promptCards} black cards.\n\n`
          + `Recommended minimums are ${lowWhiteThreshold} white and ${lowBlackThreshold} black cards.\n\n`
          + 'You can continue, but the game may run out of cards sooner. Create game anyway?';

        if (!window.confirm(warningMessage)) {
          return;
        }
      }

      const response = await createGame({
        player_name: playerName,
        tag_ids: selectedTags.length > 0 ? selectedTags : null,
        pack_ids: selectedPacks.length > 0 ? selectedPacks : null,
        settings: {
          max_players: maxPlayers,
          max_score: maxScore,
          hand_size: handSize,
          rando_enabled: randoEnabled,
          allow_late_join: allowLateJoin,
        },
      });

      if (response.success) {
        onGameCreated({
          gameId: response.data.game_id,
          playerId: response.data.player_id,
          playerName: playerName,
          isCreator: true,
        });
      } else {
        setError(response.message || 'Failed to create game');
      }
    } catch (err) {
      setError(err.message || 'Failed to create game');
    } finally {
      setLoading(false);
    }
  };

  const toggleTag = (tagId) => {
    setSelectedTags((prev) =>
      prev.includes(tagId) ? prev.filter((id) => id !== tagId) : [...prev, tagId]
    );
  };

  const selectAllTags = () => {
    setSelectedTags(tags.map((tag) => tag.tag_id));
  };

  const selectNoTags = () => {
    setSelectedTags([]);
  };

  const invertTagSelection = () => {
    setSelectedTags((prev) => {
      const allTagIds = tags.map((tag) => tag.tag_id);
      return allTagIds.filter((id) => !prev.includes(id));
    });
  };

  const togglePack = (packId) => {
    setSelectedPacks((prev) =>
      prev.includes(packId) ? prev.filter((id) => id !== packId) : [...prev, packId]
    );
  };

  const selectAllPacks = () => {
    setSelectedPacks(packs.map((pack) => pack.pack_id));
  };

  const selectNoPacks = () => {
    setSelectedPacks([]);
  };

  const invertPackSelection = () => {
    setSelectedPacks((prev) => {
      const allPackIds = packs.map((pack) => pack.pack_id);
      return allPackIds.filter((id) => !prev.includes(id));
    });
  };

  const groupedTags = useMemo(() => {
    const buckets = new Map();

    for (const tag of tags) {
      const typeKey =
        String(tag.type || '')
          .trim()
          .toLowerCase() || 'uncategorized';
      if (!buckets.has(typeKey)) {
        buckets.set(typeKey, []);
      }
      buckets.get(typeKey).push(tag);
    }

    for (const groupTags of buckets.values()) {
      groupTags.sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }

    const groups = [];

    for (const key of TAG_GROUP_ORDER) {
      if (buckets.has(key)) {
        groups.push({
          key,
          label: TAG_GROUP_LABELS[key],
          tags: buckets.get(key),
        });
        buckets.delete(key);
      }
    }

    const remaining = Array.from(buckets.entries()).sort((a, b) =>
      formatTypeLabel(a[0]).localeCompare(formatTypeLabel(b[0]))
    );

    for (const [key, groupTags] of remaining) {
      groups.push({
        key,
        label: formatTypeLabel(key),
        tags: groupTags,
      });
    }

    return groups;
  }, [tags]);

  const filteredPacks = useMemo(() => {
    const search = packSearch.trim().toLowerCase();
    if (!search) {
      return packs;
    }

    return packs.filter((pack) => {
      const versionText = pack.version ? ` ${pack.version}` : '';
      const haystack = `${pack.name}${versionText}`.toLowerCase();
      return haystack.includes(search);
    });
  }, [packs, packSearch]);

  return (
    <div className="create-game">
      <h1>Create New Game</h1>

      <form onSubmit={handleSubmit} className="game-form">
        <div className="form-group">
          <label htmlFor="name">Your Name</label>
          <input
            type="text"
            id="name"
            value={playerName}
            onChange={handleNameChange}
            placeholder="Enter your name"
            required
            maxLength={50}
            disabled={loading}
          />
        </div>

        <div className="form-group">
          <label htmlFor="maxPlayers">Max Players (3-20)</label>
          <input
            type="number"
            id="maxPlayers"
            value={maxPlayers}
            onChange={(e) => setMaxPlayers(parseInt(e.target.value, 10))}
            min="3"
            max="20"
            required
            disabled={loading}
          />
        </div>

        <div className="form-group">
          <label htmlFor="maxScore">Points to Win</label>
          <input
            type="number"
            id="maxScore"
            value={maxScore}
            onChange={(e) => setMaxScore(parseInt(e.target.value, 10))}
            min="1"
            max="50"
            required
            disabled={loading}
          />
        </div>

        <div className="form-group">
          <label htmlFor="handSize">Hand Size</label>
          <input
            type="number"
            id="handSize"
            value={handSize}
            onChange={(e) => setHandSize(parseInt(e.target.value, 10))}
            min="5"
            max="15"
            required
            disabled={loading}
          />
        </div>

        <div className="form-group checkbox-group">
          <label>
            <input
              type="checkbox"
              checked={randoEnabled}
              onChange={(e) => setRandoEnabled(e.target.checked)}
              disabled={loading}
            />
            <span>Enable Rando Cardrissian (AI player)</span>
          </label>
        </div>

        <div className="form-group checkbox-group">
          <label>
            <input
              type="checkbox"
              checked={allowLateJoin}
              onChange={(e) => setAllowLateJoin(e.target.checked)}
              disabled={loading}
            />
            <span>Allow players to join after game starts</span>
          </label>
        </div>

        {tagsLoading ? (
          <div className="form-group">
            <label>Card Tags</label>
            <div className="loading">Loading tags...</div>
          </div>
        ) : tags.length > 0 ? (
          <div className="form-group">
            <label>Card Tags</label>
            <small className="form-hint">
              Select the tags to filter which cards are used in the game
            </small>
            <div className="tag-controls">
              <button
                type="button"
                className="btn-tag-control"
                onClick={selectAllTags}
                disabled={loading}
              >
                Check All
              </button>
              <button
                type="button"
                className="btn-tag-control"
                onClick={selectNoTags}
                disabled={loading}
              >
                Check None
              </button>
              <button
                type="button"
                className="btn-tag-control"
                onClick={invertTagSelection}
                disabled={loading}
              >
                Invert
              </button>
            </div>
            <div className="tag-selection-info">
              {selectedTags.length} of {tags.length} selected
            </div>
            <div className="tag-list">
              {groupedTags.map((group) => (
                <div key={group.key} className="tag-group">
                  <h4 className="tag-group-title">{group.label}</h4>
                  <div className="tag-group-list">
                    {group.tags.map((tag) => (
                      <button
                        key={tag.tag_id}
                        type="button"
                        className={`tag-button ${
                          selectedTags.includes(tag.tag_id) ? 'selected' : ''
                        }`}
                        onClick={() => toggleTag(tag.tag_id)}
                        disabled={loading}
                      >
                        {tag.name} ({tag.response_card_count || 0}W / {tag.prompt_card_count || 0}B)
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        ) : null}

        {packsLoading ? (
          <div className="form-group">
            <label>Card Packs</label>
            <div className="loading">Loading packs...</div>
          </div>
        ) : packs.length > 0 ? (
          <div className="form-group">
            <label>Card Packs</label>
            <small className="form-hint">
              Click packs to add/remove them from the game card pool
            </small>
            <div className="tag-controls">
              <button
                type="button"
                className="btn-tag-control"
                onClick={selectAllPacks}
                disabled={loading}
              >
                Check All
              </button>
              <button
                type="button"
                className="btn-tag-control"
                onClick={selectNoPacks}
                disabled={loading}
              >
                Check None
              </button>
              <button
                type="button"
                className="btn-tag-control"
                onClick={invertPackSelection}
                disabled={loading}
              >
                Invert
              </button>
            </div>
            <div className="tag-selection-info">
              {selectedPacks.length} of {packs.length} selected
            </div>
            <input
              type="text"
              className="pack-search-input"
              placeholder="Search packs..."
              value={packSearch}
              onChange={(e) => setPackSearch(e.target.value)}
              disabled={loading}
            />
            <div className="pack-selection-box" role="listbox" aria-multiselectable="true">
              {filteredPacks.length > 0 ? (
                filteredPacks.map((pack) => {
                  const selected = selectedPacks.includes(pack.pack_id);
                  const versionText = pack.version ? ` ${pack.version}` : '';
                  return (
                    <button
                      key={pack.pack_id}
                      type="button"
                      className={`pack-row-button ${selected ? 'selected' : ''}`}
                      onClick={() => togglePack(pack.pack_id)}
                      disabled={loading}
                      aria-selected={selected}
                    >
                      <span>{pack.name}{versionText}</span>
                      <span className="pack-row-count">
                        {pack.response_card_count || 0}W / {pack.prompt_card_count || 0}B
                      </span>
                    </button>
                  );
                })
              ) : (
                <div className="pack-empty-state">No packs match your search.</div>
              )}
            </div>
          </div>
        ) : null}

        {error && <div className="error-message">{error}</div>}

        <button type="submit" className="btn btn-primary" disabled={loading}>
          {loading ? 'Creating...' : 'Create Game'}
        </button>
      </form>

      <button
        type="button"
        className="btn btn-secondary"
        onClick={onSwitchToJoin}
        disabled={loading}
      >
        Back to Join Game
      </button>
    </div>
  );
}

export default CreateGame;
