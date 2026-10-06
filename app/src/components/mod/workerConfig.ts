/**
 * The lines a computer's config.toml needs for this station: its address and
 * the key, which /mod shows only once (worker/README.md).
 */
export function configSnippet(key: string, origin: string): string {
  const local = /^https?:\/\/(localhost|127\.|\[::1\])/.test(origin);
  return `[[stations]]\nname = "${local ? 'ARCHE dev' : 'ARCHE'}"\nurl = "${origin}"\nkey = "${key}"\n`;
}
