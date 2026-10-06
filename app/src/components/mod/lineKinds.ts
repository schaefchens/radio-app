/**
 * The recorded host lines' fixed lists (Host\Lines on the server), apart from
 * the API code so the i18n test can check every key built from them.
 */

export const LINE_KINDS = ['intro', 'outro', 'encourage', 'present', 'prayertime', 'prayer', 'break'] as const;
export type LineKind = (typeof LINE_KINDS)[number];
/** Kinds whose lines belong to one program (they name it). */
export const PROGRAM_LINE_KINDS: readonly LineKind[] = ['intro', 'outro', 'present', 'prayertime'];
/** What a program takes from the library when it switches to it: every kind but the generic break. */
export const DEFAULT_LINE_KINDS: readonly LineKind[] = LINE_KINDS.filter((k) => k !== 'break');
export const LINE_TIMES = ['any', 'morning', 'afternoon', 'evening', 'night'] as const;
export type LineTime = (typeof LINE_TIMES)[number];
/** The moods a line may be tagged with ('' = none): the server takes these only (Host\Lines::MOODS). */
export const LINE_MOODS = ['calm', 'joyful', 'hopeful', 'reflective', 'warm'] as const;
export type LineMood = (typeof LINE_MOODS)[number];
export const LINE_STATES = ['draft', 'recording', 'active', 'paused', 'failed', 'removed'] as const;
export type LineState = (typeof LINE_STATES)[number];
export const LINE_SORTS = ['newest', 'most', 'least'] as const;
export type LineSort = (typeof LINE_SORTS)[number];
export const LINE_BULK_ACTIONS = ['pause', 'resume', 'approve', 'rerecord', 'remove'] as const;
export type LineBulkAction = (typeof LINE_BULK_ACTIONS)[number];
/** The server's line errors, each with a sentence (modError). */
export const LINE_ERRORS = [
  'line_kind', 'line_text', 'line_prays', 'line_program', 'line_state', 'line_tags', 'line_ids', 'line_action', 'line_count', 'line_hint', 'line_options', 'line_host', 'lines_mode',
] as const;
