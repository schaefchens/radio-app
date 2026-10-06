import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { MINUTE_MS } from '@arche/shared';
import { BASE_URL, api, cron, ensureAdmin, serverNow, slotAt, type Admin } from './support/station';

/**
 * A voice worker end to end, on a channel of its own: an admin adds a worker
 * and a host that speaks with it, and a stand-in for the Mac (fetch in the
 * test process, no model) polls the station through Apache with its key
 * header and uploads the bundled MP3 multipart for every task — and the
 * host's moments air with those clips. The real worker is tested on its own
 * (worker/tests); its timings and fallbacks on the server's fixed clock
 * (server/tests/cases/workers.php).
 */

test.describe.configure({ mode: 'serial' });

const stamp = Date.now().toString(36);
const MP3 = readFileSync(fileURLToPath(new URL('../../../server/resources/stub-voice.mp3', import.meta.url)));
const hostName = `Wendy ${stamp}`;
const channel = { id: 0, slug: `workers-${stamp}` };
const report = {
  version: 'e2e',
  engine: { model: 'qwen3-tts-1.7b-customvoice' },
  voices: [
    { id: 'Ryan', label: 'Ryan' },
    { id: 'Sohee', label: 'Sohee' },
  ],
  languages: ['en', 'de'],
  ready: true,
};
let admin: Admin;
let key = '';
let workerId = 0;

async function must<T>(call: Promise<{ status: number; data: T }>, what: string): Promise<T> {
  const { status, data } = await call;
  if (status !== 200) throw new Error(`${what}: ${status} ${JSON.stringify(data)}`);
  return data;
}

async function poll(withKey = key): Promise<{ status: number; task: { id: number } | null }> {
  const res = await fetch(`${BASE_URL}/api/worker/poll`, {
    method: 'POST',
    headers: { 'x-arche-worker-key': withKey, 'content-type': 'application/json' },
    body: JSON.stringify(report),
  });
  const data = (await res.json()) as { task?: { id: number } | null };
  return { status: res.status, task: data.task ?? null };
}

/** Like the Mac: every task it is given, spoken (the stub clip) and uploaded multipart. */
async function speakAll(): Promise<number> {
  let done = 0;
  for (let i = 0; i < 8; i++) {
    const { task } = await poll();
    if (!task) break;
    const form = new FormData();
    form.append('audio', new Blob([MP3], { type: 'audio/mpeg' }), `${task.id}.mp3`);
    form.append('ms', '3024');
    const res = await fetch(`${BASE_URL}/api/worker/tasks/${task.id}/audio`, { method: 'POST', headers: { 'x-arche-worker-key': key }, body: form });
    if (res.status === 200) done++;
  }
  return done;
}

test.beforeAll(async () => {
  admin = await ensureAdmin();
  const ch = await must(
    api<{ channel: { id: number } }>(admin, '/mod/channels', { body: { slug: channel.slug, name_en: `Workers ${stamp}`, name_de: `Workers ${stamp}`, timezone: 'Europe/Berlin' } }),
    'channel',
  );
  channel.id = ch.channel.id;
  const programs = await must(api<{ programs: { id: number }[] }>(admin, `/mod/channels/${channel.id}/programs`), 'programs');
  const host = await must(api<{ host: { id: number } }>(admin, '/mod/hosts', { body: { name: hostName, provider: 'worker', voices: { en: 'Ryan', de: 'Sohee' } } }), 'host');
  // A break after every song: a new channel's first moments come too soon to be voiced.
  await must(
    api(admin, `/mod/programs/${programs.programs[0]!.id}`, { method: 'PATCH', body: { hosts: [{ id: host.host.id, role: 'main' }], settings: { host: { enabled: true, every_songs: 1, intro: true, outro: true } } } }),
    'program',
  );
  const added = await must(api<{ key: string; worker: { id: number } }>(admin, '/mod/workers', { body: { name: `Mac ${stamp}` } }), 'worker');
  key = added.key;
  workerId = added.worker.id;
});

test.afterAll(async () => {
  // The other specs see one channel again, and no worker answering for hosts of theirs.
  if (channel.id) await api(admin, `/mod/channels/${channel.id}`, { method: 'PATCH', body: { active: false } });
  if (workerId) await api(admin, `/mod/workers/${workerId}`, { method: 'DELETE' });
});

test('a worker pulls voice tasks through the web server, uploads MP3s, and its host speaks with them on air', async () => {
  test.setTimeout(10 * 60_000);
  expect((await poll(`${'0'.repeat(64)}`)).status).toBe(401);
  // The key header reaches PHP through Apache (an Authorization header would not on the host).
  expect((await poll()).status).toBe(200);

  let uploads = 0;
  await expect
    .poll(
      async () => {
        await cron();
        uploads += await speakAll();
        const now = await serverNow();
        for (let m = -1; m <= 2; m++) {
          const slot = await slotAt(now + m * MINUTE_MS, channel.slug);
          const voiced = (slot?.items ?? []).find(
            (it) => it.type === 'host' && it.host?.name === hostName && Object.values(it.audio).some((url) => String(url).startsWith('/media/host/')),
          );
          if (voiced) return true;
        }
        return false;
      },
      { timeout: 9 * 60_000, intervals: [5000] },
    )
    .toBe(true);
  expect(uploads).toBeGreaterThanOrEqual(2);

  const { workers } = await must(api<{ workers: { id: number; online: boolean; tasks: { done_today: number } }[] }>(admin, '/mod/workers'), 'workers');
  const mine = workers.find((w) => w.id === workerId);
  expect(mine?.online).toBe(true);
  expect(mine?.tasks.done_today ?? 0).toBeGreaterThanOrEqual(2);
});
