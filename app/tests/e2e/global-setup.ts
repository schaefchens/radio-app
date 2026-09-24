import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { LIBRARY_IDS } from './fake-youtube.mjs';
import { BASE_URL, E2E_DIR, api, ensureAdmin, waitOnAir } from './support/station';

/**
 * Bring the e2e station on air the way a new station goes live in production:
 * the first administrator via passphrase + setup key, the library through
 * /mod (the Data API is the fake), then the cron until the program is out.
 * Idempotent: a second run finds the admin and the songs already there.
 */
export default async function globalSetup(): Promise<void> {
  const up = await fetch(`${BASE_URL}/api/time`).catch(() => null);
  if (!up?.ok) throw new Error(`The e2e stack does not answer on ${BASE_URL} — start it: bash scripts/e2e-stack.sh up`);

  const admin = await ensureAdmin();
  // Test runs hand in song requests faster than they air, and a full queue
  // closes requests (correctly). A moderator widens it for an event like this —
  // before the first tick, because minute files already published keep the
  // old window state for up to five minutes. Only when needed: saving a
  // program also re-plans the drafts.
  const overview = await api<{ channels: { programs: { id: number; settings: { max_queue_min: number } }[] }[] }>(admin, '/mod/overview');
  for (const program of overview.data.channels.flatMap((c) => c.programs)) {
    if (program.settings.max_queue_min >= 180) continue;
    const r = await api(admin, `/mod/programs/${program.id}`, { method: 'PATCH', body: { settings: { ...program.settings, max_queue_min: 180 } } });
    if (r.status !== 200) throw new Error(`widening the request queue: ${r.status} ${JSON.stringify(r.data)}`);
  }
  for (const id of LIBRARY_IDS) {
    const r = await api(admin, '/mod/library', { body: { url: `https://www.youtube.com/watch?v=${id}`, themes: ['worship'] } });
    if (r.status !== 200 && r.status !== 409) throw new Error(`adding ${id} to the library: ${r.status} ${JSON.stringify(r.data)}`);
  }
  // The stack's cron waits for this (docker/e2e/compose.e2e.yaml).
  writeFileSync(path.join(E2E_DIR, 'seeded'), new Date().toISOString());
  await waitOnAir();
}
