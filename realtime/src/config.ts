import { readFileSync } from 'node:fs';
import { createPublicKey, type KeyObject } from 'node:crypto';
import type { NodeConfig } from '@arche/shared';

/**
 * Two kinds of configuration:
 *
 *   NodeEnv    — fixed for the life of the process, from the environment. On a
 *                Hetzner node that is /etc/arche/node.env (written by
 *                cloud-init); in dev the repo .env mounted at ARCHE_ENV_FILE.
 *   NodeConfig — the moderation knobs (message length, slow mode, blocklist…).
 *                PHP owns them and sends them back with every report response,
 *                so a moderator can tighten chat without touching the node.
 */

export interface NodeEnv {
  port: number;
  slot: string;
  apiBase: string;
  nodeSecret: string;
  /** null = no usable TOKEN_PUBLIC_KEY; every hello is then refused. */
  tokenKey: KeyObject | null;
  publicHost: string;
  maxLifetimeMs: number | null;
  maxConnections: number;
}

/** Until PHP answers the first report. Mirrors the PHP defaults. */
export function defaultNodeConfig(): NodeConfig {
  return { maxMessageLength: 280, roomCapacity: 100, slowModeMs: 3000, blocklist: [], highlightLikes: 5 };
}

/**
 * The same dotenv dialect as the PHP side (server/app/Config.php): KEY=value,
 * optional matching quotes, no inline comments, no `export`. Both read the one
 * repo .env in dev, so they must agree on what a line means.
 */
export function parseDotenv(text: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const line of text.split(/\r?\n/)) {
    const m = /^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$/.exec(line);
    if (!m || m[1] === undefined || m[2] === undefined) continue;
    let value = m[2].trim();
    const q = value[0];
    if (value.length >= 2 && (q === '"' || q === "'") && value.endsWith(q)) value = value.slice(1, -1);
    out[m[1]] = value;
  }
  return out;
}

/** The env file first, then the real environment on top: an explicit
 *  variable (compose `environment:`) always wins over the shared file. */
export function loadEnv(
  env: NodeJS.ProcessEnv = process.env,
  warn: (msg: string) => void = () => {},
): Record<string, string> {
  const merged: Record<string, string> = {};
  const file = env.ARCHE_ENV_FILE;
  if (file) {
    try {
      Object.assign(merged, parseDotenv(readFileSync(file, 'utf8')));
    } catch (err) {
      // Not fatal: the node still serves /health and refuses hellos, which is
      // easier to diagnose than a container that restarts in a loop.
      warn(`cannot read ARCHE_ENV_FILE ${file}: ${(err as Error).message}`);
    }
  }
  for (const [k, v] of Object.entries(env)) if (v !== undefined && v !== '') merged[k] = v;
  return merged;
}

const SPKI_ED25519_PREFIX = Buffer.from('302a300506032b6570032100', 'hex');

/** TOKEN_PUBLIC_KEY is the raw 32-byte Ed25519 key, base64 (sodium's format);
 *  node:crypto wants it wrapped as SPKI DER. */
export function parsePublicKey(value: string | undefined): KeyObject | null {
  const clean = (value ?? '').trim();
  if (clean === '') return null;
  const raw = Buffer.from(clean.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
  if (raw.length !== 32) return null;
  try {
    return createPublicKey({ key: Buffer.concat([SPKI_ED25519_PREFIX, raw]), format: 'der', type: 'spki' });
  } catch {
    return null;
  }
}

function intIn(value: string | undefined, min: number, max: number, fallback: number): number {
  const n = Number(value);
  return Number.isInteger(n) && n >= min && n <= max ? n : fallback;
}

export function readNodeEnv(vars: Record<string, string>): NodeEnv {
  const hours = Number(vars.MAX_LIFETIME_H);
  return {
    port: intIn(vars.PORT, 1, 65535, 8787),
    slot: vars.NODE_SLOT || 'local',
    apiBase: (vars.API_BASE || 'http://localhost:8080').replace(/\/+$/, ''),
    nodeSecret: vars.NODE_SECRET ?? '',
    tokenKey: parsePublicKey(vars.TOKEN_PUBLIC_KEY),
    publicHost: vars.PUBLIC_HOST ?? '',
    maxLifetimeMs: Number.isFinite(hours) && hours > 0 ? Math.round(hours * 3_600_000) : null,
    maxConnections: intIn(vars.MAX_CONNECTIONS, 1, 1_000_000, 5000),
  };
}

const clampInt = (v: unknown, min: number, max: number, fallback: number): number =>
  typeof v === 'number' && Number.isFinite(v) ? Math.min(max, Math.max(min, Math.round(v))) : fallback;

/** PHP's config arrives over the network: keep what is sane, fall back to the
 *  previous value for anything missing or odd. */
export function sanitizeNodeConfig(v: unknown, prev: NodeConfig): NodeConfig {
  if (typeof v !== 'object' || v === null || Array.isArray(v)) return prev;
  const o = v as Record<string, unknown>;
  const blocklist = Array.isArray(o.blocklist)
    ? o.blocklist
        .filter((s): s is string => typeof s === 'string')
        .map((s) => s.trim().toLowerCase())
        .filter((s) => s !== '')
        .slice(0, 2000)
    : prev.blocklist;
  return {
    maxMessageLength: clampInt(o.maxMessageLength, 1, 4000, prev.maxMessageLength),
    roomCapacity: clampInt(o.roomCapacity, 2, 100_000, prev.roomCapacity),
    slowModeMs: clampInt(o.slowModeMs, 0, 3_600_000, prev.slowModeMs),
    blocklist,
    highlightLikes: clampInt(o.highlightLikes, 1, 1_000_000, prev.highlightLikes),
  };
}
