import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRobot, faCrown } from '@fortawesome/free-solid-svg-icons';

function GameEnd({ gameState, onLeaveGame }) {
  const players = gameState?.players || [];
  const endReason = gameState?.end_reason;
  const winnerId = gameState?.winner_id;

  // Sort players by score (highest to lowest)
  const sortedPlayers = [...players].sort((a, b) => b.score - a.score);

  // Get end reason message
  const getEndReasonMessage = () => {
    switch (endReason) {
      case 'max_score_reached':
        return 'Someone reached the winning score!';
      case 'no_prompt_cards_left':
        return 'No more black cards left in the deck!';
      case 'too_few_players':
        return 'Too few players remaining to continue.';
      default:
        return 'Game has ended.';
    }
  };

  return (
    <div className="game-end">
      <h1>Game Over!</h1>

      <div className="end-reason">
        <p>{getEndReasonMessage()}</p>
      </div>

      <div className="scoreboard">
        <h2>Final Scores</h2>
        <div className="scoreboard-list">
          {sortedPlayers.map((player, index) => {
            const isWinner = player.id === winnerId;
            const isRando = player.is_rando;

            return (
              <div key={player.id} className={`scoreboard-item ${isWinner ? 'winner' : ''}`}>
                <div className="player-rank">#{index + 1}</div>
                <div className="player-info">
                  <div className="player-name-score">
                    <span className="player-name">
                      {isRando && (
                        <>
                          <FontAwesomeIcon icon={faRobot} />{' '}
                        </>
                      )}
                      {player.name}
                    </span>
                    <span className="player-score">{player.score} points</span>
                  </div>
                  {isWinner && (
                    <span className="winner-badge">
                      <FontAwesomeIcon icon={faCrown} /> Winner
                    </span>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      <div className="game-end-actions">
        <button className="btn btn-primary" onClick={onLeaveGame}>
          Leave Game
        </button>
        <p className="info-message">
          Thanks for playing! Click &quot;Leave Game&quot; to start a new game.
        </p>
      </div>
    </div>
  );
}

export default GameEnd;
