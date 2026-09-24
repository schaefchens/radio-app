import { describe, expect, it } from 'vitest';
import { deriveCredential, generatePassphrase, validPassphrase } from '@/lib/passphrase';

describe('passphrase', () => {
  it('generates 12 valid words and derives a stable credential', () => {
    const words = generatePassphrase();
    expect(words.split(' ')).toHaveLength(12);
    expect(validPassphrase(words)).toBe(true);
    expect(validPassphrase(`  ${words.toUpperCase()}  `)).toBe(true);
    const a = deriveCredential(words);
    const b = deriveCredential(words.toUpperCase());
    expect(a).toEqual(b);
    expect(a.credId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(a.credSecret).toMatch(/^[0-9a-f]{64}$/);
  });
  it('rejects wrong words', () => {
    expect(validPassphrase('one two three')).toBe(false);
  });
});
