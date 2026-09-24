import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { randomBytes, randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import type { Page } from '@playwright/test';
import type { ChannelsFile, EvergreenFile, LiveFile, SlotFile, TimelineItem } from '@arche/shared';
import { MINUTE_MS, livePath, slotPath } from '@arche/shared';
import { deriveCredential, generatePassphrase } from '../../../src/lib/passphrase';

/**
 * The e2e station from the outside: its env file, a device for the API, the
 * cron, and the published program — the same view a listener's app has.
 * Everything here runs in the test process (Node), not in the page.
 */

const REPO = fileURLToPath(new URL('../../../../', import.meta.url));
export const E2E_DIR = path.join(REPO, '.data/e2e');
export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8090';
/** The stack's stand-in for BunnyCDN: the same files, cross-origin (docker/web/arche.conf). */
export const CDN_URL = process.env.E2E_CDN_URL ?? 'http://localhost:8091';
export const CHANNEL = 'main';

export function e2eEnv(): Record<string, string> {
  const file = path.join(E2E_DIR, 'arche.env');
  if (!existsSync(file)) throw new Error(`${file} is missing — start the stack: bash scripts/e2e-stack.sh up`);
  const out: Record<string, string> = {};
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    const m = /^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$/.exec(line);
    if (m?.[1] && m[2] !== undefined) out[m[1]] = m[2].trim();
  }
  return out;
}

export interface Device {
  id: string;
  secret: string;
}

export interface Admin extends Device {
  words: string;
  publicId: string;
}

export function newDevice(): Device {
  return { id: randomUUID(), secret: randomBytes(32).toString('hex') };
}

export async function api<T = Record<string, unknown>>(
  device: Device | null,
  apiPath: string,
  opts: { method?: string; body?: unknown } = {},
): Promise<{ status: number; data: T }> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (device) Object.assign(headers, { 'X-Arche-Id': device.id, 'X-Arche-Secret': device.secret });
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  const res = await fetch(`${BASE_URL}/api${apiPath}`, {
    method: opts.method ?? (opts.body !== undefined ? 'POST' : 'GET'),
    headers,
    body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
  });
  const data = (await res.json().catch(() => null)) as T;
  return { status: res.status, data };
}

const ADMIN_FILE = path.join(E2E_DIR, 'admin.json');

/**
 * The station's administrator, made exactly as in production: a device claims
 * a passphrase, then /setup with ADMIN_SETUP_KEY. Kept in .data/e2e so later
 * runs reuse it (setup works once per station).
 */
export async function ensureAdmin(): Promise<Admin> {
  if (existsSync(ADMIN_FILE)) {
    const admin = JSON.parse(readFileSync(ADMIN_FILE, 'utf8')) as Admin;
    const r = await api(admin, '/mod/overview');
    if (r.status === 200) return admin;
    throw new Error(`the saved e2e admin is no moderator any more (${r.status}) — bash scripts/e2e-stack.sh reset`);
  }
  const device = newDevice();
  const words = generatePassphrase();
  const claim = await api<{ identity: { id: string } }>(device, '/identity/claim', { body: deriveCredential(words) });
  if (claim.status !== 200) throw new Error(`claim failed: ${claim.status} ${JSON.stringify(claim.data)}`);
  const setup = await api(device, '/setup/admin', { body: { key: e2eEnv().ADMIN_SETUP_KEY } });
  if (setup.status !== 200) throw new Error(`setup failed: ${setup.status} ${JSON.stringify(setup.data)} — bash scripts/e2e-stack.sh reset`);
  const admin: Admin = { ...device, words, publicId: claim.data.identity.id };
  writeFileSync(ADMIN_FILE, JSON.stringify(admin, null, 2));
  return admin;
}

