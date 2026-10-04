import { expect, test } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { api, cron, ensureAdmin, liveFile, newDevice } from './support/station';

/**
 * Reporting a request on the prayer wall (the app stores require a report
 * for every kind of user content): hidden at once for whoever reported it,
 * in front of the moderators, off the wall and back by their decision.
 * Three listeners known for a day take it down by themselves
 * (server/tests/cases/reports.php); devices made just now, as here, do not.
 */
test('a prayer request reported on the wall: hidden for the reporter, in front of the moderators, down and back by their decision', async ({ page }) => {
  const sender = newDevice();
  const text = `Please pray for my exam (${Date.now() % 100_000}).`;
  expect((await api(sender, '/submissions/prayer', { body: { channel: 'main', text, name: 'Ida', place: 'Kiel', lang: 'en', consent_air: true } })).status).toBe(200);
  // The check runs in a tick; one per poll, as konsoleH's cron would.
  await expect
    .poll(
      async () => {
        await cron();
        return (await liveFile())?.wall.find((e) => e.text === text)?.id;
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toBeTruthy();
  const id = (await liveFile())!.wall.find((e) => e.text === text)!.id;

  await fakeYouTube(page);
  await page.goto('/');
  await page.getByRole('region', { name: 'Prayer wall' }).getByRole('button', { name: /More/ }).click({ timeout: 45_000 });
  const sheet = page.getByRole('dialog', { name: 'Prayer wall' });
  const entry = sheet.locator('.prayer-entry').filter({ hasText: text });
  await entry.getByRole('button', { name: 'Report' }).click();
  await entry.getByRole('button', { name: 'Yes' }).click();
  await expect(sheet.getByText(text)).toHaveCount(0);

  // Two more, from devices made just now: reported, but still on the wall for everyone else.
  for (let i = 0; i < 2; i++) expect((await api(newDevice(), `/wall/${id}/report`, { body: { reason: 'test' } })).status).toBe(200);
  expect((await liveFile())?.wall.some((e) => e.text === text)).toBe(true);

  const admin = await ensureAdmin();
  expect((await api<{ wallReports: number }>(admin, '/mod/overview')).data.wallReports).toBeGreaterThanOrEqual(1);
  expect((await api(admin, `/mod/review/${id.slice(1)}/wall`, { body: { hidden: true } })).status).toBe(200);
  expect((await liveFile())?.wall.some((e) => e.text === text)).toBe(false);
  expect((await api(admin, `/mod/review/${id.slice(1)}/wall`, { body: { hidden: false } })).status).toBe(200);
  expect((await liveFile())?.wall.some((e) => e.text === text)).toBe(true);
});
