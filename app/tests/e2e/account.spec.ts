import { expect, test } from '@playwright/test';
import { deriveCredential, generatePassphrase } from '../../src/lib/passphrase';
import { fakeYouTube } from './support/youtube';
import { api, BASE_URL, cron, liveFile, newDevice, type Device } from './support/station';

/**
 * Deleting an account (the app stores require it in the app; Google also on
 * the web): from Profile, and with the 12 words on /konto-loeschen for
 * someone who no longer has the app.
 */

async function prayOnWall(device: Device, text: string): Promise<void> {
  const r = await api(device, '/submissions/prayer', { body: { channel: 'main', text, name: 'Ruth', place: 'Lagos', lang: 'en', consent_air: true } });
  expect(r.status).toBe(200);
  // Checked by the stub AI in the jobs phase: wait until it is on the wall.
  await expect
    .poll(
      async () => {
        await cron();
        return (await liveFile())?.wall.some((e) => e.text === text);
      },
      { timeout: 90_000, intervals: [3000] },
    )
    .toBe(true);
}

test('Profile › Delete account: the account and what it sent go, then the device starts afresh', async ({ page }) => {
  const device = newDevice();
  const text = `Please pray for our village (${Date.now() % 100_000}).`;
  await api(device, '/me', { method: 'PATCH', body: { name: 'Ruth' } });
  await prayOnWall(device, text);

  await fakeYouTube(page);
  // This device, once: asDevice() would put it back after the reload that follows the deletion.
  await page.goto('/about');
  await page.evaluate((d) => localStorage.setItem('arche.device', JSON.stringify(d)), device);
  await page.goto('/profile');
  await page.getByRole('button', { name: 'Delete account' }).click();
  const sheet = page.getByRole('dialog', { name: 'Delete account' });
  await expect(sheet.getByText('Deleted at once:')).toBeVisible();
  await sheet.getByRole('button', { name: 'Delete everything' }).click();

  // A fresh start: home, with a new device id.
  await expect(page).toHaveURL(`${BASE_URL}/`);
  await expect.poll(async () => page.evaluate(() => JSON.parse(localStorage.getItem('arche.device') ?? '{}').id)).not.toBe(device.id);
  expect((await api<{ identity: unknown }>(device, '/me')).data.identity).toBeNull();
  expect((await liveFile())?.wall.some((e) => e.text === text)).toBe(false);
});

test('/konto-loeschen: with the 12 words from any browser; then they open nothing', async ({ page }) => {
  const words = generatePassphrase();
  const owner = newDevice();
  expect((await api(owner, '/identity/claim', { body: deriveCredential(words) })).status).toBe(200);

  await fakeYouTube(page, { welcome: true });
  await page.goto('/konto-loeschen');
  // A legal page opens on its content, not on the welcome.
  await expect(page.getByRole('dialog', { name: 'Welcome to Arche Radio!' })).toHaveCount(0);
  await page.getByLabel('Your 12 words').fill(words);
  await page.getByRole('button', { name: 'Delete account' }).last().click();
  await page.getByRole('dialog', { name: 'Delete account' }).getByRole('button', { name: 'Delete everything' }).last().click();
  await expect(page.getByText('Done: the account and everything sent from it are deleted.')).toBeVisible();

  const again = await api<{ error?: string }>(newDevice(), '/identity/login', { body: deriveCredential(words) });
  expect([again.status, again.data.error]).toEqual([401, 'unknown_passphrase']);
});
