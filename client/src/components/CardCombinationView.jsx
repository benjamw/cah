import { formatCardText } from '../utils/cardFormatting';
import CardText from './CardText';

function CardCombinationView({ promptText, responseTexts }) {
  const blanks = (promptText.match(/_+/g) || []).length;

  if (blanks === 0) {
    return (
      <div className="card-combination">
        <CardText text={promptText} className="card-text prompt-text" />
        <div className="response-cards-below">
          {responseTexts.map((text, index) => (
            <CardText
              key={index}
              text={text}
              className="card-text response-text-inline"
            />
          ))}
        </div>
      </div>
    );
  }

  // Replace blanks with response card text. Handle _<u>(repeat)</u>_ (or _(repeat)_) as
  // "same as previous blank"; only real blanks (3+ underscores) consume from responseTexts.
  let result = promptText;
  let responseIndex = 0;
  let lastFilled = '';

  // Match blanks (3+ underscores) first, then repeat placeholder _[^\s_]+_
  const repeatOrBlankRegex = /_{3,}|_[^\s_]+_/g;
  result = result.replace(repeatOrBlankRegex, (match) => {
    const isRepeat = !/^_+$/.test(match); // not all underscores => repeat placeholder
    if (isRepeat) {
      if (lastFilled === '') return '_____';
      return `<span class="response-text-inline">${formatCardText(lastFilled)}</span>`;
    }
    // Real blank
    if (responseIndex < responseTexts.length) {
      const responseText = responseTexts[responseIndex].replace(/\.+$/, '');
      lastFilled = responseText;
      responseIndex++;
      return `<span class="response-text-inline">${formatCardText(responseText)}</span>`;
    }
    return '_____';
  });

  return (
    <div
      className="card-text prompt-text"
      dangerouslySetInnerHTML={{ __html: result }}
    />
  );
}

export default CardCombinationView;
