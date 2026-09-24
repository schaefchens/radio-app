import { verify, type KeyObject } from 'node:crypto';
import { LANGS, ROLES, type Lang, type RealtimeToken, type Role } from '@arche/shared';

/**
 * Join tokens are minted by PHP (server side of the wake endpoint) and only
 * verified here. Wire form: `<base64url(json)>.<base64url(signature)>`, where
 * the signature is Ed25519 over the ASCII bytes of the base64url payload
 * string — exactly `sodium_crypto_sign_detached($payloadB64, $secretKey)`.
 *
 * The node never sees the signing key, so a compromised node can read chat but
 * cannot invent listeners or elevate anyone to moderator.
 */

export type TokenCheck = { ok: true; token: RealtimeToken } | { ok: false; code: 'auth' | 'expired' };

const B64URL = /^[A-Za-z0-9_-]+$/;
const MAX_WIRE_LENGTH = 2048;
/** Tolerated clock difference between the webhosting and the node. */
const CLOCK_SKEW_S = 300;

const fail: TokenCheck = { ok: false, code: 'auth' };

export function verifyToken(wire: unknown, key: KeyObject | null, nowSec: number): TokenCheck {
  if (key === null || typeof wire !== 'string' || wire.length > MAX_WIRE_LENGTH) return fail;
  const dot = wire.indexOf('.');
  if (dot <= 0 || dot !== wire.lastIndexOf('.')) return fail;
  const payloadB64 = wire.slice(0, dot);
  const sigB64 = wire.slice(dot + 1);
  if (!B64URL.test(payloadB64) || !B64URL.test(sigB64)) return fail;

  const signature = Buffer.from(sigB64, 'base64url');
  if (signature.length !== 64) return fail;
  let valid: boolean;
  try {
    valid = verify(null, Buffer.from(payloadB64, 'ascii'), key, signature);
  } catch {
    valid = false;
  }
  if (!valid) return fail;

  let payload: unknown;
  try {
    payload = JSON.parse(Buffer.from(payloadB64, 'base64url').toString('utf8'));
  } catch {
    return fail;
  }
  const token = parsePayload(payload);
  if (token === null || token.iat > nowSec + CLOCK_SKEW_S) return fail;
  if (token.exp <= nowSec) return { ok: false, code: 'expired' };
  return { ok: true, token };
}

const isStr = (v: unknown): v is string => typeof v === 'string';
const isNum = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);

function parsePayload(v: unknown): RealtimeToken | null {
  if (typeof v !== 'object' || v === null || Array.isArray(v)) return null;
  const o = v as Record<string, unknown>;
  if (o.v !== 1 || !isStr(o.sub) || o.sub === '' || o.sub.length > 64) return null;
  if (!isStr(o.ch) || !/^[a-z0-9][a-z0-9-]{0,63}$/i.test(o.ch)) return null;
  if (!LANGS.includes(o.lang as Lang) || !ROLES.includes(o.role as Role)) return null;
  if (!isNum(o.iat) || !isNum(o.exp)) return null;
  const country = isStr(o.country) && /^[a-z]{2}$/i.test(o.country) ? o.country.toUpperCase() : '';
  const name = isStr(o.name) ? o.name.replace(/\s+/g, ' ').trim().slice(0, 40) : '';
  return {
    v: 1,
    sub: o.sub,
    name,
    country,
    lang: o.lang as Lang,
    role: o.role as Role,
    ch: o.ch,
    iat: o.iat,
    exp: o.exp,
  };
}
