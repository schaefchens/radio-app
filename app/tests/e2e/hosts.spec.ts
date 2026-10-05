import { expect, test, type Browser, type Page } from '@playwright/test';
import type { DayFile, HostItem } from '@arche/shared';
import { fakeYouTube } from './support/youtube';
import { BASE_URL, api, asDevice, cron, ensureAdmin, fetchJson, serverNow, slotAt, type Admin } from './support/station';

/**
 * Hosts end to end, on a channel of their own (the shared `main` station
 * keeps Hope for the other specs): an admin makes a host in /mod › Hosts and
 * tries its voice, puts it on air in the channel's program through the
 * program editor, and listeners then see it on the stage while it speaks and
 * in the schedule; with an ElevenLabs host in the lineup the forms say that an
 * ElevenLabs voice may read out what they send. The stack runs stub AI: no
 * voice service is ever called. Who speaks per show, fallbacks and failures
 * are walked on the server's fixed clock (server/tests/cases/hosts.php).
 */

test.describe.configure({ mode: 'serial' });

let admin: Admin;
const channel = { id: 0, slug: '', name: '' };
let programId = 0;
const stamp = Date.now().toString(36);
const NOAH = `Noah ${stamp}`;

async function must<T>(call: Promise<{ status: number; data: T }>, what: string): Promise<T> {
  const { status, data } = await call;
  if (status !== 200) throw new Error(`${what}: ${status} ${JSON.stringify(data)}`);
  return data;
}

/** A listener tuned to the hosts' channel, welcomed already. */
async function listener(browser: Browser): Promise<Page> {
  const context = await browser.newContext({ baseURL: BASE_URL, serviceWorkers: 'block' });
  const page = await context.newPage();
  await page.addInitScript((ch) => {
    if (!localStorage.getItem('arche.settings')) localStorage.setItem('arche.settings', JSON.stringify({ state: { welcomed: true, channel: ch }, version: 1 }));
  }, channel.slug);
  await fakeYouTube(page);
  await page.goto('/');
  return page;
}

test.beforeAll(async () => {
  admin = await ensureAdmin();
  channel.slug = `hosts-${stamp}`;
  channel.name = `Hosts ${stamp}`;
  const ch = await must(
    api<{ channel: { id: number } }>(admin, '/mod/channels', { body: { slug: channel.slug, name_en: channel.name, name_de: channel.name, timezone: 'Europe/Berlin' } }),
    'channel',
  );
  channel.id = ch.channel.id;
  const programs = await must(api<{ programs: { id: number }[] }>(admin, `/mod/channels/${channel.id}/programs`), 'programs');
  programId = programs.programs[0]!.id;
  // Songs and prayer requests: the host speaks every song, and there is a form to read.
  await must(
    api(admin, `/mod/programs/${programId}`, { method: 'PATCH', body: { allowed: ['song', 'prayer'], settings: { host: { enabled: true, every_songs: 1, intro: true, outro: true } } } }),
    'program',
  );
});

test.afterAll(async () => {
  // The other specs see one channel again.
  if (channel.id) await api(admin, `/mod/channels/${channel.id}`, { method: 'PATCH', body: { active: false } });
});

test('an admin makes a host in /mod › Hosts, tries its voice and puts it on air in a program', async ({ page }) => {
  test.setTimeout(3 * 60_000);
  await asDevice(page, admin);
  await fakeYouTube(page);
  await page.goto('/mod/hosts');
  await page.getByRole('button', { name: 'New host' }).click();
  await page.getByLabel('Name').fill(NOAH);
  await page.getByLabel('About (English)').fill('Evenings and quiet songs.');
  await page.getByLabel('About (German)').fill('Abende und leise Lieder.');
  await page.getByLabel('Style notes').fill('Calm, few words.');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Host saved.')).toBeVisible();

  // Still open: a saved host can be tried — with the stub, a clip comes back at once.
  await page.getByRole('button', { name: 'Play' }).click();
  await expect(page.getByText(/s of speech for \d+ characters\./)).toBeVisible();

  // The program editor's lineup: Noah on air.
  await page.goto('/mod/programs');
  await page.getByLabel('Channel').selectOption({ label: channel.name });
  await page.getByRole('button', { name: 'Edit' }).first().click();
  await page.getByLabel('Add a host:').selectOption({ label: `${NOAH} · OpenAI` });
  await expect(page.getByLabel(`Role of ${NOAH}`)).toHaveValue('main');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved', { exact: true })).toBeVisible();
  const { programs } = await must(api<{ programs: { id: number; hosts: { id: number; role: string }[] }[] }>(admin, `/mod/channels/${channel.id}/programs`), 'programs');
  expect(programs[0]?.hosts.map((h) => h.role)).toEqual(['main']);
});

