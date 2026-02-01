import { useState, useEffect } from 'react';
import { getRandomPairing } from '../utils/api';

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

  const renderCardWithBlanks = (promptCardText, responseCardTexts) => {
    const blanks = (promptCardText.match(/_+/g) || []).length;

    if (blanks === 0) {
      // No blanks, show prompt card and response cards below
      return (
        <div className="card-combination">
          <div
            className="card-text prompt-text"
            dangerouslySetInnerHTML={{ __html: formatCardText(promptCardText) }}
          />
          <div className="response-cards-below">
            {responseCardTexts.map((text, index) => (
              <div
                key={index}
                className="card-text response-text-inline"
                dangerouslySetInnerHTML={{ __html: formatCardText(text) }}
              />
            ))}
          </div>
        </div>
      );
    }

    // Replace blanks with response card text
    let result = promptCardText;
    let responseIndex = 0;

    result = result.replace(/_+/g, () => {
      if (responseIndex < responseCardTexts.length) {
        // Trim trailing periods from response cards since prompt cards usually have punctuation
        const responseText = responseCardTexts[responseIndex].replace(/\.+$/, '');
        const replacement = `<span class="response-text-inline">${formatCardText(
          responseText
        )}</span>`;
        responseIndex++;
        return replacement;
      }
      return '_____';
    });

    return (
      <div
        className="card-text prompt-text"
        dangerouslySetInnerHTML={{ __html: result }}
      />
    );
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
          {renderCardWithBlanks(
            pairing.prompt.copy,
            pairing.responses.map(r => r.copy)
          )}
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

function formatCardText(text) {
  if (!text) return '';

  // Protect sequences of 3+ underscores (blanks) by replacing them temporarily
  // Using vertical tab character (U+000B) which won't appear in cards or be processed by markdown
  const blankPlaceholder = '\u000B';
  let formatted = text.replace(/_{3,}/g, blankPlaceholder);

  // Convert newlines to <br>
  formatted = formatted.replace(/\n/g, '<br>');
  
  // Simple markdown-like formatting
  // Bold: *text*
  formatted = formatted.replace(/\*(.+?)\*/g, '<strong>$1</strong>');
  
  // Italic: _text_
  formatted = formatted.replace(/_(.+?)_/g, '<em>$1</em>');
  
  // Restore the blanks
  formatted = formatted.replace(new RegExp(blankPlaceholder, 'g'), '_____');

  return formatted;
}

export default RandomPairing;
