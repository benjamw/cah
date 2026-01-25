import { useState, useEffect } from 'react';
import JoinGame from './components/JoinGame';
import CreateGame from './components/CreateGame';
import GamePlay from './components/GamePlay';
import { getStoredGameData, storeGameData, clearGameData } from './utils/storage';
import { getGameState } from './utils/api';

function App() {
  const [gameData, setGameData] = useState(null);
  const [view, setView] = useState('join'); // 'join', 'create', 'game'
  const [loading, setLoading] = useState(true);
  const [playerName, setPlayerName] = useState(''); // Shared name between join and create

  useEffect(() => {
    let mounted = true;
    
    // Check if user has an active game in storage
    const stored = getStoredGameData();
    
    if (stored && stored.gameId && stored.playerId) {
      // Verify the game is still valid
      getGameState()
        .then((state) => {
          if ( ! mounted) return;
          if (state.success) {
            setGameData(stored);
            setView('game');
          } else {
            // Session lost - clear data and show join screen
            clearGameData();
          }
          setLoading(false);
        })
        .catch(() => {
          if ( ! mounted) return;
          // Session lost or network error - clear data and show join screen
          clearGameData();
          setLoading(false);
        });
    } else {
      // No stored game, mark as done loading
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setLoading(false);
    }
    
    return () => {
      mounted = false;
    };
  }, []);

  const handleGameJoined = (data) => {
    storeGameData(data);
    setGameData(data);
    setView('game');
  };

  const handleGameCreated = (data) => {
    storeGameData(data);
    setGameData(data);
    setView('game');
  };

  const handleLeaveGame = () => {
    clearGameData();
    setGameData(null);
    setView('join');
  };

  if (loading) {
    return (
      <div className="app-container">
        <div className="loading">Loading...</div>
      </div>
    );
  }

  return (
    <div className="app-container">
      {view === 'join' && (
        <JoinGame
          onGameJoined={handleGameJoined}
          onSwitchToCreate={() => setView('create')}
          playerName={playerName}
          setPlayerName={setPlayerName}
        />
      )}
      {view === 'create' && (
        <CreateGame
          onGameCreated={handleGameCreated}
          onSwitchToJoin={() => setView('join')}
          playerName={playerName}
          setPlayerName={setPlayerName}
        />
      )}
      {view === 'game' && gameData && (
        <GamePlay gameData={gameData} onLeaveGame={handleLeaveGame} />
      )}
    </div>
  );
}

export default App;
