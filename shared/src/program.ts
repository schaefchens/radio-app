import type { Lang, SubmissionState, SubmissionType } from './constants.ts';

/**
 * The static program files: written by server/app/Program/*, read by the PWA.
 * All times are epoch milliseconds (UTC). Every file carries `v`; a reader that
 * does not know the version treats the file as missing rather than guessing.
 */

export type I18nText = Record<Lang, string>;
/** A per-language value that may exist for only some languages (host audio is
 *  rendered for each language the station speaks; the reader falls back). */
export type LangMap<T> = Partial<Record<Lang, T>>;

export interface StageConfig {
  /** image = the program's still; ambient = the animated default backdrop;
   *  flyins = ambient plus community voices flying in between songs. */
  mode: 'image' | 'ambient' | 'flyins';
  image: string | null;
  tagline: I18nText;
}

export interface ProgramRef {
  id: string;
  title: I18nText;
  subtitle: I18nText;
  color: string;
  stage: StageConfig;
  /** Submission types this program accepts at all (the minute file says
   *  whether each is currently open). */
  allowed: SubmissionType[];
  /** 'prayer': a prayer hour with its running order; 'preaching': preachings
   *  with songs between them; 'music' otherwise. */
  format: ProgramFormat;
}

export type ProgramFormat = 'music' | 'prayer' | 'preaching';

export interface Voice {
  id: string;
  name: string;
  /** ISO 3166-1 alpha-2, or '' when unknown. */
  country: string;
  text: string;
  at: number;
  /** The author's mark (Presence::voiceTag), for listeners who blocked them; missing in older files. */
  by?: string;
}

/** A typed prayer request on the prayer wall. Anonymous by design — text and
 *  day only, no name or place: prayers can reveal faith or health. `id` is
 *  the key for reactions (sent like a voice's). */
export interface WallEntry {
  id: string;
  text: string;
  at: number;
  /** In a prayer hour: when its reading begins — shown from then on. */
  from?: number;
  /** The station's own request (Open Doors' daily one): who it is from. */
  source?: string;
  /** Its translation, for the station's own request. */
  texts?: LangMap<string>;
}

interface ItemBase {
  /** Stable public id; also the key for reactions and `blocked`. */
  id: string;
  start: number;
  dur: number;
  /** Program id the item was scheduled for. The live bar shows the program of
   *  the *current item*, so a soft overrun never flips the title mid-song. */
  p: string;
}

export interface SongItem extends ItemBase {
  type: 'song';
  /** 'preaching': a sermon from YouTube in a preaching program. It plays
   *  exactly like a song — an app that does not know the kind plays it as
   *  one — and is named a preaching on screen. */
  kind: 'song' | 'preaching';
  yt: string;
  title: string;
  artist: string;
  thumb: string | null;
  /** Set for a listener request (or a suggested preaching) that was announced on air. */
  request: { name: string; place: string } | null;
  /** Audio to play instead when the video will not play here (region block,
   *  removed video). null = keep the stage up until the item ends. */
  fallback: string | null;
}

/** `reading` is a listener's prayer request read out word for word, and
 *  `intercession` a listener's written prayer (the host's voice, their
 *  words); after requests read out, `prayer` invites everyone to pray — the
 *  host never prays itself. opening (a moderator's prepared prayer), present,
 *  prayertime, encourage (and invite, in hours planned before) belong to a
 *  prayer hour; preaching introduces the preaching that follows in a
 *  preaching program. */
export type HostKind =
  | 'intro'
  | 'break'
  | 'announce'
  | 'outro'
  | 'prayer'
  | 'contrib'
  | 'opening'
  | 'invite'
  | 'preaching'
  | 'reading'
  | 'intercession'
  | 'present'
  | 'prayertime'
  | 'encourage';

export interface HostItem extends ItemBase {
  type: 'host';
  kind: HostKind;
  audio: LangMap<string>;
  text: LangMap<string>;
  /** Community voices the host picked up; shown as fly-ins on the stage. */
  voices: Voice[];
  /** The wall entries ('p' + id) this moment is about — a request read out —
   *  ids only: live.json says what may be shown of them ("On air now"). */
  prayers: string[];
}

