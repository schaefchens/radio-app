import { expect, test, type Browser, type Page } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { BASE_URL, api, cron, ensureAdmin, itemAt, liveFile, serverNow, type Admin, type Device } from './support/station';

/**
 * The prayer hour end to end — on a channel of its own, so the shared `main`
 * station keeps its music program (and its song requests) for the other
 * specs. In real time, so only its first minutes: the stage during the
 * collection, a request sent from it — counted, but not shown before it is
 * read out — and no Pray button before the prayer time. The whole hour (the
 * presentation, the prayer time with listeners' prayers, the outro) takes
 * longer than a test should and is walked on the server's fixed clock
 * (server/tests/cases/prayerhour.php); the stage's prayer views and the Pray
 * sheet are rendered in app/tests/component (StageVisual, PraySheet). Praying
 * along is tested on the music program's wall (listener.spec.ts).
 */

test.describe.configure({ mode: 'serial' });

let admin: Admin;
let slug = '';
let channelId = 0;

/** An MP3 of silence: MPEG-1 Layer III frames (128 kbit/s, 44.1 kHz, 1152 samples each). */
function silentMp3(seconds: number): Blob {
  const frame = new Uint8Array(417);
  frame.set([0xff, 0xfb, 0x90, 0x64]);
  const n = Math.ceil((seconds * 44100) / 1152);
  const out = new Uint8Array(417 * n);
  for (let i = 0; i < n; i++) out.set(frame, i * 417);
  return new Blob([out], { type: 'audio/mpeg' });
}

async function must<T>(call: Promise<{ status: number; data: T }>, what: string): Promise<T> {
  const { status, data } = await call;
  if (status !== 200) throw new Error(`${what}: ${status} ${JSON.stringify(data)}`);
  return data;
}

/** A listener tuned to the test channel, welcomed already. */
async function listener(browser: Browser): Promise<Page> {
  const context = await browser.newContext({ baseURL: BASE_URL, serviceWorkers: 'block' });
  const page = await context.newPage();
  await page.addInitScript((channel) => {
    if (!localStorage.getItem('arche.settings')) {
      localStorage.setItem('arche.settings', JSON.stringify({ state: { welcomed: true, channel }, version: 1 }));
    }
  }, slug);
  await fakeYouTube(page);
  await page.goto('/');
  await page.getByRole('button', { name: 'Tap to join live' }).click();
  return page;
}

async function deviceOf(page: Page): Promise<Device> {
  return JSON.parse((await page.evaluate(() => localStorage.getItem('arche.device'))) ?? 'null') as Device;
}

/**
 * Until the collection is on air: its prayer music, labelled "What can we pray
 * for?". A channel made just now plans the hour at the committed edge, so the
 * welcome first waits for its voice behind the hour's own music (labelled
 * with its title) — minutes, not seconds. The cron runs every minute; a nudge
 * every 20 s.
 */
async function waitForCollection(): Promise<void> {
  await expect
    .poll(
      async () => {
        await cron();
        const item = await itemAt(await serverNow(), slug);
        return item?.type === 'bed' ? item.label.en : (item?.type ?? 'nothing');
      },
      { timeout: 8 * 60_000, intervals: [5000] },
    )
    .toBe('What can we pray for?');
}

test.beforeAll(async () => {
  admin = await ensureAdmin();
  slug = `gebet-${Date.now().toString(36)}`;
  // A new channel brings its own music program, plan and fallback.
  const ch = await must(api<{ channel: { id: number } }>(admin, '/mod/channels', { body: { slug, name_en: 'Prayer', name_de: 'Gebet', timezone: 'Europe/Berlin' } }), 'channel');
  channelId = ch.channel.id;
  const form = new FormData();
  form.set('title', 'Quiet pad');
  form.set('audio', silentMp3(40), 'pad.mp3');
  const upload = await fetch(`${BASE_URL}/api/mod/beds`, { method: 'POST', headers: { 'X-Arche-Id': admin.id, 'X-Arche-Secret': admin.secret }, body: form });
  if (!upload.ok) throw new Error(`prayer music: ${upload.status} ${await upload.text()}`);
  const bed = ((await upload.json()) as { item: { id: number } }).item;
  const prayer = await must(
    api<{ program: { id: number; allowed: string[] } }>(admin, `/mod/channels/${channelId}/programs`, {
      body: {
        slug: 'gebet',
        title_en: 'Prayer Hour',
        title_de: 'Gebetsstunde',
        settings: { format: 'prayer', max_queue_min: 180, prayer: { collect: { songs: 0, minutes: 10, bed_id: bed.id }, quiet_min: 4, after_songs: 0, opendoors: false } },
      },
    }),
    'prayer hour',
  );
  expect(prayer.program.allowed).toEqual(['prayer', 'intercession']);
  // From two minutes from now (Berlin) for forty minutes, today.
  const local = new Date(new Date(await serverNow()).toLocaleString('en-US', { timeZone: 'Europe/Berlin' }));
  const start = local.getHours() * 60 + local.getMinutes() + 2;
  test.skip(start + 20 > 1440, 'too close to midnight for a prayer hour of its own today');
  const plan = await must(
    api<{ id: number }>(admin, `/mod/channels/${channelId}/day-plans`, { body: { name: 'Prayer now', blocks: [{ start_min: start, end_min: Math.min(1440, start + 40), program_id: prayer.program.id }] } }),
    'day plan',
  );
  await must(api(admin, `/mod/channels/${channelId}/special-days`, { body: { name: 'Today', kind: 'date', month: local.getMonth() + 1, day: local.getDate(), day_plan_id: plan.id } }), 'special day');
});

