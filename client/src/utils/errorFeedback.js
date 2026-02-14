export function reportActionFailure({
  response,
  error,
  fallbackMessage,
  showToast,
  logPrefix = 'Action failed',
}) {
  const message = response?.message || response?.error || error?.message || fallbackMessage;

  if (response) {
    console.error(`${logPrefix}:`, response);
  } else if (error) {
    console.error(`${logPrefix}:`, error);
  } else {
    console.error(logPrefix);
  }

  if (showToast) {
    showToast(message);
  }

  return message;
}
