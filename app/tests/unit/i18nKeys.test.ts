import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { SUBMISSION_TYPES, VIDEO_FORMATS, VOICE_PROVIDERS, isVideoSubmissionType } from '@arche/shared';
import en from '@/i18n/en.json';
import de from '@/i18n/de.json';
import { VOICES } from '@/components/mod/voices';
import { LINE_BULK_ACTIONS, LINE_ERRORS, LINE_KINDS, LINE_MOODS, LINE_SORTS, LINE_STATES, LINE_TIMES } from '@/components/mod/lineKinds';

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
      // A group's notice on the stage (an "other" link shows its site's name), and /mod › Groups.
      ...['youtube', 'website'].map((kind) => `stage.link.${kind}`),
      ...['youtube', 'website', 'other'].map((kind) => `mod.groups.linkKinds.${kind}`),
      ...['recording_deleted', 'video_unplayable', 'group_blocked', 'not_rejected'].map((b) => `mod.review.blockers.${b}`),
      // modError's map of the server's group and library errors.
      'mod.library.groupBlocked',
      'mod.library.invalidGroup',
      ...['invalid_name', 'about_too_long', 'invalid_link', 'too_many_links', 'invalid_channel', 'too_many_channels', 'too_many_names', 'channel_in_group', 'channel_handle', 'channel_unknown'].map(
        (code) => `mod.groups.errors.${code}`,
      ),
      // /mod › Hosts: each voice service by name, and modError's map of the server's host errors.
      ...VOICE_PROVIDERS.map((p) => `mod.hosts.providers.${p}`),
      ...VOICES.map((v) => `mod.hosts.voiceNames.${v}`),
      ...[
        'host_name', 'host_about', 'host_style', 'host_instructions', 'host_model', 'host_voice', 'host_key', 'host_provider', 'host_lineup',
        'host_in_use', 'last_host', 'host_try_text', 'host_no_room', 'voice_timeout', 'voice_failed', 'invalid_color', 'invalid_image',
      ].map((code) => `mod.hosts.errors.${code}`),
      // /mod › Lines: every list a line is shown or filtered by, and modError's map of the server's line errors.
      ...LINE_KINDS.map((k) => `mod.lines.kinds.${k}`),
      ...LINE_TIMES.map((x) => `mod.lines.times.${x}`),
      ...LINE_MOODS.map((x) => `mod.lines.moods.${x}`),
      ...LINE_STATES.map((x) => `mod.lines.states.${x}`),
      ...LINE_SORTS.map((x) => `mod.lines.sorts.${x}`),
      ...LINE_BULK_ACTIONS.map((x) => `mod.lines.actions.${x}`),
      ...['model', 'moderator'].map((x) => `mod.lines.source.${x}`),
      ...['en', 'de'].map((x) => `mod.lines.langs.${x}`),
      ...LINE_ERRORS.map((code) => `mod.lines.errors.${code}`),
      ...['fresh', 'library', 'composed'].map((mode) => `mod.programs.lines.${mode}`),
      'mod.lines.bulk.confirmRemove_one',
      'mod.lines.bulk.confirmRerecord_one',
    ];
    expect(built.filter((k) => !all.has(k))).toEqual([]);
  });
});
