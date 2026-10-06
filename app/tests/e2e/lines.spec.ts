import { expect, test } from '@playwright/test';
import { fakeYouTube } from './support/youtube';
import { api, asDevice, cron, ensureAdmin, type Admin } from './support/station';

/**
 * Recorded host lines end to end, on a channel of their own: an admin has the
 * station's host write encouragements in /mod › Lines, the jobs write and
 * record them (stub AI and the stub voice: no service is called), they go on
 * air by themselves, one is paused, and a program switches to recorded lines
 * in its editor. Which line airs when is walked on the server's fixed clock
 * (server/tests/cases/lines.php).
 */

test.describe.configure({ mode: 'serial' });

let admin: Admin;
const channel = { id: 0, slug: '', name: '' };
let programId = 0;
let hostId = 0;
const stamp = Date.now().toString(36);

async function must<T>(call: Promise<{ status: number; data: T }>, what: string): Promise<T> {
  const { status, data } = await call;
  if (status !== 200) throw new Error(`${what}: ${status} ${JSON.stringify(data)}`);
  return data;
}

/** How many of the host's encouragements are in this state now. */
async function count(state: 'active' | 'paused'): Promise<number> {
  const r = await must(api<{ total: number }>(admin, `/mod/lines?host=${hostId}&kind=encourage&state=${state}&limit=1`), 'lines');
  return r.total;
}

test.beforeAll(async () => {
  admin = await ensureAdmin();
  const { hosts } = await must(api<{ hosts: { id: number; active: boolean; provider: string }[] }>(admin, '/mod/hosts'), 'hosts');
  // An OpenAI host records at once; an ElevenLabs one waits for an admin's monthly allowance.
  hostId = (hosts.find((h) => h.active && h.provider === 'openai') ?? hosts[0])!.id;
  channel.slug = `lines-${stamp}`;
  channel.name = `Lines ${stamp}`;
  const ch = await must(
    api<{ channel: { id: number } }>(admin, '/mod/channels', { body: { slug: channel.slug, name_en: channel.name, name_de: channel.name, timezone: 'Europe/Berlin' } }),
    'channel',
  );
  channel.id = ch.channel.id;
  const programs = await must(api<{ programs: { id: number }[] }>(admin, `/mod/channels/${channel.id}/programs`), 'programs');
  programId = programs.programs[0]!.id;
  // The host on air in this program: its lines are the ones the editor offers.
  await must(api(admin, `/mod/programs/${programId}`, { method: 'PATCH', body: { hosts: [{ id: hostId, role: 'main' }] } }), 'lineup');
});

test.afterAll(async () => {
  // The other specs see one channel again.
  if (channel.id) await api(admin, `/mod/channels/${channel.id}`, { method: 'PATCH', body: { active: false } });
});

test('an admin has encouragements written, they go on air by themselves, one is paused', async ({ page }) => {
  test.setTimeout(4 * 60_000);
  const before = await count('active');
  await asDevice(page, admin);
  await fakeYouTube(page);
  await page.goto(`/mod/lines?host=${hostId}`);
  await page.getByRole('button', { name: 'Write new lines' }).click();
  const form = page.locator('form', { has: page.getByRole('button', { name: 'Write and record' }) });
  await form.getByLabel('Kind').selectOption('encourage');
  await form.getByLabel('How many').fill('3');
  await form.getByLabel('A note for the writer (optional)').fill(`e2e ${stamp}`);
  await form.getByRole('button', { name: 'Write and record' }).click();
  await expect(page.getByText('3 lines are being written and recorded.')).toBeVisible();

  // The jobs write them, then record each language; new lines go live by default.
  await expect
    .poll(
      async () => {
        await cron();
        return count('active');
      },
      { timeout: 3 * 60_000, intervals: [5000] },
    )
    .toBeGreaterThanOrEqual(before + 3);

  const paused = await count('paused');
  await page.reload();
  // The filter row's: the options card has a "kind" in a label too.
  const filters = page.getByRole('form', { name: 'Filter the lines' });
  await filters.getByLabel('Kind').selectOption('encourage');
  await filters.getByLabel('State').selectOption('active');
  const row = page.locator('li').filter({ has: page.getByRole('button', { name: 'Pause' }) }).first();
  await row.getByRole('button', { name: 'Pause' }).click();
  await expect(page.getByText('Line saved.')).toBeVisible();
  expect(await count('paused')).toBe(paused + 1);
});

test('a program takes its host\'s words from recorded lines', async ({ page }) => {
  await asDevice(page, admin);
  await fakeYouTube(page);
  await page.goto('/mod/programs');
  await page.getByLabel('Channel').selectOption({ label: channel.name });
  await page.getByRole('button', { name: 'Edit' }).first().click();
  await page.getByRole('radio', { name: 'From recorded lines' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved', { exact: true })).toBeVisible();
  const { programs } = await must(api<{ programs: { id: number; lines?: { mode: string; kinds: string[] } }[] }>(admin, `/mod/channels/${channel.id}/programs`), 'programs');
  const lines = programs.find((p) => p.id === programId)?.lines;
  expect(lines?.mode).toBe('library');
  expect(lines?.kinds).toContain('intro');
});
