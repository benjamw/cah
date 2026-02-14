import CardText from './CardText';

function CardView({
  copy,
  variant = 'prompt',
  className = '',
  contentClassName = 'card-content',
  selected = false,
  disabled = false,
  choices,
  onClick,
  children,
}) {
  const classes = [
    'card',
    `card-${variant}`,
    className,
    selected ? 'card-selected' : '',
    disabled ? 'card-disabled' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={classes} onClick={onClick}>
      <CardText text={copy} className={contentClassName} />
      {choices > 1 && <div className="card-pick">Pick {choices}</div>}
      {children}
    </div>
  );
}

export default CardView;
