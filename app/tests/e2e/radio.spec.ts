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

  // One player for every page: moving between Live and the others hands it
  // from one stage to the next without pausing (it once paused and sought
  // back in on every switch).
  await playing(page);
  const before = (await ytCalls(page)).length;
  await page.getByRole('link', { name: 'Schedule' }).click();
  await expect(page.getByRole('heading', { name: 'Schedule' })).toBeVisible();
  await page.waitForTimeout(1500);
  await page.getByRole('link', { name: 'Live' }).click();
  await page.waitForTimeout(1500);
  expect((await ytCalls(page)).slice(before).filter((c) => c.fn === 'pause' || c.fn === 'load' || c.fn === 'stop')).toEqual([]);
  expect((await ytNow(page))?.state).toBe(YT_PLAYING);

  // Pause: back to the start's state, and it stays there — no item starts it again.
  await page.getByRole('button', { name: 'Pause', exact: true }).click();
  await expect(page.getByRole('button', { name: JOIN })).toBeVisible();
  await expect.poll(async () => (await ytNow(page))?.state).not.toBe(YT_PLAYING);
  const calls = (await ytCalls(page)).length;
  await page.waitForTimeout(3000);
  expect((await ytCalls(page)).slice(calls).filter((c) => c.fn === 'load' || c.fn === 'play')).toEqual([]);
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
    { name: 'desktop', width: 1280, height: 900, theme: undefined },
    { name: 'phone', width: 390, height: 844, theme: undefined },
    // Storm Ark pulls the pinned player further up into the scenery.
    { name: 'phone, dark', width: 390, height: 844, theme: 'dark' as const },
  ]) {
    test(`nothing covers the playing video and it is at least 200×200 (${viewport.name})`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await waitForSong();
      await fakeYouTube(page, { theme: viewport.theme });
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

  test('on a phone the player stays pinned, uncovered and playing while the page scrolls', async ({ page }) => {
    // A short screen, so the page scrolls far enough to pin the player.
    await page.setViewportSize({ width: 390, height: 640 });
    await waitForSong();
    await fakeYouTube(page, { theme: 'dark' });
    await join(page);
    await playing(page);
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    const place = () =>
      page.evaluate(() => {
        const f = document.querySelector('iframe[data-fake-youtube]');
        const slot = document.querySelector('[data-stage-slot]');
        const dock = document.querySelector('.player-dock');
        if (!f || !slot || !dock) return null;
        const r = f.getBoundingClientRect();
        const s = slot.getBoundingClientRect();
        const points: [number, number][] = [[0.5, 0.5], [0.05, 0.05], [0.95, 0.05], [0.05, 0.95], [0.95, 0.95]];
        return {
          pinned: Math.round(dock.getBoundingClientRect().top) === Math.round(parseFloat(getComputedStyle(dock).top)),
          follows: Math.abs(r.top - s.top) < 1 && Math.abs(r.height - s.height) < 1,
          size: Math.min(r.width, r.height) >= 200,
          covered: points.filter(([x, y]) => document.elementFromPoint(r.left + r.width * x, r.top + r.height * y) !== f).length,
        };
      });
    await expect.poll(place).toEqual({ pinned: true, follows: true, size: true, covered: 0 });
    expect((await ytNow(page))?.state).toBe(YT_PLAYING);
    // At the bottom of the page the tiles unfold above the menu.
    await expect(page.locator('.mobile-dock')).toHaveAttribute('data-expanded', 'true');

    // Back up they fold away; a first tap unfolds them, the second one opens.
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(page.locator('.mobile-dock')).toHaveAttribute('data-expanded', 'false');
    const tile = page.locator('.mobile-dock').getByRole('button', { name: /Share a prayer request/ });
    await tile.click();
    await expect(page.locator('.mobile-dock')).toHaveAttribute('data-expanded', 'true');
    await expect(page.getByRole('dialog', { name: 'Share a prayer request' })).not.toBeInViewport();
    await tile.click();
    await expect(page.getByRole('dialog', { name: 'Share a prayer request' })).toBeInViewport();
  });

  test('a sheet over the stage pauses the video; closing it resumes at the live position', async ({ page }) => {
    await waitForSong(40_000);
    await fakeYouTube(page);
    await join(page);
    await playing(page);
    await page.getByRole('button', { name: /Share a prayer request/ }).first().click();
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
