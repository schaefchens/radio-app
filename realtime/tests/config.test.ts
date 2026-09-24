import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { loadEnv, readNodeEnv } from '../src/config.ts';
import { makeKeys } from './helpers.ts';

describe('loadEnv / readNodeEnv', () => {
  it('reads the mounted env file and lets explicit variables win', () => {
    const dir = mkdtempSync(join(tmpdir(), 'arche-rt-'));
    const file = join(dir, 'arche.env');
    const { publicKeyB64 } = makeKeys();
    writeFileSync(file, `NODE_SECRET=from-file\nNODE_SLOT=file-slot\nTOKEN_PUBLIC_KEY=${publicKeyB64}\nSFTP_PASSWORD="p@ss"\n`);
    const vars = loadEnv({ ARCHE_ENV_FILE: file, NODE_SLOT: 'local', API_BASE: 'http://web/', PORT: '' });
    const env = readNodeEnv(vars);
    expect(env).toMatchObject({ slot: 'local', nodeSecret: 'from-file', apiBase: 'http://web', port: 8787 });
    expect(env.tokenKey).not.toBeNull();
    expect(env.maxLifetimeMs).toBeNull();
  });

  it('survives a missing env file with a warning', () => {
    const warnings: string[] = [];
    const vars = loadEnv({ ARCHE_ENV_FILE: '/nonexistent/arche.env', MAX_LIFETIME_H: '6' }, (w) => warnings.push(w));
    expect(warnings).toHaveLength(1);
    const env = readNodeEnv(vars);
    expect(env.tokenKey).toBeNull();
    expect(env.maxLifetimeMs).toBe(6 * 3_600_000);
  });
});
