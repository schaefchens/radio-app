import { expect, test, type Page } from '@playwright/test';
import type { EvergreenFile } from '@arche/shared';
import { fakeYouTube, ytCalls, ytNow, YT_PLAYING, type YtCall } from './support/youtube';
import { BASE_URL, CDN_URL, evergreen, itemAt, serverNow, waitForSong } from './support/station';

/**
 * The core promise: everyone hears the same second. What plays is a function
 * of server time and the published program, so these tests compute the same
 * thing from the outside and compare it with what the app told the player.
 */

const JOIN = 'Tap to join live';

// Waiting for a song can take a few minutes: a host break, a jingle, or a song
// another test pulled from air may be on air first.
test.describe.configure({ timeout: 5 * 60_000 });

async function join(page: Page): Promise<void> {
  await page.goto('/');
  await page.getByRole('button', { name: JOIN }).click();
}

async function firstLoad(page: Page): Promise<YtCall> {
  await expect.poll(async () => (await ytCalls(page)).some((c) => c.fn === 'load'), { timeout: 30_000 }).toBe(true);
  const load = (await ytCalls(page)).find((c) => c.fn === 'load');
  if (!load) throw new Error('no load call');
  return load;
}

async function playing(page: Page): Promise<void> {
  await expect.poll(async () => (await ytNow(page))?.state, { timeout: 30_000 }).toBe(YT_PLAYING);
}

/** Where the evergreen loop is at t, computed independently of the app. */
function loopAt(file: EvergreenFile, t: number): { yt: string; offsetMs: number } {
  let pos = (((t - file.epoch) % file.total) + file.total) % file.total;
  for (const track of file.items) {
    if (pos < track.dur) return { yt: track.yt, offsetMs: pos };
    pos -= track.dur;
  }
  throw new Error('position beyond the loop');
}

test('nothing is loaded from YouTube before the listener joins', async ({ page }) => {
  const google = await fakeYouTube(page);
  await page.goto('/');
  await expect(page.getByRole('button', { name: JOIN })).toBeVisible();
  // The program is fetched and shown already; only the player waits for consent.
  await page.waitForTimeout(2000);
  expect(google).toEqual([]);
  await page.getByRole('button', { name: JOIN }).click();
  await expect.poll(() => google.some((u) => u.startsWith('https://www.youtube.com/iframe_api'))).toBe(true);
  // The player itself comes from the privacy-enhanced host.
  await expect.poll(async () => (await ytCalls(page)).find((c) => c.fn === 'create')?.host).toBe('https://www.youtube-nocookie.com');
});

test('joining plays the song on air at the live position', async ({ page }) => {
  await waitForSong();
  await fakeYouTube(page);
  await join(page);
  const load = await firstLoad(page);

  const skew = (await serverNow()) - Date.now();
  const at = load.at + skew;
  const item = await itemAt(at);
  expect(item?.type, 'a song was on air when the player loaded').toBe('song');
  if (item?.type !== 'song') return;
  expect(load.videoId).toBe(item.yt);
  // The engine starts 0.4 s ahead to cover the load time.
  const expected = (at - item.start) / 1000 + 0.4;
  expect(Math.abs((load.startSeconds ?? 0) - expected)).toBeLessThan(1.5);
  await expect(page.getByText(item.title).first()).toBeVisible();
});

test('two listeners who joined at different times hear the same second', async ({ browser }) => {
  await waitForSong(40_000);
  const pages: Page[] = [];
  for (let i = 0; i < 2; i++) {
    const context = await browser.newContext({ baseURL: BASE_URL, serviceWorkers: 'block' });
    const page = await context.newPage();
    await fakeYouTube(page);
    await join(page);
    pages.push(page);
    if (i === 0) await page.waitForTimeout(4000);
  }
  const [a, b] = pages as [Page, Page];
  await playing(a);
  await playing(b);
  const na = await ytNow(a);
  const nb = await ytNow(b);
  if (!na || !nb) throw new Error('no player');
  expect(na.videoId).toBe(nb.videoId);
  // Both readings moved to the same instant.
  const drift = na.time + (nb.at - na.at) / 1000 - nb.time;
  expect(Math.abs(drift)).toBeLessThan(1.5);
  await Promise.all(pages.map((p) => p.context().close()));
});

