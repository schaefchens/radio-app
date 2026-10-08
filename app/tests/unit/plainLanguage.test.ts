import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * The station's own words name people by the plain word — "Hörer", not
 * "Hörerinnen und Hörer" or "Hörer*innen" — and its rules name sex, not
 * gender (station decision 2026-10-08). Paired forms had crept into the
 * privacy policy, the rules, the app and the store texts.
 */
const TEXTS = ['src/i18n/de.json', 'src/i18n/en.json', 'src/content/legal.ts', 'src/content/rules.ts', '../STORE.md'];

// A pair repeats its noun ("Hörerinnen und Hörer"), so "beginnen und Sie" or "Berlin und der Welt" pass.
const AVOIDED: [string, RegExp][] = [
  // A quoted block in STORE.md wraps with "> ".
  ['a paired plural ("Hörerinnen und Hörer")', /(\p{Lu}\p{L}*)innen\s+(und|oder|bzw\.)\s+(>\s*)?\1/gu],
  ['a paired plural ("Hörer und Hörerinnen")', /(\p{Lu}\p{L}*)n?\s+(und|oder|bzw\.)\s+\1innen/gu],
  ['a paired singular ("der Verfasserin bzw. des Verfassers")', /(\p{Lu}\p{L}*)in\s+(und|oder|bzw\.)\s+(des|der|den|dem|ein|einen|einem)\s+\1/gu],
  ['a star, colon, underscore or slash ("Hörer*innen")', /\p{Lu}\p{L}*([*:_]|\/-?)in(nen)?\b/gu],
  ['"sie/er"', /\b(sie\/er|er\/sie)\b/gu],
  ['"gender"', /\bgender/giu],
];

describe('plain language', () => {
  it.each(TEXTS)('%s names people by the plain word, and sex rather than gender', (file) => {
    const text = readFileSync(join(__dirname, '../..', file), 'utf8');
    const found = AVOIDED.flatMap(([what, re]) => [...text.matchAll(re)].map((m) => `${what}: ${m[0]}`));
    expect(found).toEqual([]);
  });
});
