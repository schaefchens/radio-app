import { expect, test } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { BASE_URL } from './support/station';

/**
 * The first visit: a welcome with the language and the theme, both applied
 * at once, shown once. Profile changes the theme later. Nothing of it may
 * reach Google — the listener has not agreed to YouTube yet.
 */

test('a new listener is welcomed once and picks language and theme', async ({ page }) => {
  const google = await fakeYouTube(page, { welcome: true });
  await page.goto('/');
  const dialog = page.getByRole('dialog', { name: 'Welcome to Arche Radio!' });
  await expect(dialog).toBeVisible();
  await dialog.getByLabel('Language').selectOption('de');
  // Applied at once: the dialog itself is German now.
  await expect(page.getByRole('dialog', { name: 'Willkommen bei Arche Radio!' })).toBeVisible();
  const welcome = page.getByRole('dialog', { name: 'Willkommen bei Arche Radio!' });
  await welcome.getByLabel('Sturm', { exact: true }).check();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await welcome.getByRole('button', { name: "Los geht's" }).click();
  // The closed submission sheets are dialogs too: look for this one by name.
  await expect(welcome).toHaveCount(0);
  await expect(page.locator('html')).toHaveAttribute('lang', 'de');

  await page.reload();
  await expect(page.getByRole('button', { name: 'Tippen und live dabei sein' })).toBeVisible();
  await expect(page.getByRole('dialog', { name: /Willkommen/ })).toHaveCount(0);
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  expect(google).toEqual([]);
});

test('the theme follows the device until Profile picks one', async ({ browser }) => {
  const context = await browser.newContext({ baseURL: BASE_URL, colorScheme: 'dark', serviceWorkers: 'block' });
  const page = await context.newPage();
  await fakeYouTube(page);
  await page.goto('/profile');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.getByLabel('Kids', { exact: true }).check();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.getByLabel('Automatic').check();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await context.close();
});

test('an install link waits for the welcome, then offers the app once', async ({ page }) => {
  await fakeYouTube(page, { welcome: true });
  await page.goto('/schedule?install=1');
  const welcome = page.getByRole('dialog', { name: 'Welcome to Arche Radio!' });
  const install = page.getByRole('dialog', { name: 'Install Arche Radio' });
  await expect(welcome).toBeVisible();
  // Gone from the address bar at once: a link shared from it must not install.
  await expect.poll(() => new URL(page.url()).pathname + new URL(page.url()).search).toBe('/schedule');
  // Two modals at once, and the sheet would sit under the dialog's top layer.
  await expect(install).not.toBeInViewport();
  await welcome.getByRole('button', { name: "Let's get started" }).click();
  // Chrome's own dialog behind a button, or the written steps (headless
  // Chromium may never offer an install): the sheet either way.
  await expect(install).toBeInViewport();
  await install.getByRole('button', { name: 'Close' }).first().click();
  await expect(install).not.toBeInViewport();

  // Answered: a reload does not ask again.
  await page.reload();
  await expect(page.getByRole('button', { name: 'Tap to join live' })).toBeVisible();
  await expect(install).toHaveCount(0);
});