/** One cron request, as konsoleH sends it. The endpoint runs at most one tick per 20 s. */
export async function cron(): Promise<number> {
  const res = await fetch(`${BASE_URL}/cron.php`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${e2eEnv().CRON_KEY}` },
  });
  return res.status;
}

/** Server time, from the sample with the smallest round trip (as the app does). */
export async function serverNow(): Promise<number> {
  let best: { rtt: number; offset: number } | null = null;
  for (let i = 0; i < 3; i++) {
    const t0 = Date.now();
    const { data } = await api<{ now: number }>(null, '/time');
    const t1 = Date.now();
    const rtt = t1 - t0;
    const offset = data.now - (t0 + rtt / 2);
    if (!best || rtt < best.rtt) best = { rtt, offset };
  }
  return Date.now() + (best?.offset ?? 0);
}

/**
 * A program file, or null — also when it cannot be parsed: on the macOS bind
 * mount of the e2e stack a read can catch a file mid-replacement (on the host
 * the rename is atomic). The app treats such a file as missing too.
 */
export async function fetchJson<T>(sitePath: string): Promise<T | null> {
  const res = await fetch(`${BASE_URL}/${sitePath}`, { cache: 'no-store' });
  if (!res.ok) return null;
  try {
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

export const slotAt = (t: number, channel = CHANNEL): Promise<SlotFile | null> => fetchJson<SlotFile>(slotPath(channel, t));
export const channels = (): Promise<ChannelsFile | null> => fetchJson<ChannelsFile>('program/channels.json');

export async function evergreen(channel = CHANNEL): Promise<EvergreenFile> {
  const url = (await channels())?.channels.find((c) => c.id === channel)?.evergreen;
  const file = url ? await fetchJson<EvergreenFile>(url.replace(/^\//, '')) : null;
  if (!file) throw new Error('no evergreen loop published');
  return file;
}

export const liveFile = (channel = CHANNEL): Promise<LiveFile | null> => fetchJson<LiveFile>(livePath(channel));

/**
 * The item on air at t, from that minute's file — what every client computes.
 * An item a moderator pulled from air (live.json `blocked`) is not on air.
 */
export async function itemAt(t: number, channel = CHANNEL): Promise<TimelineItem | null> {
  const [slot, live] = await Promise.all([slotAt(t, channel), liveFile(channel)]);
  const item = slot?.items.find((i) => i.start <= t && t < i.start + i.dur) ?? null;
  return item && live?.blocked.includes(item.id) ? null : item;
}

/**
 * Wait until the station is on air: the minute file for now exists and a song
 * is scheduled within the next few minutes. A fresh or restarted station
 * re-anchors first (a gap of a minute or two), so this can take a while.
 */
export async function waitOnAir(timeoutMs = 240_000): Promise<void> {
  const until = Date.now() + timeoutMs;
  let lastCron = 0;
  for (;;) {
    const now = await serverNow();
    const slot = await slotAt(now);
    const current = slot?.items.find((i) => i.start <= now && now < i.start + i.dur);
    if (current && current.type !== 'gap') return;
    if (Date.now() > until) throw new Error(`station not on air after ${timeoutMs / 1000} s (current: ${current?.type ?? 'no file'})`);
    if (Date.now() - lastCron > 21_000) {
      lastCron = Date.now();
      await cron();
    }
    await new Promise((r) => setTimeout(r, 2000));
  }
}

/**
 * Wait for the next moment a song is on air with at least `marginMs` left, so
 * a test that joins has a song to compare against (host breaks, jingles and
 * gaps sit between songs).
 */
export async function waitForSong(marginMs = 25_000, timeoutMs = 6 * MINUTE_MS): Promise<void> {
  const until = Date.now() + timeoutMs;
  for (;;) {
    const now = await serverNow();
    const item = await itemAt(now);
    if (item?.type === 'song' && item.start + item.dur - now > marginMs) return;
    if (Date.now() > until) throw new Error('no song on air');
    await new Promise((r) => setTimeout(r, 1000));
  }
}

/** Open the app as this device (its id and secret in localStorage, as the app keeps them). */
export async function asDevice(page: Page, device: Device): Promise<void> {
  await page.addInitScript((d) => {
    localStorage.setItem('arche.device', JSON.stringify(d));
  }, device);
}
