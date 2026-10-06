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

/** What a listener can hand in. `song` is a YouTube link, and so are the
 *  videos suggested for a video program (VIDEO_SUBMISSIONS): `preaching`,
 *  `testimony_video` — a testimony on YouTube, where `testimony` is one the
 *  listener recorded —, `mission` and `film`. `prayer` covers both a typed
 *  and a recorded prayer request; `intercession` is a listener's own prayer,
 *  typed or recorded, sent in a prayer hour's prayer time; story, testimony
 *  and greeting are always recordings. */
export const SUBMISSION_TYPES = [
  'song',
  'story',
  'testimony',
  'greeting',
  'prayer',
  'preaching',
  'testimony_video',
  'mission',
  'film',
  'intercession',
] as const;
export type SubmissionType = (typeof SUBMISSION_TYPES)[number];

/** The program formats that play videos with songs between them. A format's
 *  name is also the library kind it plays, the song item's `kind` on air and
 *  the host moment that introduces it. PHP restates this list as
 *  Catalog::VIDEO_FORMATS. */
export const VIDEO_FORMATS = ['preaching', 'testimony', 'mission', 'film'] as const;
export type VideoFormat = (typeof VIDEO_FORMATS)[number];

/** A listener's video suggestion, one type per kind (the fourth tile's sheet
 *  picks among them). An Extract, so a name missing from SUBMISSION_TYPES
 *  fails the typecheck instead of being dropped by the parser. */
export type VideoSubmissionType = Extract<SubmissionType, 'preaching' | 'testimony_video' | 'mission' | 'film'>;

/** Which suggestion type a video format takes as its own. `testimony_video`,
 *  because `testimony` is the recorded one. */
export const VIDEO_SUBMISSIONS: Readonly<Record<VideoFormat, VideoSubmissionType>> = {
  preaching: 'preaching',
  testimony: 'testimony_video',
  mission: 'mission',
  film: 'film',
};

export const VIDEO_SUBMISSION_TYPES: readonly VideoSubmissionType[] = VIDEO_FORMATS.map((f) => VIDEO_SUBMISSIONS[f]);

export const isVideoFormat = (v: unknown): v is VideoFormat => VIDEO_FORMATS.includes(v as VideoFormat);

export const isVideoSubmissionType = (v: unknown): v is VideoSubmissionType => VIDEO_SUBMISSION_TYPES.includes(v as VideoSubmissionType);

/** The services a host's voice comes from (server/app/Host/Hosts.php
 *  PROVIDERS): OpenAI, ElevenLabs, or `worker` — Qwen3-TTS on one of the
 *  station's own computers (Host\Workers), which sends nothing to an outside
 *  service. Listeners are told when ElevenLabs reads out what they send. */
export const VOICE_PROVIDERS = ['openai', 'elevenlabs', 'worker'] as const;
export type VoiceProvider = (typeof VOICE_PROVIDERS)[number];

export const SUBMISSION_STATES = ['open', 'closing', 'closed'] as const;
export type SubmissionState = (typeof SUBMISSION_STATES)[number];

/** heart and pray are the two big buttons; the rest sit behind the emoji
 *  picker — 🙌 raise, 😊 smile, 😍 love, 🥹 moved, 🕊️ peace, ✨ hope,
 *  🎉 celebrate, 🔥 fire. The server counts only kinds it has a weight for
 *  (server/app/Presence/Trends.php), so a kind added here goes there too. */
export const REACTION_KINDS = ['heart', 'pray', 'smile', 'raise', 'peace', 'fire', 'love', 'moved', 'hope', 'celebrate'] as const;
export type ReactionKind = (typeof REACTION_KINDS)[number];

export const ROLES = ['listener', 'moderator', 'admin'] as const;
export type Role = (typeof ROLES)[number];

/** Bumped when a program file changes shape incompatibly. channels.json
 *  carries `minClient`; an older client asks to update instead of guessing. */
export const PROGRAM_FORMAT = 1;
