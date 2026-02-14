import { formatCardText } from '../utils/cardFormatting';

function CardText({ text, className }) {
  return <div className={className} dangerouslySetInnerHTML={{ __html: formatCardText(text) }} />;
}

export default CardText;