export interface JingleItem extends ItemBase {
  type: 'jingle';
  audio: string;
}

/** Background music of our own (the prayer hour's prayer music), played on
 *  its own — never under the host — while the stage shows `label`. A piece
 *  starts `offset` ms into the file, so consecutive pieces continue the track,
 *  and is never longer than the rest of the file: the app never loops one. */
export interface BedItem extends ItemBase {
  type: 'bed';
  audio: string;
  label: I18nText;
  offset: number;
}

export interface SilenceItem extends ItemBase {
  type: 'silence';
  label: I18nText;
}

export interface ContribItem extends ItemBase {
  type: 'contrib';
  kind: 'story' | 'testimony' | 'greeting' | 'prayer';
  audio: string;
  caption: LangMap<string>;
  name: string;
  place: string;
}

/** Nothing was scheduled here (the generator was down). Clients play the
 *  evergreen loop until the next real item. */
export interface GapItem extends ItemBase {
  type: 'gap';
}

/** A stage-only moment, used when the library is too small to fill time. */
export interface StageItem extends ItemBase {
  type: 'stage';
  label: I18nText;
}

export type TimelineItem = SongItem | HostItem | JingleItem | BedItem | SilenceItem | ContribItem | GapItem | StageItem;
export type TimelineItemType = TimelineItem['type'];

export interface SlotFile {
  v: 1;
  channel: string;
  /** Start of the minute this file describes. */
  t: number;
  gen: number;
  /** Program on air at `t` by the plan (the live bar prefers the item's own). */
  current: string | null;
  next: { p: string; start: number } | null;
  /** Only the types the current program allows appear here. */
  submissions: Partial<Record<SubmissionType, SubmissionState>>;
  programs: Record<string, ProgramRef>;
  /** Every item overlapping [t, t + SLOT_WINDOW_MS), sorted by start. */
  items: TimelineItem[];
}

export interface DayBlock {
  start: number;
  end: number;
  p: string;
}

export interface PlayedEntry {
  start: number;
  type: 'song' | 'contrib';
  title: string;
  artist: string;
  thumb: string | null;
  p: string;
}

export interface DayProgram extends ProgramRef {
  description: I18nText;
}

/** The plan for one station-local day. Structure only — what will play stays
 *  a surprise; `played` lists only what has already been on air. */
export interface DayFile {
  v: 1;
  channel: string;
  /** Station-local date, YYYY-MM-DD. */
  date: string;
  tz: string;
  gen: number;
  blocks: DayBlock[];
  programs: Record<string, DayProgram>;
  played: PlayedEntry[];
}

/** Rewritten every tick; cached for seconds, not minutes. */
export interface LiveFile {
  v: 1;
  channel: string;
  gen: number;
  listeners: number;
  voices: Voice[];
  /** Typed prayer requests the sender agreed to show, anonymous, newest
   *  first, at most 30 (a prayer hour's: as they are read out, up to 60). In
   *  live.json (not the minute files) so that a moderator's takedown applies
   *  with the next tick. */
  wall: WallEntry[];
  /** While a prayer hour is on air: requests it received, not yet read out. */
  collected?: number;
  /** Item ids a moderator pulled from air after they were published. */
  blocked: string[];
  /** Seconds between presence pulses; 0 = do not pulse. Raised under load. */
  pulse: number;
}

export interface ChannelInfo {
  id: string;
  name: I18nText;
  main: boolean;
  tz: string;
  color: string;
  host: { name: string; avatar: string | null };
  /** Path of the current evergreen loop file, or null while there is none. */
  evergreen: string | null;
}

export interface ChannelsFile {
  v: 1;
  gen: number;
  /** Oldest program format a client must understand (see PROGRAM_FORMAT). */
  minClient: number;
  channels: ChannelInfo[];
  features: { songRequests: boolean; contributions: boolean; realtime: boolean };
}

export interface EvergreenTrack {
  yt: string;
  title: string;
  artist: string;
  dur: number;
  thumb: string | null;
}

/** The fallback loop. Position = (now - epoch) mod total, so every client that
 *  has this file plays the same second of it without asking anyone. */
export interface EvergreenFile {
  v: 1;
  channel: string;
  epoch: number;
  total: number;
  items: EvergreenTrack[];
}