test.afterAll(async () => {
  // The other specs see one channel again.
  if (channelId) await api(admin, `/mod/channels/${channelId}`, { method: 'PATCH', body: { active: false } });
});

test('a prayer hour: over prayer music the stage invites requests and counts them — none is shown before it is read out, and no one prays yet', async ({ browser }) => {
  test.setTimeout(12 * 60_000);
  await waitForCollection();
  const sender = await listener(browser);

  // The collection: our stage, no YouTube player on screen. (The player's
  // "Now playing" row names the label too: the stage is asked, not the page.)
  const stage = sender.locator('[data-stage-slot]');
  await expect(stage.getByText('What can we pray for?')).toBeVisible({ timeout: 45_000 });
  const share = stage.getByRole('button', { name: 'Share a prayer request' });
  await expect(share).toBeVisible();
  // Prayers belong to the prayer time, after the requests are read out.
  await expect(stage.getByRole('button', { name: /Pray$/ })).toHaveCount(0);
  expect(await sender.evaluate(() => document.querySelector('[class*="z-[35]"]')?.getAttribute('aria-hidden'))).toBe('true');

  // A request from the stage. In a prayer hour every request reaches the hour's wall when it is read out:
  // the box keeps it on the wall after the hour.
  await share.click();
  const sheet = sender.getByRole('dialog', { name: 'Share a prayer request' });
  const text = `Please pray for my father's healing (${Date.now() % 100_000}).`;
  await sheet.getByLabel('Your prayer request').fill(text);
  await expect(sheet.getByText(/In this prayer hour every request is shown on the prayer wall/)).toBeVisible();
  await sheet.getByLabel('Keep my request on the prayer wall after the prayer hour too, without my name, so others can go on praying with me.').check();
  await sheet.getByLabel('Your first name').fill('Ruth');
  await sheet.getByLabel(/Where are you from/).fill('Lagos');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(/Thank you!/)).toBeVisible();
  await sender.keyboard.press('Escape');

  // Checked on the next ticks; then counted in live.json — and not on the wall.
  const me = await deviceOf(sender);
  await expect
    .poll(
      async () => {
        await cron();
        const r = await api<{ submissions: { title: string; status: string }[] }>(me, '/submissions');
        return r.data.submissions.find((s) => text.startsWith(s.title))?.status ?? 'missing';
      },
      { timeout: 120_000, intervals: [3000] },
    )
    .toMatch(/approved|scheduled/);
  await expect
    .poll(
      async () => {
        await cron();
        return (await liveFile(slug))?.collected ?? 0;
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toBeGreaterThanOrEqual(1);
  expect((await liveFile(slug))?.wall.some((e) => e.text === text)).toBe(false);
  // The app reads live.json every 30 s: the stage shows the number, never the text.
  await expect(stage.getByText(/prayer requests? so far/)).toBeVisible({ timeout: 45_000 });
  await expect(stage.getByText(text)).toHaveCount(0);
  await expect(sender.getByRole('region', { name: 'Prayer wall' }).getByText(text)).toHaveCount(0);
  await sender.context().close();
});

for (const width of [360, 390]) {
  test(`with the prayer music on the stage nothing scrolls sideways at ${width} px`, async ({ browser }) => {
    test.setTimeout(10 * 60_000);
    await waitForCollection();
    const page = await listener(browser);
    for (const theme of ['light', 'dark'] as const) {
      await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
      await page.setViewportSize({ width, height: 800 });
      await expect(page.locator('[data-stage-slot]').getByText('What can we pray for?')).toBeVisible({ timeout: 45_000 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth), theme).toBeLessThanOrEqual(width);
    }
    await page.context().close();
  });
}
