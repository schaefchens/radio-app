/** A sentence for the last error the node (or the wake endpoint) reported. */
export function chatErrorKey(code: string | null): string | null {
  switch (code) {
    case null:
      return null;
    case 'rate':
      return 'chat.rate';
    case 'too_long':
      return 'chat.tooLong';
    case 'blocked':
      return 'chat.blocked';
    case 'banned':
      return 'chat.banned';
    case 'draining':
      return 'chat.draining';
    case 'full':
      return 'chat.full';
    case 'bad':
      return 'chat.bad';
    case 'unavailable':
      return 'chat.unavailable';
    case 'auth':
    case 'expired':
      return 'chat.connecting';
    default:
      return 'chat.error';
  }
}
