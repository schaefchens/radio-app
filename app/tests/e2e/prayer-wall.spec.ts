import { expect, test, type Locator, type Page } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { api, cron, ensureAdmin, liveFile, type Admin } from './support/station';

/**
 * The prayer wall. Any program can show one, so for this file the regular
 * program does (a prayer hour would close song requests for the other
 * specs): a request its sender agreed to show appears on it, a listener
 * prays along, a moderator takes it down.
 */

interface Program {
  id: number;
  settings: Record<string, unknown>;
}

let admin: Admin;
let program: Program;

async function setWall(enabled: boolean): Promise<void> {
  const r = await api(admin, `/mod/programs/${program.id}`, { method: 'PATCH', body: { settings: { ...program.settings, wall: { enabled, keep_min: 0 } } } });
  if (r.status !== 200) throw new Error(`setting the wall: ${r.status} ${JSON.stringify(r.data)}`);
  await cron();
}

test.beforeAll(async () => {
  admin = await ensureAdmin();
  const overview = await api<{ channels: { programs: Program[] }[] }>(admin, '/mod/overview');
  program = overview.data.channels[0]!.programs[0]!;
  await setWall(true);
});

test.afterAll(async () => {
  await setWall(false);
});

async function openSheet(page: Page, tile: RegExp, title: string): Promise<Locator> {
  await page.getByRole('button', { name: tile }).first().click();
  const sheet = page.getByRole('dialog', { name: title });
  await expect(sheet).toBeInViewport();
  return sheet;
}

test('a prayer request on the wall: shown with its sender\'s agreement, prayed along with, taken down by a moderator', async ({ page }) => {
  await fakeYouTube(page);
  await page.goto('/');
  const sheet = await openSheet(page, /Prayer Request/, 'Prayer Request');
  const text = `Please pray for our neighbours (${Date.now() % 100_000}).`;
  await sheet.getByLabel('Your prayer request').fill(text);
  const show = sheet.getByLabel('Also show my request to the community, so others can pray with me.');
  await expect(show).not.toBeChecked(); // a clear yes, never pre-ticked
  await show.check();
  await sheet.getByLabel('Your first name').fill('Miriam');
  await sheet.getByLabel(/Where are you from/).fill('Kiel');
  await sheet.getByRole('button', { name: 'Send' }).click();
  await expect(sheet.getByText(/Thank you!/)).toBeVisible();

  // Checked on the next ticks, then in live.json, which the app reads every 30 s.
  await expect
    .poll(
      async () => {
        await cron();
        return (await liveFile())?.wall?.entries.some((e) => e.text === text) ?? false;
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toBe(true);
  await page.keyboard.press('Escape');
  const wall = page.getByRole('region', { name: 'Prayer wall' });
  const card = wall.getByRole('listitem').filter({ hasText: text });
  await expect(card).toBeVisible({ timeout: 45_000 });
  await expect(card.getByText('Miriam')).toBeVisible();

  await card.getByRole('button', { name: /^Pray along/ }).click();
  const prayed = card.getByRole('button', { name: 'You prayed along (1 praying)' });
  await expect(prayed).toHaveAttribute('aria-pressed', 'true');
  await page.reload();
  await expect(page.getByRole('region', { name: 'Prayer wall' }).getByRole('button', { name: 'You prayed along (1 praying)' })).toBeVisible({ timeout: 45_000 });

  for (const width of [360, 390]) {
    await page.setViewportSize({ width, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `no sideways scroll at ${width} px`).toBe(true);
  }

  const id = (await liveFile())!.wall!.entries.find((e) => e.text === text)!.id.slice(1);
  expect((await api(admin, `/mod/review/${id}/wall`, { body: { hidden: true } })).status).toBe(200);
  expect((await liveFile())?.wall?.entries.some((e) => e.text === text)).toBe(false);
  await expect(page.getByRole('region', { name: 'Prayer wall' }).getByText(text)).toBeHidden({ timeout: 45_000 });
});
