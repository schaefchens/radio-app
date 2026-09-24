import { expect, test } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { api, asDevice, ensureAdmin, liveFile, newDevice, serverNow, slotAt } from './support/station';

/**
 * The moderator side, as the station's administrator (made by the global
 * setup exactly as in production: passphrase, then /setup).
 */

test('setup is closed once the station has its administrator', async ({ page }) => {
  await fakeYouTube(page);
  await page.goto('/setup');
  await expect(page.getByText('ARCHE is already set up.')).toBeVisible();
});

test('a listener is sent away from /mod', async ({ page }) => {
  await fakeYouTube(page);
  await page.goto('/mod/library');
  await expect(page).toHaveURL(/\/$/);
  // …and the API agrees.
  expect((await api(newDevice(), '/mod/overview')).status).toBe(403);
});

test('the library lists the curated songs', async ({ page }) => {
  await asDevice(page, await ensureAdmin());
  await fakeYouTube(page);
  await page.goto('/mod/library');
  await expect(page.getByText('Morning Light').first()).toBeVisible();
  await expect(page.getByText('Steady Heart').first()).toBeVisible();
  // Titles were split from "Artist - Title (Official Video)".
  await expect(page.getByText('E2E Worship').first()).toBeVisible();
});

test('pulling a song from air reaches listeners through live.json; enabling it again brings it back', async ({ page }) => {
  const admin = await ensureAdmin();
  // A song committed well ahead, so pulling it has airings to block — the
  // farthest one: the other tests want music now, not the fallback loop.
  const now = await serverNow();
  // Minute files exist up to ~5 minutes ahead and each covers the next 10.
  const slot = await slotAt(now + 4 * 60_000);
  const upcoming = slot?.items.filter((i) => i.type === 'song' && i.start > now + 5 * 60_000).at(-1);
  test.skip(!upcoming || upcoming.type !== 'song', 'no song committed ahead right now');
  if (!upcoming || upcoming.type !== 'song') return;

  await asDevice(page, admin);
  await fakeYouTube(page);
  await page.goto('/mod/library');
  const row = page.locator('li', { hasText: upcoming.yt });
  await row.getByRole('button', { name: 'Pull from air' }).click();
  await row.getByRole('button', { name: 'Yes' }).click();
  await expect(page.getByText(/Pulled from air \(\d+ airings?\)\./)).toBeVisible();
  expect((await liveFile())?.blocked).toContain(upcoming.id);

  await row.getByRole('button', { name: 'Activate' }).click();
  await expect(row.getByText('Active', { exact: true })).toBeVisible();
});

test('the status page shows the generator running', async ({ page }) => {
  await asDevice(page, await ensureAdmin());
  await fakeYouTube(page);
  await page.goto('/mod');
  await expect(page.getByRole('link', { name: 'Library' })).toBeVisible();
  await expect(page.getByText(/tick/i).first()).toBeVisible();
});
