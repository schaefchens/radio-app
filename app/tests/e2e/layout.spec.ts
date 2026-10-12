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

/**
 * The tiles in the desktop scenery (Home, from 1200 px) are placed by
 * percentages between things placed the same way: they must stay in the
 * sky — clear of the verse, the name and the menu, inside the scenery — at
 * the narrowest width they show at and on wide screens, where the scenery
 * stops growing.
 */
for (const theme of ['light', 'dark'] as const) {
  test(`the tiles in the scenery touch nothing there (${theme})`, async ({ page }) => {
    await fakeYouTube(page, { theme });
    const touching: string[] = [];
    for (const width of [1200, 1280, 1920]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await page.waitForLoadState('networkidle');
      await expect(page.locator('.hero-actions')).toBeVisible();
      const boxes = await page.evaluate(() => {
        const box = (selector: string) => {
          const r = document.querySelector(selector)!.getBoundingClientRect();
          return { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
        };
        return { tiles: box('.hero-actions'), hero: box('.hero'), verse: box('.hero-verse'), name: box('.brand'), menu: box('.desktop-nav') };
      });
      const { tiles, hero } = boxes;
      for (const [what, b] of Object.entries({ verse: boxes.verse, name: boxes.name, menu: boxes.menu })) {
        const apart = tiles.right <= b.left || b.right <= tiles.left || tiles.bottom <= b.top || b.bottom <= tiles.top;
        if (!apart) touching.push(`${width} px: the ${what}`);
      }
      if (tiles.left < hero.left || tiles.right > hero.right || tiles.top < hero.top || tiles.bottom > hero.bottom) touching.push(`${width} px: the scenery's edge`);
    }
    expect(touching).toEqual([]);
    // Narrower, the scenery is too low for them: only the tiles below the page.
    await page.setViewportSize({ width: 1100, height: 900 });
    await expect(page.locator('.hero-actions')).toBeHidden();
  });
}
