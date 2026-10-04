import { expect, test, type Browser, type Page } from '@playwright/test';
import { fakeYouTube, ytNow } from './support/youtube';
import { BASE_URL, api, cron, ensureAdmin, itemAt, serverNow, type Admin, type Device } from './support/station';

/**
 * Video programs end to end — each on a channel of its own, so the shared
 * `main` station keeps its music program for the other specs:
 * - a preaching program: the library's preaching on air as the video it is,
 *   named a preaching; the fourth tile's sheet opening on Preaching, and a
 *   suggestion through the YouTube check and moderation;
 * - a mission program that also takes testimonies: the library's mission
 *   video on air, named "Mission"; the sheet opening on Mission with the
 *   kinds the program does not take closed, and a testimony suggested
 *   through the check;
 * - on a music program the same tile closed.
 * The running orders (songs between videos, what fits, suggestions first,
 * never twice, two-hour films, midnight) are walked on the server's fixed
 * clock (server/tests/cases/preaching.php and videos.php).
 */

test.describe.configure({ mode: 'serial' });

interface Channel {
  slug: string;
  id: number;
}

let admin: Admin;
let preaching: Channel = { slug: '', id: 0 };
let mission: Channel = { slug: '', id: 0 };

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

/**
 * A channel whose whole day is one video program. A new channel brings its own
 * music program and a plan for every day: the plan gets this program instead.
 */
async function videoChannel(
  slug: string,
  name: { en: string; de: string },
  program: { slug: string; title_en: string; title_de: string; format: string; allowed: string[] },
): Promise<Channel> {
  const ch = await must(
    api<{ channel: { id: number } }>(admin, '/mod/channels', { body: { slug, name_en: name.en, name_de: name.de, timezone: 'Europe/Berlin' } }),
    `channel ${slug}`,
  );
  const { format, ...fields } = program;
  const made = await must(
    api<{ program: { id: number; allowed: string[] } }>(admin, `/mod/channels/${ch.channel.id}/programs`, {
      // The settings group keeps its first name: it is every video format's.
      body: { ...fields, settings: { format, max_queue_min: 180, preaching: { songs_between: 1 } } },
    }),
    `${format} program`,
  );
  // The server lists what it takes in its own order.
  expect([...made.program.allowed].sort()).toEqual([...program.allowed].sort());
  const plans = await must(api<{ dayPlans: { id: number; name: string }[] }>(admin, `/mod/channels/${ch.channel.id}/plans`), 'plans');
  const plan = plans.dayPlans[0]!;
  await must(
    api(admin, `/mod/day-plans/${plan.id}`, { method: 'PUT', body: { name: plan.name, blocks: [{ start_min: 0, end_min: 1440, program_id: made.program.id }] } }),
    'day plan',
  );
  return { slug, id: ch.channel.id };
}

/**
 * Ticks until the item on air matches `want` ("<kind> <video id>") and returns
 * its video and title. A channel made just now anchors its timeline first (a
 * gap of a minute or two).
 */
async function waitOnAir(slug: string, want: RegExp): Promise<{ yt: string; title: string }> {
  let onAir = { yt: '', title: '' };
  await expect
    .poll(
      async () => {
        await cron();
        const item = await itemAt(await serverNow(), slug);
        if (item?.type === 'song') onAir = { yt: item.yt, title: item.title };
        return item?.type === 'song' ? `${item.kind} ${item.yt}` : (item?.type ?? 'nothing');
      },
      { timeout: 6 * 60_000, intervals: [5000] },
    )
    .toMatch(want);
  return onAir;
}

