import { generateKeyPairSync, sign, type KeyObject } from 'node:crypto';
import type { RealtimeToken, ServerMsg } from '@arche/shared';
import { parsePublicKey } from '../src/config.ts';
import type { Conn } from '../src/hub.ts';

/** A fresh Ed25519 pair, with the public half in TOKEN_PUBLIC_KEY's format
 *  (raw 32 bytes, base64) and parsed back the way the node does it. */
export function makeKeys(): { privateKey: KeyObject; publicKeyB64: string; publicKey: KeyObject } {
  const pair = generateKeyPairSync('ed25519');
  const raw = pair.publicKey.export({ format: 'der', type: 'spki' }).subarray(12);
  const publicKeyB64 = Buffer.from(raw).toString('base64');
  const publicKey = parsePublicKey(publicKeyB64);
  if (!publicKey) throw new Error('could not parse generated key');
  return { privateKey: pair.privateKey, publicKeyB64, publicKey };
}

/** Exactly what PHP does: base64url (no padding) of the JSON, then
 *  sodium_crypto_sign_detached over that string's bytes. */
export function signToken(privateKey: KeyObject, payload: unknown): string {
  const body = Buffer.from(JSON.stringify(payload), 'utf8').toString('base64url');
  const sig = sign(null, Buffer.from(body, 'ascii'), privateKey).toString('base64url');
  return `${body}.${sig}`;
}

export function tokenPayload(overrides: Partial<RealtimeToken> = {}, nowSec = Math.floor(Date.now() / 1000)): RealtimeToken {
  return {
    v: 1,
    sub: 'u1',
    name: 'Maria',
    country: 'DE',
    lang: 'de',
    role: 'listener',
    ch: 'main',
    iat: nowSec,
    exp: nowSec + 600,
    ...overrides,
  };
}

export class FakeConn implements Conn {
  sent: ServerMsg[] = [];
  closed: { code: number; reason: string } | null = null;

  send(msg: ServerMsg): void {
    if (this.closed === null) this.sent.push(msg);
  }

  close(code: number, reason: string): void {
    this.closed ??= { code, reason };
  }

  of<T extends ServerMsg['t']>(t: T): Extract<ServerMsg, { t: T }>[] {
    return this.sent.filter((m): m is Extract<ServerMsg, { t: T }> => m.t === t);
  }

  last<T extends ServerMsg['t']>(t: T): Extract<ServerMsg, { t: T }> | undefined {
    return this.of(t).at(-1);
  }

  errors(): string[] {
    return this.of('error').map((m) => m.code);
  }
}

/** A mutable clock for rate limits and slow mode. */
export function makeClock(start = 1_790_190_000_000): { now: () => number; advance: (ms: number) => void } {
  let t = start;
  return { now: () => t, advance: (ms) => (t += ms) };
}
