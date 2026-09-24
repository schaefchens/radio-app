import { expect, test } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { asDevice, ensureAdmin } from './support/station';

/**
 * Every page fits a phone: nothing wider than the screen, so nothing scrolls
 * sideways (a grid column that grows with a long title once pushed the whole
 * home page — and the player — past the edge).
 */

const LISTENER = ['/', '/schedule', '/chat', '/profile', '/setup'];
const MOD = ['/mod', '/mod/library', '/mod/programs', '/mod/plans', '/mod/review', '/mod/chat', '/mod/users', '/mod/channels'];

for (const width of [360, 390]) {
  test(`no page scrolls sideways at ${width} px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await asDevice(page, await ensureAdmin());
    await fakeYouTube(page);
    const wide: string[] = [];
    for (const path of [...LISTENER, ...MOD]) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(300);
      const w = await page.evaluate(() => document.documentElement.scrollWidth);
      if (w > width) wide.push(`${path}: ${w} px`);
    }
    expect(wide).toEqual([]);
  });
}
