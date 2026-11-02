import { createHash, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { MINUTE_MS } from '@arche/shared';
import { BASE_URL, api, cron, ensureAdmin, serverNow, slotAt, type Admin } from './support/station';

/**
 * Computers end to end, on a channel of their own: an admin adds a host
 * that speaks with them, and stand-ins for herde (fetch in the test process,
 * no model) join with an invite, run the station's profile, poll through
 * Apache with their key header and hand back the bundled MP3 multipart for
 * every task — and the host's moments air with those clips. First one of
 * ours, then a lent computer. The real worker is tested in its own repo
 * (herde); timings, privacy and fallbacks on the server's fixed clock
 * (server/tests/cases/workers.php).
 */

test.describe.configure({ mode: 'serial' });

const stamp = Date.now().toString(36);
const MP3 = readFileSync(fileURLToPath(new URL('../../../server/resources/stub-voice.mp3', import.meta.url)));
const hostName = `Wendy ${stamp}`;
const channel = { id: 0, slug: `workers-${stamp}` };
const VOICES = [
  { id: 'Ryan', label: 'Ryan' },
  { id: 'Sohee', label: 'Sohee' },
];
let admin: Admin;
let ownId = 0;
let lentId = 0;
let programId = 0;
let hostId = 0;

async function must<T>(call: Promise<{ status: number; data: T }>, what: string): Promise<T> {
  const { status, data } = await call;
  if (status !== 200) throw new Error(`${what}: ${status} ${JSON.stringify(data)}`);
  return data;
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
});

test.afterAll(async () => {
  // The other specs see one channel again, and no computer answering for hosts of theirs.
  if (channel.id) await api(admin, `/mod/channels/${channel.id}`, { method: 'PATCH', body: { active: false } });
  if (ownId) await api(admin, `/mod/workers/${ownId}`, { method: 'DELETE' });
  if (lentId) await api(admin, `/mod/workers/${lentId}`, { method: 'DELETE' });
});

/** A computer joins with an admin's invite, as `herde join` does: its key, id and trust. */
async function joinWith(name: string, trust: 'own' | 'lender'): Promise<{ key: string; id: number; trust: string; again: number }> {
  const invite = await must(api<{ code: string }>(admin, '/mod/workers/invites', { body: { name, trust } }), 'invite');
  const join = () =>
    fetch(`${BASE_URL}/api/worker/v2/join`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ code: invite.code, name: 'e2e', version: 'e2e', platform: { os: 'linux', arch: 'x86_64' } }),
    });
  const joined = await join();
  expect(joined.status).toBe(200);
  const { key, worker } = (await joined.json()) as { key: string; worker: { id: number; trust: string } };
  // Used once.
  return { key, id: worker.id, trust: worker.trust, again: (await join()).status };
}

interface Profile {
  profile: string;
  tts: { code: number; backends: Record<string, { model: string; clone_model: string }>; speech: Record<string, number>; voices: { id: string; langs: Record<string, { file: string }> }[] };
}

/**
 * Like herde: the station's profile fetched and run (herde's stub backend, which a local stack's profile
 * names), a poll that says so with its caps, then every task offered claimed, spoken (the stub clip) and
 * handed back. Only a computer in step with the profile is given speech.
 */
async function herdeRound(key: string): Promise<number> {
  const headers = { 'x-worker-key': key };
  const doc = (await (await fetch(`${BASE_URL}/api/worker/v2/profile`, { headers })).json()) as Profile;
  const stub = doc.tts.backends.stub!;
  const setup = {
    code: doc.tts.code,
    backend: 'stub',
    model: stub.model,
    clone_model: stub.clone_model,
    dtype: '',
    speech: doc.tts.speech,
    voices: Object.fromEntries(doc.tts.voices.map((v) => [v.id, Object.fromEntries(Object.entries(v.langs).map(([lang, rec]) => [lang, rec.file]))])),
    checks: [],
  };
  const caps = {
    name: 'e2e',
    platform: { os: 'linux', arch: 'x86_64' },
    engines: [{ kind: 'tts', model: 'qwen3-tts-1.7b', location: 'local', max_chars: 2000, voices: VOICES.map((v) => ({ ...v, langs: ['en', 'de'], instruct: true })), setup }],
  };
  const post = (path: string, body: unknown) => fetch(`${BASE_URL}/api/worker/v2${path}`, { method: 'POST', headers: { ...headers, 'content-type': 'application/json' }, body: JSON.stringify(body) });
  const res = await post('/poll', {
    protocol: 2,
    version: 'e2e',
    kinds: { tts: [1] },
    state: 'ready',
    caps_hash: createHash('sha256').update(JSON.stringify(caps)).digest('hex'),
    caps,
    running: [],
    free: { tts: 1 },
    profile: { hash: doc.profile, state: 'ok', verified_ago: 60 },
  });
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
    const result = await fetch(`${BASE_URL}/api/worker/v2/tasks/${offer.task}/result`, { method: 'POST', headers, body: form });
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

test('one of our computers joins with an invite, runs the station\'s profile, and its host speaks with its clips on air', async () => {
  test.setTimeout(10 * 60_000);
  const own = await joinWith(`Mac ${stamp}`, 'own');
  ownId = own.id;
  expect([own.trust, own.again]).toEqual(['own', 401]);
  // Only its own header reaches PHP through Apache (an Authorization header would not on the host).
  const wrong = await fetch(`${BASE_URL}/api/worker/v2/profile`, { headers: { 'x-worker-key': '0'.repeat(64) } });
  expect(wrong.status).toBe(401);

  let results = 0;
  await expect
    .poll(
      async () => {
        await cron();
        results += await herdeRound(own.key);
        return results >= 2 && (await voicedNow());
      },
      { timeout: 9 * 60_000, intervals: [5000] },
    )
    .toBe(true);

  const { workers } = await must(api<{ workers: { id: number; online: boolean; tasks: { done_today: number }; profile: { state: string } }[] }>(admin, '/mod/workers'), 'workers');
  const mine = workers.find((w) => w.id === ownId);
  expect([mine?.online, mine?.profile.state]).toEqual([true, 'ok']);
  expect(mine?.tasks.done_today ?? 0).toBeGreaterThanOrEqual(2);
});

test('a lent computer joins with an invite, speaks protocol 2 through the web server, and voices the moments that name nobody', async () => {
  test.setTimeout(10 * 60_000);
  // Ours steps aside: the lent computer is the only one. Host moments are planned while someone
  // can read a listener's words (production's lineup has a fallback voice): the lender voices those that name nobody.
  await must(api(admin, `/mod/workers/${ownId}`, { method: 'PATCH', body: { active: false } }), 'ours off');
  const fallback = await must(api<{ host: { id: number } }>(admin, '/mod/hosts', { body: { name: `Hope ${stamp}`, provider: 'openai', voices: { en: 'coral', de: 'coral' } } }), 'fallback');
  await must(
    api(admin, `/mod/programs/${programId}`, { method: 'PATCH', body: { hosts: [{ id: hostId, role: 'main' }, { id: fallback.host.id, role: 'fallback' }] } }),
    'lineup',
  );
  const lender = await joinWith(`Lent ${stamp}`, 'lender');
  lentId = lender.id;
  expect([lender.trust, lender.again]).toEqual(['lender', 401]);
  // Lent: work on air only once an admin lets it.
  await must(api(admin, `/mod/workers/${lentId}`, { method: 'PATCH', body: { live: true } }), 'live');

  let results = 0;
  await expect
    .poll(
      async () => {
        await cron();
        results += await herdeRound(lender.key);
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