test('without program files the evergreen loop plays, at the same position for everyone', async ({ page }) => {
  const loop = await evergreen();
  await fakeYouTube(page);
  // The generator (or the network) is gone: no minute files, no live file.
  await page.route(/\/program\/main\/(slots\/|live\.json)/, (route) => route.fulfill({ status: 404, body: '' }));
  await join(page);
  const load = await firstLoad(page);
  const skew = (await serverNow()) - Date.now();
  const at = load.at + skew;
  const expected = loopAt(loop, at);
  expect(load.videoId).toBe(expected.yt);
  expect(Math.abs((load.startSeconds ?? 0) - (expected.offsetMs / 1000 + 0.4))).toBeLessThan(1.5);
  await expect(page.getByText('Our favourites while the program reconnects')).toBeVisible();
});

test.describe('the CDN', () => {
  function programRequests(page: Page): string[] {
    const urls: string[] = [];
    page.on('request', (r) => {
      if (/\/program\//.test(r.url())) urls.push(r.url());
    });
    return urls;
  }

  test('carries the program; the page is allowed to read it', async ({ page }) => {
    const urls = programRequests(page);
    const csp: string[] = [];
    page.on('console', (m) => {
      if (/Content Security Policy/i.test(m.text())) csp.push(m.text());
    });
    await fakeYouTube(page);
    await join(page);
    await firstLoad(page);
    expect(urls.some((u) => u.startsWith(`${CDN_URL}/program/main/slots/`))).toBe(true);
    expect(urls.filter((u) => u.startsWith(BASE_URL))).toEqual([]);
    expect(csp).toEqual([]);
  });

  test('down: the radio plays from the site', async ({ page }) => {
    const urls = programRequests(page);
    await page.route(`${CDN_URL}/**`, (route) => route.abort('connectionfailed'));
    await fakeYouTube(page);
    await join(page);
    await firstLoad(page);
    expect(urls.some((u) => u.startsWith(`${BASE_URL}/program/main/slots/`))).toBe(true);
  });
});

test.describe('YouTube required minimum functionality', () => {
  for (const viewport of [
    { name: 'desktop', width: 1280, height: 900 },
    { name: 'phone', width: 390, height: 844 },
  ]) {
    test(`nothing covers the playing video and it is at least 200×200 (${viewport.name})`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await waitForSong();
      await fakeYouTube(page);
      await join(page);
      await playing(page);
      const frame = page.locator('iframe[data-fake-youtube]');
      const box = await frame.boundingBox();
      expect(box?.width ?? 0).toBeGreaterThanOrEqual(200);
      expect(box?.height ?? 0).toBeGreaterThanOrEqual(200);
      const covered = await page.evaluate(() => {
        const f = document.querySelector('iframe[data-fake-youtube]');
        if (!f) return ['no player'];
        const r = f.getBoundingClientRect();
        const points: [number, number][] = [[0.5, 0.5], [0.05, 0.05], [0.95, 0.05], [0.05, 0.95], [0.95, 0.95]];
        return points
          .map(([x, y]) => document.elementFromPoint(r.left + r.width * x, r.top + r.height * y))
          .filter((el) => el !== f)
          .map((el) => el?.outerHTML.slice(0, 120) ?? 'nothing');
      });
      expect(covered).toEqual([]);
    });
  }

  test('a sheet over the stage pauses the video; closing it resumes at the live position', async ({ page }) => {
    await waitForSong(40_000);
    await fakeYouTube(page);
    await join(page);
    await playing(page);
    await page.getByRole('button', { name: /Prayer Request/ }).first().click();
    await expect.poll(async () => (await ytNow(page))?.state).not.toBe(YT_PLAYING);
    await page.keyboard.press('Escape');
    await playing(page);
    const now = await ytNow(page);
    const skew = (await serverNow()) - Date.now();
    const item = await itemAt((now?.at ?? 0) + skew);
    if (item?.type !== 'song' || !now) throw new Error('no song');
    expect(now.videoId).toBe(item.yt);
    expect(Math.abs(now.time - ((now.at + skew - item.start) / 1000))).toBeLessThan(1.5);
  });
});
