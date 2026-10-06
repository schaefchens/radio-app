import { expect, test } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { asDevice, ensureAdmin } from './support/station';

/**
 * Every page fits a phone: nothing wider than the screen, so nothing scrolls
 * sideways (a grid column that grows with a long title once pushed the whole
 * home page — and the player — past the edge). Both themes: their scenery
 * and brand are placed differently.
 */

const LISTENER = ['/', '/schedule', '/chat', '/profile', '/setup', '/about', '/impressum', '/datenschutz'];
const MOD = ['/mod', '/mod/library', '/mod/groups', '/mod/programs', '/mod/plans', '/mod/lines', '/mod/review', '/mod/chat', '/mod/users', '/mod/channels', '/mod/hosts'];

for (const [width, theme] of [
  [360, 'light'],
  [390, 'light'],
  [360, 'dark'],
  [390, 'dark'],
] as const) {
  test(`no page scrolls sideways at ${width} px (${theme})`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await asDevice(page, await ensureAdmin());
    await fakeYouTube(page, { theme });
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
