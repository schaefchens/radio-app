/**
 * Numbers and names the PHP generator, the PWA and the realtime node must agree
 * on. The PHP side restates the timing constants in server/app/Program/Timing.php
 * — shared/fixtures is what keeps the two honest (both test suites read it).
 */

export const LANGS = ['en', 'de'] as const;
export type Lang = (typeof LANGS)[number];

export const MINUTE_MS = 60_000;

/** Every minute file carries every item overlapping [t, t + SLOT_WINDOW_MS).
 *  One successful fetch therefore covers this much outage on its own. */
export const SLOT_WINDOW_MS = 3 * MINUTE_MS;

/** Minute files exist this far ahead of now, so a client never asks for one
 *  that is still being written. */
export const PUBLISH_LEAD_MS = 2 * MINUTE_MS;

/** The timeline is fixed (committed) this far ahead: window + lead. It is
 *  also how long the program outlives a stopped generator, and the least
 *  time between a listener's request being approved and it airing. */
export const COMMIT_HORIZON_MS = SLOT_WINDOW_MS + PUBLISH_LEAD_MS;

/** How many minutes back a client looks when the current minute file 404s.
 *  An earlier file still names the item on air when that item is long (a
 *  song started before its window), so this reaches past SLOT_WINDOW_MS. */
export const SLOT_WALKBACK_MINUTES = 10;

/** What a listener can hand in. `prayer` covers both a typed and a recorded
 *  prayer request; the other three are always recordings. */
export const SUBMISSION_TYPES = ['song', 'story', 'testimony', 'greeting', 'prayer'] as const;
export type SubmissionType = (typeof SUBMISSION_TYPES)[number];

export const SUBMISSION_STATES = ['open', 'closing', 'closed'] as const;
export type SubmissionState = (typeof SUBMISSION_STATES)[number];

/** heart and pray are the two big buttons; the rest sit behind the smile. */
export const REACTION_KINDS = ['heart', 'pray', 'smile', 'raise', 'peace', 'fire'] as const;
export type ReactionKind = (typeof REACTION_KINDS)[number];

export const ROLES = ['listener', 'moderator', 'admin'] as const;
export type Role = (typeof ROLES)[number];

/** Bumped when a program file changes shape incompatibly. channels.json
 *  carries `minClient`; an older client asks to update instead of guessing. */
export const PROGRAM_FORMAT = 1;
