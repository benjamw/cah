function SkipCzarButton({ voteCount, hasVoted, onVote, voting }) {
  const votesNeeded = 2;

  return (
    <button
      className={`btn-skip-czar ${hasVoted ? 'voted' : ''}`}
      onClick={onVote}
      disabled={voting}
      title={`${voteCount}/${votesNeeded} votes to skip`}
    >
      {hasVoted ? '✓ ' : ''}Skip Current Czar {voteCount > 0 ? `(${voteCount}/${votesNeeded})` : ''}
    </button>
  );
}

export default SkipCzarButton;
