import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { DEVICE_KEYS, SESSION_KEYS } from '@/lib/deviceKeys';

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((f) => {
    const p = join(dir, f);
    return statSync(p).isDirectory() ? files(p) : /\.tsx?$/.test(f) ? [p] : [];
  });
}

/**
 * "Delete data on this device" deletes what the privacy policy says it does:
 * a key added anywhere in the app without joining the list would survive it.
 */
describe('everything the app stores on the device', () => {
  it('is on the list "Delete data on this device" deletes', () => {
    const stored = new Set<string>();
    for (const f of files(join(__dirname, '../../src'))) {
      for (const m of readFileSync(f, 'utf8').matchAll(/['"](arche\.[a-zA-Z.]+)['"]/g)) stored.add(m[1]!);
    }
    const listed = new Set([...DEVICE_KEYS, ...SESSION_KEYS]);
    expect([...stored].filter((k) => !listed.has(k)).sort()).toEqual([]);
    expect(stored.size).toBeGreaterThanOrEqual(DEVICE_KEYS.length);
  });
});
