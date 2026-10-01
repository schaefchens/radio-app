import { expect, test, type Browser, type Page } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { BASE_URL, api, cron, ensureAdmin, itemAt, liveFile, serverNow, type Admin, type Device } from './support/station';

/**
 * The prayer hour end to end — on a channel of its own, so the shared `main`
 * station keeps its music program (and its song requests) for the other
 * specs. In real time, so only its first minutes: the stage during the
 * collection, a request on the stage and the wall without its sender,
 * praying along from a second listener, the sender's count. The whole hour —
 * the reading seven minutes on, silent prayer, repeats, the outro — is walked
 * on the server's fixed clock (server/tests/cases/prayerhour.php), and the
 * stage's prayer views are rendered in app/tests/component/StageVisual.test.tsx.
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
 * welcome, the opening prayer and the invitation first wait for their voice
 * behind the hour's own music (labelled with its title) — minutes, not seconds.
 * The cron runs every minute; a nudge every 20 s.
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
        settings: { format: 'prayer', max_queue_min: 180, prayer: { collect: { with: 'music', minutes: 10, songs: 2, bed_id: bed.id }, quiet_min: 4, after_songs: 0 } },
      },
    }),
    'prayer hour',
  );
  expect(prayer.program.allowed).toEqual(['prayer']);
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

test('a prayer hour: the stage invites requests over prayer music; one appears without its sender, a listener prays along, the sender sees it', async ({ browser }) => {
  test.setTimeout(12 * 60_000);
  await waitForCollection();
  const sender = await listener(browser);

  // The collection: our stage, no YouTube player on screen. (The player's
  // "Now playing" row names the label too: the stage is asked, not the page.)
  const stage = sender.locator('[data-stage-slot]');
  await expect(stage.getByText('What can we pray for?')).toBeVisible({ timeout: 45_000 });
  const share = stage.getByRole('button', { name: 'Share a prayer request' });
  await expect(share).toBeVisible();
  expect(await sender.evaluate(() => document.querySelector('[class*="z-[35]"]')?.getAttribute('aria-hidden'))).toBe('true');

  // A request from the stage, with the wall box ticked.
  await share.click();
  const sheet = sender.getByRole('dialog', { name: 'Share a prayer request' });
  const text = `Please pray for my father's healing (${Date.now() % 100_000}).`;
  await sheet.getByLabel('Your prayer request').fill(text);
  await sheet.getByLabel('Also show my request on the prayer wall, without my name, so others can pray with me.').check();
  await sheet.getByLabel('Your first name').fill('Ruth');
  await sheet.getByLabel(/Where are you from/).fill('Lagos');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(/Thank you!/)).toBeVisible();
  await sender.keyboard.press('Escape');

  // Checked on the next ticks, then on the wall in live.json, then on the stage.
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
    .toMatch(/approved|scheduled|aired/);
  // The next tick publishes it in live.json — nudged here, the cron loop
  // alone ticks only every minute — and the app reads that every 30 s.
  await expect
    .poll(
      async () => {
        await cron();
        return (await liveFile(slug))?.wall.some((e) => e.text === text) ?? false;
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toBe(true);
  await expect(stage.getByText(text)).toBeVisible({ timeout: 45_000 });
  await expect(stage.getByText('Ruth')).toHaveCount(0);

  // A second listener prays along on the wall card; the pulse carries it.
  const friend = await listener(browser);
  const wall = friend.getByRole('region', { name: 'Prayer wall' });
  await expect(wall.getByText(text)).toBeVisible({ timeout: 60_000 });
  await wall.getByRole('button', { name: 'I prayed' }).click();
  await friend.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  });

  // Counted when the pulse arrives; My submissions loads after the page and
  // polls every 30 s, so the page is opened once and waited on.
  await sender.goto('/profile');
  await expect(sender.getByText('🙏 1 prayed with you')).toBeVisible({ timeout: 60_000 });
  await sender.context().close();
  await friend.context().close();
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
