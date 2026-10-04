import { expect, test, type Page } from '@playwright/test';
import { fakeYouTube, ytNow, YT_PLAYING } from './support/youtube';
import { waitForSong } from './support/station';

/**
 * The store apps load this very site, and the site is deployed ahead of every
 * installed app: a store-app-only path that throws would break every installed
 * app after a deploy, and no other test would see it. A stand-in shell — the
 * bridge and plugin list Capacitor injects at document start, but no native
 * side, so every plugin call fails — runs the production build under the real CSP.
 */
function standInShell(): void {
  Object.assign(window, {
    androidBridge: { postMessage() {} },
    Capacitor: {
      PluginHeaders: [
        { name: 'App', methods: [{ name: 'addListener', rtype: 'callback' }, { name: 'minimizeApp', rtype: 'promise' }] },
        { name: 'SplashScreen', methods: [{ name: 'hide', rtype: 'promise' }] },
        { name: 'KeepAwake', methods: [{ name: 'keepAwake', rtype: 'promise' }, { name: 'allowSleep', rtype: 'promise' }] },
        { name: 'SystemBars', methods: [{ name: 'setStyle', rtype: 'promise' }] },
      ],
      nativePromise: () => Promise.reject(new Error('no native side')),
      nativeCallback: () => 'callback-id',
    },
  });
}

async function setVisibility(page: Page, state: 'visible' | 'hidden'): Promise<void> {
  await page.evaluate((s) => {
    Object.defineProperty(document, 'visibilityState', { value: s, configurable: true });
    document.dispatchEvent(new Event('visibilitychange'));
  }, state);
}

test.describe.configure({ timeout: 5 * 60_000 });

test('the store app: no install offer, and the background stops the radio', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.addInitScript(standInShell);
  await fakeYouTube(page);
  await waitForSong();

  await page.goto('/?install=1');
  await expect(page.locator('html')).toHaveAttribute('data-native', 'android');
  await expect(page.locator('html')).toHaveAttribute('data-standalone', '');
  // An installed app is not offered for installing.
  await expect(page.getByRole('button', { name: 'Tap to join live' })).toBeVisible();
  await expect(page.getByRole('dialog', { name: 'Install Arche Radio' })).not.toBeInViewport();

  await page.getByRole('button', { name: 'Tap to join live' }).click();
  await expect.poll(async () => (await ytNow(page))?.state, { timeout: 30_000 }).toBe(YT_PLAYING);

  // Home button: nothing may play in the background (YouTube's terms).
  await setVisibility(page, 'hidden');
  await setVisibility(page, 'visible');
  await expect(page.getByText('Background playback is not possible on phones')).toBeVisible();
  await expect.poll(async () => (await ytNow(page))?.state).not.toBe(YT_PLAYING);

  // One tap joins live again.
  await page.getByRole('button', { name: 'Tap to join live' }).click();
  await expect.poll(async () => (await ytNow(page))?.state, { timeout: 30_000 }).toBe(YT_PLAYING);
  await expect(page.getByText('Background playback is not possible on phones')).toHaveCount(0);

  // Failing plugin calls are the shell's business, never the page's.
  expect(errors).toEqual([]);
});
