import { useState, useEffect } from 'react';
import { createGame, getTags } from '../utils/api';

function CreateGame({ onGameCreated, onSwitchToJoin }) {
  const [name, setName] = useState('');
  const [maxPlayers, setMaxPlayers] = useState(20);
  const [maxScore, setMaxScore] = useState(8);
  const [handSize, setHandSize] = useState(10);
  const [randoEnabled, setRandoEnabled] = useState(true);
  const [allowLateJoin, setAllowLateJoin] = useState(true);
  const [tags, setTags] = useState([]);
  const [selectedTags, setSelectedTags] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // Filter out dangerous characters that could be used for XSS
  const handleNameChange = (e) => {
    const value = e.target.value;
    // Allow all visible ASCII except: < > & " \ / ; | ` { }
    const filtered = value.replace(/[<>&"\\\/;|`{}]/g, '');
    setName(filtered);
  };

  useEffect(() => {
    // Load available tags
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
      });
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const response = await createGame({
        player_name: name,
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
          playerName: name,
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
            value={name}
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

        {tags.length > 0 && (
          <div className="form-group">
            <label>Card Packs</label>
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
            <small className="form-hint">
              Select which card packs to include in the game
            </small>
          </div>
        )}

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
