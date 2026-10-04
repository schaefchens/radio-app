import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { SUBMISSION_TYPES, VIDEO_FORMATS, isVideoSubmissionType } from '@arche/shared';
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

  // Keys built at runtime (`nowPlaying.${kind}`, `submit.${type}.title` …) escape
  // the literal check above: a missing one shows its raw name on screen.
  it('every key built from a list of kinds, types or tiles exists', () => {
    const all = new Set(keys(en));
    const built = [
      ...VIDEO_FORMATS.flatMap((f) => [`nowPlaying.${f}`, `videoForm.kinds.${f}`, `videoForm.hints.${f}`, `mod.library.kind.${f}`]),
      ...SUBMISSION_TYPES.flatMap((type) => [
        // submissionLabel: what the forms call it, a recording by its kind.
        type === 'song' || type === 'prayer' || isVideoSubmissionType(type) ? `submit.${type}.title` : `record.${type}`,
        `mod.review.types.${type}`,
      ]),
      ...['song', 'story', 'prayer', 'video'].flatMap((tile) => [`submit.${tile}.title`, `submit.${tile}.subtitle`]),
      'submit.video.title',
      'songForm.url',
      'songForm.message',
      'songForm.messageHint',
      'songForm.nameHint',
      'videoForm.url',
      'videoForm.message',
      'videoForm.messageHint',
      'videoForm.nameHint',
    ];
    expect(built.filter((k) => !all.has(k))).toEqual([]);
  });
});
