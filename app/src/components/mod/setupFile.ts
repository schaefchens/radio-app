/** arche-setup-20261008.json, by the day the setup was made (UTC, like the server's names). */
export function setupFileName(exportedSec: number): string {
  return `arche-setup-${new Date(exportedSec * 1000).toISOString().slice(0, 10).replaceAll('-', '')}.json`;
}
