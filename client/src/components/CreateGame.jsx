import { useState, useEffect } from 'react';
import { createGame, getTags } from '../utils/api';

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

  // Filter out dangerous characters that could be used for attacks or cause display issues
  const handleNameChange = (e) => {
    let value = e.target.value;
    
    // Remove control characters (0x00-0x1F, 0x7F-0x9F)
    // Remove zero-width and invisible characters (U+200B-U+200F)
    // Remove bidirectional text override characters (U+202A-U+202E)
    // Remove line/paragraph separators (U+2028-U+2029)
    // Remove byte order mark (U+FEFF)
    value = value.replace(/[\x00-\x1F\x7F-\x9F\u200B-\u200F\u202A-\u202E\u2028\u2029\uFEFF]/g, '');
    
    setPlayerName(value);
  };

  useEffect(() => {
    // Load available tags
    setTagsLoading(true);
    getTags()
      .then((response) => {
        if (response.success && response.data && response.data.tags) {
          const tagList = response.data.tags;
          setTags(tagList);
          // Select all tags by default
          setSelectedTags(tagList.map((tag) => tag.tag_id));
        }
      })
      .catch((err) => {
        console.error('Failed to load tags:', err);
      })
      .finally(() => {
        setTagsLoading(false);
      });
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const response = await createGame({
        player_name: playerName,
        tag_ids: selectedTags.length > 0 ? selectedTags : null,
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
      prev.includes(tagId)
        ? prev.filter((id) => id !== tagId)
        : [...prev, tagId]
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
              {tags.map((tag) => (
                <button
                  key={tag.tag_id}
                  type="button"
                  className={`tag-button ${
                    selectedTags.includes(tag.tag_id) ? 'selected' : ''
                  }`}
                  onClick={() => toggleTag(tag.tag_id)}
                  disabled={loading}
                >
                  {tag.name}
                </button>
              ))}
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
