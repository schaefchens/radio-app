import { createHash, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { MINUTE_MS } from '@arche/shared';
import { BASE_URL, api, cron, ensureAdmin, serverNow, slotAt, type Admin } from './support/station';

/**
 * Computers end to end, on a channel of their own: an admin adds a host
 * that speaks with them, and stand-ins (fetch in the test process, no model)
 * poll the station through Apache with their key header and hand back the
 * bundled MP3 multipart for every task — and the host's moments air with
 * those clips. First the old voice worker (protocol 1, added with a key),
 * then a lent computer that joins with an invite and speaks herde's protocol
 * 2. The real worker is tested in its own repo (herde); timings, privacy and
 * fallbacks on the server's fixed clock (server/tests/cases/workers.php).
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
let lentId = 0;
let programId = 0;
let hostId = 0;

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
  programId = programs.programs[0]!.id;
  hostId = host.host.id;
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
  if (lentId) await api(admin, `/mod/workers/${lentId}`, { method: 'DELETE' });
});

/** What a herde worker says it can do: Qwen's speech, two voices in English and German. */
const caps = {
  name: 'e2e',
  platform: { os: 'linux', arch: 'x86_64' },
  engines: [{ kind: 'tts', model: 'qwen3-tts-1.7b', location: 'local', max_chars: 2000, voices: report.voices.map((v) => ({ ...v, langs: ['en', 'de'], instruct: true })) }],
};
const capsHash = createHash('sha256').update(JSON.stringify(caps)).digest('hex');

/** Like herde: a poll (the caps go along), then every task offered claimed, spoken (the stub clip) and handed back. */
async function herdeRound(lentKey: string): Promise<number> {
  const post = (path: string, body: unknown) =>
    fetch(`${BASE_URL}/api/worker/v2${path}`, { method: 'POST', headers: { 'x-worker-key': lentKey, 'content-type': 'application/json' }, body: JSON.stringify(body) });
  const res = await post('/poll', { protocol: 2, version: 'e2e', kinds: { tts: [1] }, state: 'ready', caps_hash: capsHash, caps, running: [], free: { tts: 1 } });
  if (res.status !== 200) throw new Error(`poll: ${res.status}`);
  const { offers } = (await res.json()) as { offers: { task: number }[] };
  let done = 0;
  for (const offer of offers) {
    const lease = randomUUID();
    if ((await post(`/tasks/${offer.task}/claim`, { lease })).status !== 200) continue;
    const form = new FormData();
    form.append('lease', lease);
    form.append('ms', '3024');
    form.append('audio', new Blob([MP3], { type: 'audio/mpeg' }), `${offer.task}.mp3`);
    const result = await fetch(`${BASE_URL}/api/worker/v2/tasks/${offer.task}/result`, { method: 'POST', headers: { 'x-worker-key': lentKey }, body: form });
    if (result.status === 200) done++;
  }
  return done;
}

/** Whether a moment of this channel's host airs with a computer's clip around now. */
async function voicedNow(): Promise<boolean> {
  const now = await serverNow();
  for (let m = -1; m <= 2; m++) {
    const slot = await slotAt(now + m * MINUTE_MS, channel.slug);
    const voiced = (slot?.items ?? []).find(
      (it) => it.type === 'host' && it.host?.name === hostName && Object.values(it.audio).some((url) => String(url).startsWith('/media/host/')),
    );
    if (voiced) return true;
  }
  return false;
}

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
        return voicedNow();
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

test('a lent computer joins with an invite, speaks protocol 2 through the web server, and voices the moments that name nobody', async () => {
  test.setTimeout(10 * 60_000);
  // The old worker steps aside: the lent computer is the only one. Host moments are planned while someone
  // can read a listener's words (production's lineup has a fallback voice): the lender voices those that name nobody.
  await must(api(admin, `/mod/workers/${workerId}`, { method: 'PATCH', body: { active: false } }), 'old worker off');
  const fallback = await must(api<{ host: { id: number } }>(admin, '/mod/hosts', { body: { name: `Hope ${stamp}`, provider: 'openai', voices: { en: 'coral', de: 'coral' } } }), 'fallback');
  await must(
    api(admin, `/mod/programs/${programId}`, { method: 'PATCH', body: { hosts: [{ id: hostId, role: 'main' }, { id: fallback.host.id, role: 'fallback' }] } }),
    'lineup',
  );
  const invite = await must(api<{ code: string }>(admin, '/mod/workers/invites', { body: { name: `Lent ${stamp}`, trust: 'lender' } }), 'invite');
  const join = (code: string) =>
    fetch(`${BASE_URL}/api/worker/v2/join`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ code, name: 'e2e', version: 'e2e', platform: { os: 'linux', arch: 'x86_64' } }) });
  const joined = await join(invite.code);
  expect(joined.status).toBe(200);
  const { key: lentKey, worker } = (await joined.json()) as { key: string; worker: { id: number; trust: string } };
  lentId = worker.id;
  expect(worker.trust).toBe('lender');
  expect((await join(invite.code)).status).toBe(401);
  // Lent: work on air only once an admin lets it.
  await must(api(admin, `/mod/workers/${lentId}`, { method: 'PATCH', body: { live: true } }), 'live');

  let results = 0;
  await expect
    .poll(
      async () => {
        await cron();
        results += await herdeRound(lentKey);
        return results >= 2 && (await voicedNow());
      },
      { timeout: 9 * 60_000, intervals: [5000] },
    )
    .toBe(true);

  const { workers } = await must(api<{ workers: { id: number; trust: string; online: boolean; tasks: { done_today: number } }[] }>(admin, '/mod/workers'), 'workers');
  const lent = workers.find((w) => w.id === lentId);
  expect([lent?.trust, lent?.online]).toEqual(['lender', true]);
  expect(lent?.tasks.done_today ?? 0).toBeGreaterThanOrEqual(2);
});
