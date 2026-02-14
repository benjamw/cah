import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRobot } from '@fortawesome/free-solid-svg-icons';

function Scoreboard({ players, currentPlayerId }) {
  // Sort players by score (descending), then alphabetically by name
  const sortedPlayers = [...players].sort((a, b) => {
    // First sort by score (highest first)
    if (b.score !== a.score) {
      return b.score - a.score;
    }
    // If scores are equal, sort alphabetically by name
    return a.name.localeCompare(b.name);
  });

  return (
    <div className="scoreboard">
      <h3 className="scoreboard-title">Scoreboard</h3>
      <div className="scoreboard-list">
        {sortedPlayers.map((player, index) => {
          const isCurrentPlayer = player.id === currentPlayerId;
          const isRando = player.is_rando;

          // Calculate rank based on score (players with same score get same rank)
          let rank = 1;
          for (let i = 0; i < index; i++) {
            if (sortedPlayers[i].score > player.score) {
              rank++;
            }
          }

          return (
            <div
              key={player.id}
              className={`scoreboard-item ${isCurrentPlayer ? 'current-player' : ''}`}
            >
              <span className="scoreboard-rank">#{rank}</span>
              <span className="scoreboard-name">
                {isRando && (
                  <>
                    <FontAwesomeIcon icon={faRobot} />{' '}
                  </>
                )}
                {player.name}
                {isCurrentPlayer && ' (You)'}
              </span>
              <span className="scoreboard-score">
                {player.score} {player.score === 1 ? 'pt' : 'pts'}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export default Scoreboard;
