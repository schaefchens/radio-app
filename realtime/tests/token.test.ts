import { describe, expect, it } from 'vitest';
import { parsePublicKey } from '../src/config.ts';
import { verifyToken } from '../src/token.ts';
import { makeKeys, signToken, tokenPayload } from './helpers.ts';

const now = 1_790_190_000;

describe('verifyToken', () => {
  const keys = makeKeys();

  it('accepts a token signed the way PHP signs it', () => {
    const wire = signToken(keys.privateKey, tokenPayload({}, now));
    const check = verifyToken(wire, keys.publicKey, now);
    expect(check).toEqual({ ok: true, token: tokenPayload({}, now) });
  });

  it('rejects a tampered payload, a foreign key and malformed wire', () => {
    const wire = signToken(keys.privateKey, tokenPayload({}, now));
    const [body, sig] = wire.split('.') as [string, string];
    const forged = Buffer.from(JSON.stringify(tokenPayload({ role: 'admin' }, now))).toString('base64url');
    expect(verifyToken(`${forged}.${sig}`, keys.publicKey, now)).toEqual({ ok: false, code: 'auth' });
    expect(verifyToken(wire, makeKeys().publicKey, now).ok).toBe(false);
    for (const bad of ['', body, `${body}.`, `.${sig}`, `${body}.${sig}.x`, `${body}.${sig}==`, 42, null]) {
      expect(verifyToken(bad, keys.publicKey, now).ok).toBe(false);
    }
    expect(verifyToken(wire, null, now).ok).toBe(false);
  });

  it('reports expiry separately so the client knows to fetch a new token', () => {
    const wire = signToken(keys.privateKey, tokenPayload({ exp: now - 1 }, now));
    expect(verifyToken(wire, keys.publicKey, now)).toEqual({ ok: false, code: 'expired' });
  });

  it('refuses tokens from the future and unknown shapes', () => {
    const cases = [
      tokenPayload({ iat: now + 3600 }, now),
      { ...tokenPayload({}, now), v: 2 },
      { ...tokenPayload({}, now), lang: 'fr' },
      { ...tokenPayload({}, now), role: 'owner' },
      { ...tokenPayload({}, now), ch: '../etc' },
      { ...tokenPayload({}, now), sub: '' },
    ];
    for (const payload of cases) {
      expect(verifyToken(signToken(keys.privateKey, payload), keys.publicKey, now).ok).toBe(false);
    }
  });

  it('normalizes name and country instead of trusting them', () => {
    const wire = signToken(keys.privateKey, tokenPayload({ name: '  Jo   Ann  ', country: 'br' }, now));
    const check = verifyToken(wire, keys.publicKey, now);
    expect(check.ok && check.token.name).toBe('Jo Ann');
    expect(check.ok && check.token.country).toBe('BR');
    const odd = signToken(keys.privateKey, tokenPayload({ country: 'Germany' }, now));
    const oddCheck = verifyToken(odd, keys.publicKey, now);
    expect(oddCheck.ok && oddCheck.token.country).toBe('');
  });
});

describe('parsePublicKey', () => {
  it('takes raw 32-byte keys in base64 or base64url and nothing else', () => {
    const { publicKeyB64 } = makeKeys();
    expect(parsePublicKey(publicKeyB64)).not.toBeNull();
    expect(parsePublicKey(Buffer.from(publicKeyB64, 'base64').toString('base64url'))).not.toBeNull();
    expect(parsePublicKey('')).toBeNull();
    expect(parsePublicKey(undefined)).toBeNull();
    expect(parsePublicKey(Buffer.alloc(31).toString('base64'))).toBeNull();
  });
});
