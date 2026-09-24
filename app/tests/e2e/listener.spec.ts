import { expect, test, type Browser, type Locator, type Page } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { api, BASE_URL, cron, liveFile, type Device } from './support/station';

/**
 * What a listener does besides listening: the schedule, a profile with a
 * passphrase that follows them to another device, and the three ways to
 * take part (a song, a prayer, a recording) through moderation to the air.
 */

async function listener(browser: Browser, opts: Parameters<Browser['newContext']>[0] = {}): Promise<Page> {
  const context = await browser.newContext({ baseURL: BASE_URL, serviceWorkers: 'block', ...opts });
  const page = await context.newPage();
  await fakeYouTube(page);
  return page;
}

async function deviceOf(page: Page): Promise<Device> {
  const raw = await page.evaluate(() => localStorage.getItem('arche.device'));
  if (!raw) throw new Error('the app has not minted a device yet');
  return JSON.parse(raw) as Device;
}

interface MySubmission {
  id: string;
  type: string;
  status: string;
  reason: string | null;
  title: string;
}

/** Moderation runs as a job on the next ticks; drive the cron until the submission leaves "pending". */
async function decided(device: Device, id: string): Promise<MySubmission> {
  let last: MySubmission | undefined;
  await expect
    .poll(
      async () => {
        await cron();
        const r = await api<{ submissions: MySubmission[] }>(device, '/submissions');
        last = r.data.submissions.find((s) => s.id === id);
        return last?.status ?? 'missing';
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .not.toMatch(/^(pending|missing)$/);
  if (!last) throw new Error('submission vanished');
  return last;
}

const SENT = /Thank you! We.ll let you know when it.s on air\./;

/** Open a submission sheet from Home. Closed sheets stay mounted, so everything is looked up inside the open one. */
async function openSheet(page: Page, tile: RegExp, title: string): Promise<Locator> {
  await page.getByRole('button', { name: tile }).first().click();
  const sheet = page.getByRole('dialog', { name: title });
  await expect(sheet).toBeInViewport();
  return sheet;
}

/** Join first: the song preview (oEmbed) is a request to YouTube and waits for consent. */
async function joined(page: Page): Promise<void> {
  await page.goto('/');
  await page.getByRole('button', { name: 'Tap to join live' }).click();
}

async function latestSubmission(device: Device): Promise<MySubmission> {
  const r = await api<{ submissions: MySubmission[] }>(device, '/submissions');
  const s = r.data.submissions[0];
  if (!s) throw new Error('no submission recorded');
  return s;
}

test('the schedule shows today’s programs in local time', async ({ page }) => {
  await fakeYouTube(page);
  await page.goto('/schedule');
  await expect(page.getByRole('heading', { name: 'Schedule' })).toBeVisible();
  await expect(page.getByText('ARCHE Live').first()).toBeVisible();
  await expect(page.getByText(/Times are in your local time/)).toBeVisible();
});

test('a passphrase takes the listener’s identity to a second device', async ({ browser }) => {
  const first = await listener(browser);
  await first.goto('/profile');
  const name = `Miriam ${Date.now() % 10_000}`;
  await first.getByLabel('Display name').fill(name);
  await first.getByRole('button', { name: 'Save' }).first().click();
  await expect(first.getByText('Saved', { exact: true })).toBeVisible();

  const panel = first.locator('section', { has: first.getByRole('heading', { name: 'Your 12-word passphrase' }) });
  await panel.getByRole('button', { name: 'Create a passphrase' }).click();
  const words = (await panel.locator('ol li span.text-ink').allTextContents()).map((w) => w.trim());
  expect(words).toHaveLength(12);
  await panel.getByLabel('I have written these 12 words down').check();
  await panel.getByRole('button', { name: 'Save' }).click();
  await expect(panel.getByText('This device is linked to your passphrase.')).toBeVisible();

  const second = await listener(browser);
  await second.goto('/profile');
  const panel2 = second.locator('section', { has: second.getByRole('heading', { name: 'Your 12-word passphrase' }) });
  await panel2.getByRole('button', { name: 'I already have a passphrase' }).click();
  await panel2.getByLabel('Enter your 12 words').fill(words.join(' '));
  await panel2.getByRole('button', { name: 'Use this passphrase' }).click();
  await expect(panel2.getByText('This device is linked to your passphrase.')).toBeVisible();
  await expect(second.getByLabel('Display name')).toHaveValue(name);

  await Promise.all([first.context().close(), second.context().close()]);
});

test('a prayer request is checked, accepted and shown as a community voice', async ({ page }) => {
  await fakeYouTube(page);
  await page.goto('/');
  const sheet = await openSheet(page, /Prayer Request/, 'Prayer Request');
  const text = `Please pray for my sister's recovery (${Date.now() % 100_000}).`;
  await sheet.getByLabel('Your prayer request').fill(text);
  await sheet.getByLabel('Show it to the community so others can pray with me.').check();
  await sheet.getByLabel('Your first name').fill('Ruth');
  await sheet.getByLabel(/Where are you from/).fill('Lagos');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(SENT)).toBeVisible();

  const device = await deviceOf(page);
  const sub = await latestSubmission(device);
  expect(sub.type).toBe('prayer');
  expect((await decided(device, sub.id)).status).toMatch(/approved|scheduled|aired/);
  // The next tick publishes it in live.json; the app reads that every 30 s.
  await expect
    .poll(
      async () => {
        await cron();
        return (await liveFile())?.voices.some((v) => v.text === text);
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toBe(true);
  await page.keyboard.press('Escape');
  await expect(page.getByText(text)).toBeVisible({ timeout: 45_000 });
});

test('before the listener agrees to YouTube, a song request asks YouTube nothing', async ({ page }) => {
  const google = await fakeYouTube(page);
  await page.goto('/');
  const sheet = await openSheet(page, /Request a Song/, 'Request a Song');
  await sheet.getByLabel('YouTube link').fill('https://youtu.be/e2eReqOk001');
  await page.waitForTimeout(1500);
  expect(google).toEqual([]);
  // The server checks the video anyway, so sending is possible.
  await expect(sheet.getByRole('button', { name: 'Send' })).toBeEnabled();
});

test('a song request is checked against YouTube and accepted', async ({ page }) => {
  await fakeYouTube(page);
  await joined(page);
  const sheet = await openSheet(page, /Request a Song/, 'Request a Song');
  await sheet.getByLabel('YouTube link').fill('https://youtu.be/e2eReqOk001');
  await expect(sheet.getByText('Hillside Choir - Carried (Lyric Video)')).toBeVisible();
  await sheet.getByLabel('Dedication or greeting (optional)').fill('For everyone who needs courage today.');
  await sheet.getByLabel('Your first name').fill('Jonas');
  await sheet.getByLabel(/Where are you from/).fill('Hamburg');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(SENT)).toBeVisible();

  const device = await deviceOf(page);
  const sub = await latestSubmission(device);
  expect(sub.type).toBe('song');
  expect((await decided(device, sub.id)).status).toMatch(/approved|scheduled|aired|library/);
});

test('a song that is too long is declined with a general reason', async ({ page }) => {
  await fakeYouTube(page);
  await joined(page);
  const sheet = await openSheet(page, /Request a Song/, 'Request a Song');
  await sheet.getByLabel('YouTube link').fill('https://www.youtube.com/watch?v=e2eReqLong1');
  await expect(sheet.getByText('E2E Worship - Ten Thousand Reasons Medley')).toBeVisible();
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(SENT)).toBeVisible();
  const device = await deviceOf(page);
  const done = await decided(device, (await latestSubmission(device)).id);
  expect(done.status).toBe('rejected');
  expect(['not_program_fit', 'not_suitable', 'not_accepted']).toContain(done.reason);
});

test('a video that cannot be embedded is refused before sending', async ({ page }) => {
  await fakeYouTube(page);
  await joined(page);
  const sheet = await openSheet(page, /Request a Song/, 'Request a Song');
  await sheet.getByLabel('YouTube link').fill('https://www.youtube.com/watch?v=e2eNoEmbed1');
  await expect(sheet.getByText(/This video can.t be played in other apps\./)).toBeVisible();
  await expect(sheet.getByRole('button', { name: 'Send' })).toBeDisabled();
});

test.describe('recordings', () => {
  test.use({ permissions: ['microphone'] });

  test('a recorded story is encoded in the browser, checked and accepted', async ({ page }) => {
    await fakeYouTube(page);
    await page.goto('/');
    const sheet = await openSheet(page, /Share Your Story/, 'Share Your Story');
    await sheet.getByRole('button', { name: 'Start recording' }).click();
    await page.waitForTimeout(4500);
    await sheet.getByRole('button', { name: 'Stop' }).click();
    await expect(sheet.getByLabel('Listen back')).toBeVisible();
    await sheet.getByLabel('Your first name').fill('Esther');
    await sheet.getByLabel(/Where are you from/).fill('Nairobi');
    await sheet.getByLabel('I agree that this is broadcast on ARCHE.').check();
    await sheet.getByRole('button', { name: 'Send' }).click();
    await expect(sheet.getByText(SENT)).toBeVisible({ timeout: 30_000 });

    const device = await deviceOf(page);
    const sub = await latestSubmission(device);
    expect(sub.type).toBe('story');
    expect((await decided(device, sub.id)).status).toMatch(/approved|scheduled|aired/);
  });
});
