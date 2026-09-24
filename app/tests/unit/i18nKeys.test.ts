import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import en from '@/i18n/en.json';
import de from '@/i18n/de.json';

/** Adapted from bible-assistant: both languages have the same keys, and every
 *  literal t('…') in src/ exists (plural keys count through their _one form). */
function keys(obj: unknown, prefix = ''): string[] {
  if (typeof obj !== 'object' || obj === null) return [prefix];
  return Object.entries(obj).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k));
}

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((f) => {
    const p = join(dir, f);
    return statSync(p).isDirectory() ? files(p) : /\.(tsx?|ts)$/.test(f) ? [p] : [];
  });
}

describe('i18n', () => {
  it('en and de have the same keys', () => {
    expect(keys(de).sort()).toEqual(keys(en).sort());
  });

  it('every literal key used in the code exists', () => {
    const all = new Set(keys(en));
    const missing: string[] = [];
    for (const f of files(join(__dirname, '../../src'))) {
      const src = readFileSync(f, 'utf8');
      for (const m of src.matchAll(/\bt\(\s*'([a-zA-Z0-9_.]+)'/g)) {
        const k = m[1]!;
        if (!all.has(k) && !all.has(`${k}_one`)) missing.push(`${k} (${f.split('/src/')[1]})`);
      }
    }
    expect(missing).toEqual([]);
  });
});
