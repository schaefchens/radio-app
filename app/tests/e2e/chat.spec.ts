import { expect, test, type Browser, type Page } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { BASE_URL } from './support/station';

/**
 * The community room: wake (the static driver answers "ready" at once in the
 * e2e stack), a signed token, the realtime server, and back.
 *
 * The production CSP allows WebSockets only as wss: (the nodes sit behind
 * Caddy with TLS); the e2e node is plain ws://localhost:8797, so these tests
 * run with the page's CSP switched off.
 */
test.use({ bypassCSP: true });

async function listener(browser: Browser, name: string): Promise<Page> {
  const context = await browser.newContext({ baseURL: BASE_URL, serviceWorkers: 'block', bypassCSP: true });
  const page = await context.newPage();
  await fakeYouTube(page);
  await page.goto('/chat');
  await expect(page.getByText('Choose a name to join the room')).toBeVisible();
  await page.getByLabel('Display name').fill(name);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByPlaceholder('Say something kind…')).toBeEnabled({ timeout: 30_000 });
  return page;
}

test('two listeners meet in a room and read each other', async ({ browser }) => {
  const anna = await listener(browser, 'Anna');
  const ben = await listener(browser, 'Ben');
  await expect(anna.getByText(/2 people here/)).toBeVisible({ timeout: 30_000 });

  const hello = `Grace and peace from Anna ${Date.now() % 10_000}`;
  await anna.getByPlaceholder('Say something kind…').fill(hello);
  await anna.getByRole('button', { name: 'Send' }).click();
  await expect(ben.getByText(hello)).toBeVisible();
  await expect(anna.getByText(hello)).toBeVisible();

  await Promise.all([anna.context().close(), ben.context().close()]);
});
