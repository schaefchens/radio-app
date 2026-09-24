import { expect, test } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { api, asDevice, cron, ensureAdmin, liveFile, newDevice, serverNow, slotAt, type Device } from './support/station';

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
  // Minute files exist up to ~2 minutes ahead (between ticks one less) and
  // each covers the next 3.
  const slot = (await slotAt(now + 2 * 60_000)) ?? (await slotAt(now + 60_000));
  const upcoming = slot?.items.filter((i) => i.type === 'song' && i.start > now + 30_000).at(-1);
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

/** A listener requests a song through the API; the cron drives its moderation until it is decided. */
async function rejectedRequest(yt: string): Promise<{ device: Device; id: string }> {
  const device = newDevice();
  const r = await api<{ submission: { id: string } }>(device, '/submissions/song', {
    body: { url: `https://www.youtube.com/watch?v=${yt}`, name: 'Ruth', place: 'Moab', lang: 'en' },
  });
  expect(r.status).toBe(200);
  const id = r.data.submission.id;
  await expect
    .poll(
      async () => {
        await cron();
        const mine = await api<{ submissions: { id: string; status: string }[] }>(device, '/submissions');
        return mine.data.submissions.find((s) => s.id === id)?.status;
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toBe('rejected');
  return { device, id };
}

test('a moderator sees why a request was rejected and can approve it anyway', async ({ page }) => {
  // Too long for the station's rule (a moderator may overrule it) and
  // unembeddable (nobody can: YouTube would not play it).
  const long = await rejectedRequest('e2eReqLong1');
  const noEmbed = await rejectedRequest('e2eNoEmbed1');
  await asDevice(page, await ensureAdmin());
  await fakeYouTube(page);
  await page.goto('/mod/review');
  await page.getByRole('button', { name: 'Rejected' }).click();

  const blocked = page.locator('section', { hasText: `#${noEmbed.id}` });
  await expect(blocked.getByText('YouTube: the owner does not allow embedding')).toBeVisible();
  await expect(blocked.getByText(/cannot air/)).toBeVisible();
  await expect(blocked.getByRole('button', { name: 'Approve anyway' })).toHaveCount(0);

  const card = page.locator('section', { hasText: `#${long.id}` });
  await expect(card.getByText('Longer than the station allows (15:00)')).toBeVisible();
  await expect(card.getByText('the listener was told: Not suitable')).toBeVisible();
  await card.getByRole('button', { name: 'Approve anyway' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await expect(page.locator('section', { hasText: `#${long.id}` })).toHaveCount(0);

  const mine = await api<{ submissions: { id: string; status: string }[] }>(long.device, '/submissions');
  // `missed` when an earlier run already switched the song off (below).
  expect(mine.data.submissions.find((s) => s.id === long.id)?.status).toMatch(/approved|scheduled|aired|library|missed/);

  // Leave the rotation as it was: a 15-minute song in it would fill the whole
  // published window again and again (switched off, not pulled: an airing
  // already fixed stays, so the tests after this one still find music).
  const admin = await ensureAdmin();
  const lib = await api<{ items: { id: number; yt_id: string }[] }>(admin, '/mod/library?q=e2eReqLong1');
  for (const item of lib.data.items.filter((i) => i.yt_id === 'e2eReqLong1')) {
    expect((await api(admin, `/mod/library/${item.id}`, { method: 'PATCH', body: { active: false } })).status).toBe(200);
  }
});