/** The listener's newest submission as "<type> <status>", once moderation has decided. */
async function decided(page: Page, pattern: RegExp): Promise<void> {
  const device = await deviceOf(page);
  await expect
    .poll(
      async () => {
        await cron();
        const r = await api<{ submissions: { type: string; status: string }[] }>(device, '/submissions');
        const sub = r.data.submissions[0];
        return sub ? `${sub.type} ${sub.status}` : 'missing';
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toMatch(pattern);
}

test.beforeAll(async () => {
  admin = await ensureAdmin();
  for (const [kind, url] of [
    ['preaching', 'https://youtu.be/e2ePreach01'],
    ['mission', 'https://youtu.be/e2eMission1'],
  ] as const) {
    const added = await api(admin, '/mod/library', { body: { kind, url, themes: ['grace'] } });
    if (added.status !== 200 && added.status !== 409) throw new Error(`the library's ${kind}: ${added.status} ${JSON.stringify(added.data)}`);
  }
  const stamp = Date.now().toString(36);
  // Both channels now, so their timelines start together instead of one after the other.
  preaching = await videoChannel(`predigt-${stamp}`, { en: 'Sermons', de: 'Predigten' }, {
    slug: 'predigt',
    title_en: 'Sunday Sermon',
    title_de: 'Sonntagspredigt',
    format: 'preaching',
    allowed: ['preaching'],
  });
  // Neither the channel nor the program is called "Mission": the player's label must be the video's kind.
  mission = await videoChannel(`outreach-${stamp}`, { en: 'Outreach', de: 'Aussendung' }, {
    slug: 'outreach',
    title_en: 'Into All the World',
    title_de: 'In alle Welt',
    format: 'mission',
    allowed: ['mission', 'testimony_video'],
  });
});

test.afterAll(async () => {
  // The other specs see one channel again.
  for (const ch of [preaching, mission]) {
    if (ch.id) await api(admin, `/mod/channels/${ch.id}`, { method: 'PATCH', body: { active: false } });
  }
});

test('a preaching program: the library\'s preaching plays as its video, named a preaching; the fourth tile suggests one, which is checked and accepted', async ({ browser }) => {
  test.setTimeout(10 * 60_000);
  // A library preaching. The station keeps its data between runs, and an
  // earlier run's suggestion (e2ePreach02) joined the library without airing
  // there: as the one longest unheard, it may well come first.
  const onAir = await waitOnAir(preaching.slug, /^preaching e2ePreach0[12]$/);

  const page = await listener(browser, preaching.slug);
  await page.getByRole('button', { name: 'Tap to join live' }).click();
  const player = page.getByRole('region', { name: 'Radio player' });
  await expect(player.getByText('Preaching', { exact: true })).toBeVisible();
  await expect(player.getByText(onAir.title)).toBeVisible();
  await expect.poll(async () => (await ytNow(page))?.videoId).toBe(onAir.yt);

  // The fourth tile, where the room used to be: one sheet for every kind of video.
  await page.getByRole('button', { name: 'Suggest a video' }).first().click();
  const sheet = page.getByRole('dialog', { name: 'Suggest a video' });
  await expect(sheet).toBeInViewport();
  // A preaching program takes preachings only: the other kinds are there, closed.
  await expect(sheet.getByRole('button', { name: 'Preaching' })).toHaveAttribute('aria-pressed', 'true');
  for (const closed of ['Testimony', 'Mission', 'Film']) await expect(sheet.getByRole('button', { name: closed })).toBeDisabled();
  await sheet.getByLabel('YouTube link').fill('https://youtu.be/e2ePreach02');
  await expect(sheet.getByText('Pastor Samuel Okoro - Hope in the Storm')).toBeVisible();
  await sheet.getByLabel('Why do you recommend it? (optional)').fill('It carried me through a hard winter.');
  await sheet.getByLabel('Your first name').fill('Samuel');
  await sheet.getByLabel(/Where are you from/).fill('Accra');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(/Thank you! We.ll let you know when it.s on air\./)).toBeVisible();

  // On air already, it goes to the library rather than airing twice.
  await decided(page, /^preaching (approved|scheduled|aired|library)$/);
  await page.context().close();
});

test('a mission program that also takes testimonies: its own video named "Mission"; the sheet opens on Mission, and a testimony goes through the check', async ({ browser }) => {
  test.setTimeout(10 * 60_000);
  await waitOnAir(mission.slug, /^mission e2eMission1$/);

  const page = await listener(browser, mission.slug);
  await page.getByRole('button', { name: 'Tap to join live' }).click();
  const player = page.getByRole('region', { name: 'Radio player' });
  await expect(player.getByText('Mission', { exact: true })).toBeVisible();
  await expect.poll(async () => (await ytNow(page))?.videoId).toBe('e2eMission1');

  await page.getByRole('button', { name: 'Suggest a video' }).first().click();
  const sheet = page.getByRole('dialog', { name: 'Suggest a video' });
  await expect(sheet).toBeInViewport();
  // The program's own kind first; what it does not take, closed.
  await expect(sheet.getByRole('button', { name: 'Mission' })).toHaveAttribute('aria-pressed', 'true');
  for (const closed of ['Preaching', 'Film']) await expect(sheet.getByRole('button', { name: closed })).toBeDisabled();
  await sheet.getByRole('button', { name: 'Testimony' }).click();
  await expect(sheet.getByRole('button', { name: 'Testimony' })).toHaveAttribute('aria-pressed', 'true');
  await expect(sheet.getByText(/someone tells what God has done in their life/)).toBeVisible();
  await sheet.getByLabel('YouTube link').fill('https://youtu.be/e2eTestim01');
  await expect(sheet.getByText('From the Streets to Hope - My Testimony')).toBeVisible();
  await sheet.getByLabel('Your first name').fill('Grace');
  await sheet.getByLabel(/Where are you from/).fill('Lagos');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(/Thank you! We.ll let you know when it.s on air\./)).toBeVisible();

  await decided(page, /^testimony_video (approved|scheduled|aired|library)$/);
  await page.context().close();
});

test('on a music program the video tile says it is not part of the program', async ({ browser }) => {
  const page = await listener(browser, 'main');
  await expect(page.getByRole('button', { name: 'Suggest a video · Not part of this program' }).first()).toBeVisible();
  await page.context().close();
});
