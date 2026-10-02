import { expect, test, type Browser, type Page } from '@playwright/test';
import { fakeYouTube, ytNow } from './support/youtube';
import { BASE_URL, api, cron, ensureAdmin, itemAt, serverNow, type Admin, type Device } from './support/station';

/**
 * A preaching program end to end — on a channel of its own, so the shared
 * `main` station keeps its music program for the other specs: a preaching
 * from the library on air as the video it is, named a preaching; the fourth
 * tile, which suggests one, through the YouTube check and moderation; and on
 * a music program the same tile closed. The running order itself (songs
 * between preachings, what fits, suggestions first, never twice) is walked
 * on the server's fixed clock (server/tests/cases/preaching.php).
 */

test.describe.configure({ mode: 'serial' });

let admin: Admin;
let slug = '';
let channelId = 0;

async function must<T>(call: Promise<{ status: number; data: T }>, what: string): Promise<T> {
  const { status, data } = await call;
  if (status !== 200) throw new Error(`${what}: ${status} ${JSON.stringify(data)}`);
  return data;
}

/** A listener tuned to `channel`, welcomed already. */
async function listener(browser: Browser, channel: string): Promise<Page> {
  const context = await browser.newContext({ baseURL: BASE_URL, serviceWorkers: 'block' });
  const page = await context.newPage();
  await page.addInitScript((ch) => {
    if (!localStorage.getItem('arche.settings')) {
      localStorage.setItem('arche.settings', JSON.stringify({ state: { welcomed: true, channel: ch }, version: 1 }));
    }
  }, channel);
  await fakeYouTube(page);
  await page.goto('/');
  return page;
}

async function deviceOf(page: Page): Promise<Device> {
  return JSON.parse((await page.evaluate(() => localStorage.getItem('arche.device'))) ?? 'null') as Device;
}

test.beforeAll(async () => {
  admin = await ensureAdmin();
  const added = await api(admin, '/mod/library', { body: { kind: 'preaching', url: 'https://youtu.be/e2ePreach01', themes: ['grace'] } });
  if (added.status !== 200 && added.status !== 409) throw new Error(`the library's preaching: ${added.status} ${JSON.stringify(added.data)}`);
  slug = `predigt-${Date.now().toString(36)}`;
  // A new channel brings its own music program and a plan for every day: the plan gets the preaching program instead.
  const ch = await must(api<{ channel: { id: number } }>(admin, '/mod/channels', { body: { slug, name_en: 'Sermons', name_de: 'Predigten', timezone: 'Europe/Berlin' } }), 'channel');
  channelId = ch.channel.id;
  const sermon = await must(
    api<{ program: { id: number; allowed: string[] } }>(admin, `/mod/channels/${channelId}/programs`, {
      body: { slug: 'predigt', title_en: 'Sunday Sermon', title_de: 'Sonntagspredigt', allowed: ['preaching'], settings: { format: 'preaching', max_queue_min: 180, preaching: { songs_between: 1 } } },
    }),
    'preaching program',
  );
  expect(sermon.program.allowed).toEqual(['preaching']);
  const plans = await must(api<{ dayPlans: { id: number; name: string }[] }>(admin, `/mod/channels/${channelId}/plans`), 'plans');
  const plan = plans.dayPlans[0]!;
  await must(api(admin, `/mod/day-plans/${plan.id}`, { method: 'PUT', body: { name: plan.name, blocks: [{ start_min: 0, end_min: 1440, program_id: sermon.program.id }] } }), 'day plan');
});

test.afterAll(async () => {
  // The other specs see one channel again.
  if (channelId) await api(admin, `/mod/channels/${channelId}`, { method: 'PATCH', body: { active: false } });
});

test('a preaching program: the library\'s preaching plays as its video, named a preaching; the fourth tile suggests one, which is checked and accepted', async ({ browser }) => {
  test.setTimeout(10 * 60_000);
  // A channel made just now anchors its timeline first (a gap of a minute or two), then the preaching.
  await expect
    .poll(
      async () => {
        await cron();
        const item = await itemAt(await serverNow(), slug);
        return item?.type === 'song' ? `${item.kind} ${item.yt}` : (item?.type ?? 'nothing');
      },
      { timeout: 6 * 60_000, intervals: [5000] },
    )
    .toBe('preaching e2ePreach01');

  const page = await listener(browser, slug);
  await page.getByRole('button', { name: 'Tap to join live' }).click();
  const player = page.getByRole('region', { name: 'Radio player' });
  await expect(player.getByText('Preaching', { exact: true })).toBeVisible();
  await expect(player.getByText('The Prodigal Son')).toBeVisible();
  await expect.poll(async () => (await ytNow(page))?.videoId).toBe('e2ePreach01');

  // The fourth tile, where the room used to be: open while the preaching program is on.
  await page.getByRole('button', { name: 'Suggest a preaching' }).first().click();
  const sheet = page.getByRole('dialog', { name: 'Suggest a preaching' });
  await expect(sheet).toBeInViewport();
  await sheet.getByLabel('YouTube link').fill('https://youtu.be/e2ePreach02');
  await expect(sheet.getByText('Pastor Samuel Okoro - Hope in the Storm')).toBeVisible();
  await sheet.getByLabel('Why do you recommend it? (optional)').fill('It carried me through a hard winter.');
  await sheet.getByLabel('Your first name').fill('Samuel');
  await sheet.getByLabel(/Where are you from/).fill('Accra');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(/Thank you! We.ll let you know when it.s on air\./)).toBeVisible();

  const device = await deviceOf(page);
  let status = 'missing';
  await expect
    .poll(
      async () => {
        await cron();
        const r = await api<{ submissions: { type: string; status: string; title: string }[] }>(device, '/submissions');
        const sub = r.data.submissions[0];
        status = sub ? `${sub.type} ${sub.status}` : 'missing';
        return status;
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toMatch(/^preaching (approved|scheduled|aired|library)$/);
  await page.context().close();
});

test('on a music program the preaching tile says it is not part of the program', async ({ browser }) => {
  const page = await listener(browser, 'main');
  await expect(page.getByRole('button', { name: 'Suggest a preaching · Not part of this program' }).first()).toBeVisible();
  await page.context().close();
});
