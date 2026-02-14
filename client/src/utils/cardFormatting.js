export function formatCardText(text) {
  if (!text) return '';

  // Escape HTML first so card content cannot inject markup.
  let formatted = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  // Protect sequences of 3+ underscores (blanks) by replacing them temporarily.
  // Use a vertical tab placeholder that should never appear in card text.
  const blankPlaceholder = '\u000B';
  formatted = formatted.replace(/_{3,}/g, blankPlaceholder);

  // Convert newlines to <br>
  formatted = formatted.replace(/\n/g, '<br>');

  // Simple markdown-like formatting
  // Bold: *text*
  formatted = formatted.replace(/\*(.+?)\*/g, '<strong>$1</strong>');

  // Italic: _text_
  formatted = formatted.replace(/_(.+?)_/g, '<em>$1</em>');

  // Restore the blanks.
  formatted = formatted.replace(new RegExp(blankPlaceholder, 'g'), '_____');

  return formatted;
}
