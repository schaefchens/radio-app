/**
 * The lines a protocol 1 voice worker's config.toml needs for this station:
 * its address and the key, which /mod shows only once (worker/README.md).
 */
export function configSnippet(key: string, origin: string): string {
  const local = /^https?:\/\/(localhost|127\.|\[::1\])/.test(origin);
  return `[[stations]]\nname = "${local ? 'ARCHE dev' : 'ARCHE'}"\nurl = "${origin}"\nkey = "${key}"\n`;
}

/** Where a herde worker reaches this station (protocol 2). */
export function projectAddress(origin: string): string {
  return `${origin.replace(/\/+$/, '')}/api/worker/v2`;
}

/** What a computer runs to join with an invite: herde trades the code for its key once. */
export function joinCommand(code: string, origin: string): string {
  return `herde join ${projectAddress(origin)} ${code}`;
}
