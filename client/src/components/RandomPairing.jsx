import { useState, useEffect } from 'react';
import { getRandomPairing } from '../utils/api';
import CardCombinationView from './CardCombinationView';

function RandomPairing({ onBack }) {
  const [pairing, setPairing] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    loadRandomPairing();
  }, []);

  const loadRandomPairing = async () => {
    setLoading(true);
    setError('');
    
    try {
      const response = await getRandomPairing();
      
      if (response.success) {
        setPairing(response.data);
      } else {
        setError(response.message || 'Failed to load random pairing');
      }
    } catch (err) {
      console.error('Error loading random pairing:', err);
      setError('Failed to load random pairing');
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="random-pairing">
        <div className="loading">Loading random pairing...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="random-pairing">
        <div className="error-message">{error}</div>
        <button className="btn btn-secondary" onClick={onBack}>
          Back
        </button>
      </div>
    );
  }

  if (!pairing) {
    return null;
  }

  return (
    <div className="random-pairing">
      <h1>Random Pairing</h1>
      
      <div className="random-pairing-display">
        <div className="card card-prompt card-submission">
          <CardCombinationView
            promptText={pairing.prompt.copy}
            responseTexts={pairing.responses.map((r) => r.copy)}
          />
        </div>
      </div>

      <div className="random-pairing-actions">
        <button className="btn btn-primary" onClick={loadRandomPairing}>
          Show Another
        </button>
        <button className="btn btn-secondary" onClick={onBack}>
          Back to Home
        </button>
      </div>
    </div>
  );
}

export default RandomPairing;