test('listeners see the host who speaks, on the stage and in the schedule', async ({ browser }) => {
  // A channel made just now anchors its timeline first, and the host speaks after each song.
  test.setTimeout(12 * 60_000);
  // A new channel anchors its timeline first; then the host speaks between songs.
  await expect
    .poll(
      async () => {
        await cron();
        const now = await serverNow();
        const slot = await slotAt(now, channel.slug);
        const moment = slot?.items.find((i): i is HostItem => i.type === 'host' && i.start + i.dur > now);
        return moment?.host?.name ?? 'none yet';
      },
      { timeout: 6 * 60_000, intervals: [5000] },
    )
    .toBe(NOAH);

  const day = await fetchJson<DayFile>(`program/${channel.slug}/days/${new Date(await serverNow()).toLocaleDateString('sv-SE', { timeZone: 'Europe/Berlin' })}.json`);
  expect(Object.values(day?.programs ?? {}).flatMap((p) => p.hosts.map((h) => h.name))).toContain(NOAH);

  const page = await listener(browser);
  await page.getByRole('button', { name: 'Tap to join live' }).click();
  // The host speaks after every song here: the stage names Noah while he does (the cron keeps the station going).
  const ticking = setInterval(() => void cron(), 20_000);
  try {
    await expect(page.getByText(`${NOAH} is speaking`).first()).toBeVisible({ timeout: 6 * 60_000 });
    await expect(page.getByText('Evenings and quiet songs.').first()).toBeVisible();
  } finally {
    clearInterval(ticking);
  }

  await page.goto('/schedule');
  await expect(page.getByText(`with ${NOAH}`).first()).toBeVisible();
  await page.context().close();
});

test('with an ElevenLabs host in the lineup the forms say its voice may read out what is sent', async ({ browser }) => {
  test.setTimeout(4 * 60_000);
  const ella = await must(
    api<{ host: { id: number } }>(admin, '/mod/hosts', {
      body: { name: `Ella ${stamp}`, provider: 'elevenlabs', api_key: 'sk_e2e0000000000000000000000000000', voices: { en: '21m00Tcm4TlvDq8ikWAM' }, max_chars_day: 1000 },
    }),
    'ElevenLabs host',
  );
  const { programs } = await must(api<{ programs: { id: number; hosts: { id: number; role: string }[] }[] }>(admin, `/mod/channels/${channel.id}/programs`), 'programs');
  const lineup = [...(programs[0]?.hosts ?? []), { id: ella.host.id, role: 'fallback' }];
  await must(api(admin, `/mod/programs/${programId}`, { method: 'PATCH', body: { hosts: lineup } }), 'lineup');
  // The minute files written from now on name the voices the program may speak with.
  await expect
    .poll(
      async () => {
        await cron();
        return (await slotAt(await serverNow() + 120_000, channel.slug))?.programs.live?.voicedBy ?? [];
      },
      { timeout: 3 * 60_000, intervals: [5000] },
    )
    .toContain('elevenlabs');

  const page = await listener(browser);
  await page.getByRole('button', { name: /Request a song/ }).first().click();
  const sheet = page.getByRole('dialog', { name: /Request a song/ });
  await expect(sheet.getByText(/an AI voice from ElevenLabs \(USA\) may read it out/)).toBeVisible({ timeout: 3 * 60_000 });
  await page.context().close();
});
