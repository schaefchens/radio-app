import { expect, test, type Browser, type Page } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { api, BASE_URL, ensureAdmin } from './support/station';

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

test('blocking: one listener no longer sees the other, the moderators learn of it, unblocking brings them back', async ({ browser }) => {
  const anna = await listener(browser, 'Anna');
  const ben = await listener(browser, 'Ben');
  await expect(anna.getByText(/2 people here/)).toBeVisible({ timeout: 30_000 });
  const rude = `Ben was here ${Date.now() % 10_000}`;
  await ben.getByPlaceholder('Say something kind…').fill(rude);
  await ben.getByRole('button', { name: 'Send' }).click();
  await expect(anna.getByText(rude)).toBeVisible();

  const row = anna.getByRole('listitem').filter({ hasText: rude });
  await row.getByRole('button', { name: 'Block' }).click();
  await row.getByRole('button', { name: 'Yes' }).click();
  await expect(anna.getByText(rude)).toHaveCount(0);
  await expect(ben.getByText(rude)).toBeVisible();

  // The node reports every 20 s: a "blocked" report reaches /mod.
  const admin = await ensureAdmin();
  await expect
    .poll(async () => (await api<{ reports: { text: string; reason: string }[] }>(admin, '/mod/reports')).data.reports.some((r) => r.text === rude && r.reason === 'blocked'), {
      timeout: 60_000,
      intervals: [3000],
    })
    .toBe(true);

  await anna.goto('/profile');
  await anna.getByRole('button', { name: 'Unblock' }).click();
  await anna.goto('/chat');
  await expect(anna.getByText(rude)).toBeVisible({ timeout: 30_000 });
  await Promise.all([anna.context().close(), ben.context().close()]);
});

test('the community rules come before the first message', async ({ browser }) => {
  const context = await browser.newContext({ baseURL: BASE_URL, serviceWorkers: 'block', bypassCSP: true });
  const page = await context.newPage();
  await fakeYouTube(page, { rules: false });
  await page.goto('/chat');
  await page.getByLabel('Display name').fill('Clara');
  const go = page.getByRole('button', { name: 'Continue' });
  await expect(go).toBeDisabled();
  await page.getByText('Read the rules').click();
  await expect(page.getByText('Zero tolerance')).toBeVisible();
  await page.getByLabel(/I accept the community rules/).check();
  await go.click();
  await expect(page.getByPlaceholder('Say something kind…')).toBeEnabled({ timeout: 30_000 });
  await context.close();
});
