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
}

export interface Voice {
  id: string;
  name: string;
  /** ISO 3166-1 alpha-2, or '' when unknown. */
  country: string;
  text: string;
  at: number;
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
  yt: string;
  title: string;
  artist: string;
  thumb: string | null;
  /** Set for a listener request that was announced on air. */
  request: { name: string; place: string } | null;
  /** Audio to play instead when the video will not play here (region block,
   *  removed video). null = keep the stage up until the item ends. */
  fallback: string | null;
}

export type HostKind = 'intro' | 'break' | 'announce' | 'outro' | 'prayer' | 'contrib';

export interface HostItem extends ItemBase {
  type: 'host';
  kind: HostKind;
  audio: LangMap<string>;
  text: LangMap<string>;
  /** Community voices the host picked up; shown as fly-ins on the stage. */
  voices: Voice[];
}

export interface JingleItem extends ItemBase {
  type: 'jingle';
  audio: string;
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

export type TimelineItem = SongItem | HostItem | JingleItem | SilenceItem | ContribItem | GapItem | StageItem;
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
