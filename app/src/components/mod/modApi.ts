import type { Lang, ProgramFormat, VideoFormat, VoiceProvider } from '@arche/shared';
import { api, ApiError } from '@/lib/api';
import { errorText } from '@/i18n';
import i18n from '@/i18n';
import { LINE_ERRORS, type LineKind, type LineState, type LineTime } from './lineKinds';

export * from './lineKinds';

/**
 * Typed shapes of the /api/mod answers (server/app/Api/ModApi.php) and a
 * translator for their error codes: the common ones get a sentence, anything
 * else the generic message plus the code, which a moderator can act on.
 */

export interface ModProgram {
  id: number;
  channel_id: number;
  slug: string;
  title_en: string;
  title_de: string;
  subtitle_en: string;
  subtitle_de: string;
  description_en: string;
  description_de: string;
  tagline_en: string;
  tagline_de: string;
  color: string;
  image: string | null;
  stage_mode: 'image' | 'ambient' | 'flyins';
  allowed: string[];
  themes: string[];
  moods: string[];
  settings: ProgramSettings;
  active: number;
  /** Its lineup (on-air hosts first); missing from a server older than hosts. */
  hosts?: LineupEntry[];
  /** Whether the host's words come from recorded lines; missing from a server older than them. */
  lines?: ProgramLines;
}

export type LinesMode = 'fresh' | 'library' | 'composed';

/** A program's choice (outside its settings, like its lineup): `kinds` take a recorded line when the host has one. */
export interface ProgramLines {
  mode: LinesMode;
  kinds: LineKind[];
}

export interface ProgramSettings {
  host: { enabled: boolean; every_songs: number; intro: boolean; outro: boolean };
  jingle_every_songs: number;
  silence: { every_min: number; dur_s: number };
  closing_min: number;
  closed_min: number;
  max_queue_min: number;
  replay_contrib: boolean;
  format: ProgramFormat;
  prayer: {
    /** The collection: `songs` songs (0–3), then prayer music until it has lasted `minutes`. */
    collect: { songs: number; minutes: number; bed_id: number };
    quiet_min: number;
    after_songs: number;
    /** Open Doors' daily prayer request, read first. */
    opendoors: boolean;
  };
  /** Songs between two videos — for every video format, not only preaching (the group keeps its first name). */
  preaching: { songs_between: number };
}

export interface ModChannel {
  id: number;
  slug: string;
  name_en: string;
  name_de: string;
  is_main: number;
  timezone: string;
  color: string;
  sort: number;
  active: number;
  default_day_plan_id: number | null;
  fallback_program_id: number | null;
  /** Its lineup: the hosts of programs that have none. Missing from a server older than hosts. */
  hosts?: LineupEntry[];
}

/** One host in a channel's or program's lineup: on air (one per show, at random) or a fallback (in order). */
export interface LineupEntry {
  id: number;
  role: 'main' | 'fallback';
}

/** What a provider can be told (Host\Hosts::SETTINGS): OpenAI only `speed`, our own computers (`worker`) only `temperature`. */
export interface HostSettings {
  speed?: number;
  /** Qwen on our own computers: lower reads steadier and closer to the words. */
  temperature?: number;
  stability?: number;
  similarity?: number;
  style?: number;
  speaker_boost?: boolean;
  /** Send the language code (eleven_multilingual_v2 refuses one). */
  language?: boolean;
}

/** An on-air host (/mod › Hosts). The key itself never comes back. */
export interface ModHost {
  id: number;
  name: string;
  avatar: string | null;
  color: string;
  about_en: string;
  about_de: string;
  style: string;
  provider: VoiceProvider;
  model: string;
  voices: Partial<Record<Lang, string>>;
  instructions: string;
  settings: HostSettings;
  /** Characters a day; 0 = no cap (an ElevenLabs host then never speaks). */
  max_chars_day: number;
  active: boolean;
  key_set: boolean;
  /** Its sealed key no longer opens: enter it again. */
  key_unreadable: boolean;
  /** An OpenAI host without a key of its own speaks with the station's. */
  station_key: boolean;
  /** The key's last characters (admins only). */
  key_hint?: string;
  /** Unix seconds; 0 = not resting. */
  resting_until: number;
  last_error: string;
  speaks: boolean;
  today: { chars: number; calls: number };
  used_in: { channel: string; program: number | null; title: { en: string; de: string }; role: LineupEntry['role'] }[];
  /** Its recorded lines on air; missing from a server older than them. */
  lines_active?: number;
  /** A host speaking on our own computers: one that offers its voice was online in the last 90 s (absent for other providers). */
  worker_online?: boolean;
}

/** One of the station's own computers that speaks for the hosts with Qwen (/mod › Hosts › Computers). */
export interface ModWorker {
  id: number;
  name: string;
  active: boolean;
  online: boolean;
  /** Unix seconds; 0 = never. */
  last_seen: number;
  version: string;
  engine: { model?: string; checkpoint?: string; revision?: string };
  voices: { id: string; label: string }[];
  languages: string[];
  key_hint: string;
  tasks: { done_today: number; failed_today: number; queued: number };
}

/** A try of a voice on our own computers, while it is being made (GET /mod/hosts/try/{task}). */
export interface WorkerTry {
  state: 'queued' | 'leased' | 'done' | 'failed' | 'cancelled';
  audio?: string;
  ms?: number;
  error?: string;
}

/** A program to test a host's moments in (GET /mod/hosts/scenarios), with the moments it has. */
export interface ScenarioProgram {
  id: number;
  channel: string;
  title: Record<Lang, string>;
  format: string;
  moments: string[];
}

export interface ScenarioCatalog {
  programs: ScenarioProgram[];
  efforts: string[];
  effort: string;
}

/** A song or video a test moment is about. */
export interface ScenarioSong {
  id: number;
  title: string;
  artist: string;
  kind: string;
}

/** A test moment as the writer wrote it (POST /mod/hosts/scenario). */
export interface ScenarioResult {
  moment: string;
  kind: string;
  texts: Partial<Record<Lang, string>>;
  delivery: string;
  /** The writer's provider, `template:<why>` when it did not answer, `listener` for people's own words. */
  source: string;
  theirs: boolean;
  seconds: number;
  spoken: Partial<Record<Lang, string>>;
  songs: { previous: ScenarioSong | null; next: ScenarioSong | null };
  given: { moment: Record<string, unknown>; show: Record<string, unknown> };
}

/** A moment of a test show, sent back so the next one remembers it. */
export interface TestMoment {
  moment: string;
  previous_id?: number;
  next_id?: number;
  texts: Partial<Record<Lang, string>>;
}

/** One recorded line of a host (/mod › Lines): its words and a clip per language. */
export interface ModLine {
  id: number;
  host_id: number;
  kind: LineKind;
  program_id: number | null;
  texts: Partial<Record<Lang, string>>;
  /** Public /media URL per recorded language. */
  audio: Partial<Record<Lang, string>>;
  /** Milliseconds per recorded language. */
  durations: Partial<Record<Lang, number>>;
  tags: { time: LineTime; mood: string };
  state: LineState;
  /** Recorded with an earlier voice of the host: not picked unless its options say so. */
  old_voice: boolean;
  source: 'model' | 'moderator';
  chars: number;
  uses: number;
  /** Milliseconds; null = never aired. */
  last_aired: number | null;
  error: string;
  note: string;
  /** Unix seconds. */
  created: number;
  updated: number;
}

/** One kind's lines of a host (for one program, where the kind names it). */
export interface LinePool {
  kind: LineKind;
  program_id: number | null;
  active: number;
  target: number;
  /** Waiting for approval or being recorded. */
  waiting: number;
  failed: number;
  old_voice: number;
  aired_7d: number;
}

export interface LineOptions {
  /** Write and record new lines when a kind runs low. */
  refill: boolean;
  /** New lines go on air without approval. */
  live: boolean;
  targets: Record<LineKind, number>;
  /** A line comes back no sooner, when there are others. */
  rest_hours: number;
  /** Characters a calendar month the host may record; 0 records nothing. */
  month_chars: number;
  /** Keep picking lines recorded with an earlier voice. */
  old_voice: boolean;
}

export interface LinesOverview {
  host: { id: number; name: string; color: string; avatar: string | null; provider: VoiceProvider; model: string; voices: Partial<Record<Lang, string>> };
  kinds: LineKind[];
  program_kinds: LineKind[];
  /** The programs whose lineup has this host. */
  programs: { id: number; channel_id: number; title: { en: string; de: string }; format: string; mode: LinesMode; kinds: LineKind[] }[];
  pools: LinePool[];
  month: { chars: number; allowance: number };
  options: LineOptions;
  /** Requests for new lines the AI has not written yet. */
  queued: number;
  /** Admins change the options. */
  can_edit_options: boolean;
}

/** What a provider offers the host editor (POST /mod/hosts/catalog). */
export interface VoiceCatalog {
  voices: { id: string; name: string; category?: string; labels?: string; languages?: string[] }[];
  models: { id: string; name: string; languages?: string[]; cost?: number }[];
  account: { used: number; limit: number; resets: number; tier: string } | null;
  errors: string[];
  stub?: boolean;
  /** Our own computers (`worker`): how many are online now. */
  workers_online?: number;
}

export interface Overview {
  me: { id: string; role: string };
  channels: (ModChannel & { programs: ModProgram[] })[];
  library: { songs: number; preachings: number; testimonies: number; missions: number; films: number; jingles: number };
  review: number;
  reports: number;
  /** Prayer wall requests listeners reported, waiting for a decision. */
  wallReports: number;
  highlights: number;
  youtube: boolean;
}

export interface LibraryItem {
  id: number;
  kind: 'song' | VideoFormat | 'jingle' | 'contrib' | 'bed';
  yt_id: string | null;
  audio: string | null;
  title: string;
  artist: string;
  thumb: string | null;
  duration_ms: number;
  languages: string[];
  themes: string[];
  moods: string[];
  program_ids: number[];
  channel_ids: number[];
  source: string;
  active: number;
  plays: number;
  last_played: number | null;
  trend_score: number;
  updated: number;
  /** The library group a moderator put it in or its channel joined (LibraryGroup). */
  group_id: number | null;
  /** Its YouTube channel, once known (older items learn it from a job). */
  yt_channel: string | null;
  /** What was looked up about it (Library\Knowledge), in short; null for what has nothing to know (a jingle). */
  knowledge?: KnowledgeSummary | null;
}

export type KnowledgeState = 'none' | 'queued' | 'working' | 'ready' | 'failed';

export interface KnowledgeSummary {
  state: KnowledgeState;
  christian?: 'yes' | 'no' | 'unclear' | null;
  biblical?: 'yes' | 'concern' | 'no' | null;
  explicit?: boolean;
  concern?: boolean;
  facts?: number;
  /** The names research found, when they differ from the item's. */
  names?: { title: string; artist: string } | null;
  error?: string;
}

export interface KnowledgeFact {
  en: string;
  de: string;
  source: string;
}

/** A video's whole record, as /mod edits it. */
export interface KnowledgeRecord {
  yt_id: string;
  kind: string;
  state: KnowledgeState;
  yt_title: string;
  yt_artist: string;
  title: string;
  artist: string;
  research: {
    identity?: {
      identified: boolean;
      original: string;
      writers: { name: string; role: string; died: string }[];
      year: string;
      artist_background: string;
      christian_artist: string;
    };
    bible?: string[];
    facts?: KnowledgeFact[];
    content_notes?: string;
    public_domain?: { is: boolean; why: string; url: string };
    sources?: string[];
  };
  analysis: {
    heard?: boolean;
    message_en?: string;
    message_de?: string;
    summary_en?: string;
    summary_de?: string;
    addressed_to?: string;
    themes?: string[];
    moods?: string[];
    energy?: string;
    style?: string;
    quotes?: { text: string; at: string }[];
    bible_refs?: string[];
    christian?: 'yes' | 'no' | 'unclear';
    christian_why?: string;
    biblical?: 'yes' | 'concern' | 'no';
    concerns?: { what: string; why: string; quote: string }[];
    explicit?: boolean;
    age?: string;
    fits?: string[];
    speaker?: string;
    points?: string[];
  };
  /** Fact index → when it was last told (ms). */
  facts_told: Record<string, number>;
  text: string;
  text_source: string;
  cost_micros: number;
  error: string;
  edited_by: string;
  researched: number;
}

export interface KnowledgeSettings {
  checks: boolean;
  air: boolean;
  budget_usd: number;
  standard: string;
}

export interface GroupLink {
  kind: 'youtube' | 'website' | 'other';
  url: string;
}

/**
 * A preacher, a church, a ministry or an artist (/mod › Groups): with
 * `notice`, the stage shows these words and links after their items while the
 * host speaks; `blocked`, they asked not to be on our platform — nothing of
 * theirs is accepted or played.
 */
export interface LibraryGroup {
  id: number;
  name: string;
  about_en: string;
  about_de: string;
  links: GroupLink[];
  /** Their YouTube channels: videos from them join the group. */
  channels: { id: string; title: string }[];
  /** For blocking: the artist names their titles carry (empty: the group's name). */
  names: string[];
  notice: number;
  blocked: number;
  /** For moderators only (e.g. when and how they asked to be left out). */
  note: string;
  /** How many library items it holds. */
  items: number;
}

export interface VideoLookup {
  id: string;
  title: string;
  artist: string;
  channel: string;
  duration_ms: number;
  embeddable: boolean;
  public: boolean;
  live: boolean;
  age_restricted: boolean;
  playable: boolean;
  existing: number | null;
}

export interface DayPlanBlock {
  start_min: number;
  end_min: number;
  program_id: number;
}

export interface DayPlan {
  id: number;
  name: string;
  blocks: DayPlanBlock[];
}

export interface SpecialDay {
  id: number;
  name: string;
  kind: 'date' | 'easter';
  month: number | null;
  day: number | null;
  year: number | null;
  easter_offset: number | null;
  day_plan_id: number;
}

export interface PlansData {
  channel: ModChannel;
  programs: ModProgram[];
  dayPlans: DayPlan[];
  week: Record<string, number>;
  specialDays: SpecialDay[];
}

const KNOWN: Record<string, string> = {
  program_in_use: 'mod.programs.inUse',
  day_plan_in_use: 'mod.plans.inUse',
  overlapping_blocks: 'mod.plans.overlap',
  needs_passphrase: 'mod.users.needsPassphrase',
  youtube_not_configured: 'mod.library.noYoutube',
  video_not_embeddable: 'mod.library.notEmbeddable',
  already_in_library: 'mod.library.existing',
  video_duration: 'mod.library.badDuration',
  video_unplayable: 'mod.review.blockers.video_unplayable',
  recording_deleted: 'mod.review.blockers.recording_deleted',
  prayer_fallback: 'mod.programs.prayer.notFallback',
  group_blocked: 'mod.library.groupBlocked',
  invalid_group: 'mod.library.invalidGroup',
  invalid_name: 'mod.groups.errors.invalid_name',
  about_too_long: 'mod.groups.errors.about_too_long',
  invalid_link: 'mod.groups.errors.invalid_link',
  too_many_links: 'mod.groups.errors.too_many_links',
  invalid_channel: 'mod.groups.errors.invalid_channel',
  too_many_channels: 'mod.groups.errors.too_many_channels',
  too_many_names: 'mod.groups.errors.too_many_names',
  channel_in_group: 'mod.groups.errors.channel_in_group',
  channel_handle: 'mod.groups.errors.channel_handle',
  channel_unknown: 'mod.groups.errors.channel_unknown',
  host_name: 'mod.hosts.errors.host_name',
  host_about: 'mod.hosts.errors.host_about',
  host_style: 'mod.hosts.errors.host_style',
  host_instructions: 'mod.hosts.errors.host_instructions',
  host_model: 'mod.hosts.errors.host_model',
  host_voice: 'mod.hosts.errors.host_voice',
  host_key: 'mod.hosts.errors.host_key',
  host_provider: 'mod.hosts.errors.host_provider',
  host_lineup: 'mod.hosts.errors.host_lineup',
  host_in_use: 'mod.hosts.errors.host_in_use',
  last_host: 'mod.hosts.errors.last_host',
  host_try_text: 'mod.hosts.errors.host_try_text',
  host_no_room: 'mod.hosts.errors.host_no_room',
  voice_timeout: 'mod.hosts.errors.voice_timeout',
  host_try_timeout: 'mod.hosts.errors.host_try_timeout',
  scenario_program: 'mod.hosts.errors.scenario_program',
  scenario_moment: 'mod.hosts.errors.scenario_moment',
  invalid_color: 'mod.hosts.errors.invalid_color',
  invalid_image: 'mod.hosts.errors.invalid_image',
  worker_name: 'mod.workers.errors.worker_name',
  no_worker: 'mod.workers.errors.no_worker',
  knowledge_not_configured: 'mod.knowledge.errors.not_configured',
  not_ready: 'mod.knowledge.errors.not_ready',
  fact_needs_source: 'mod.knowledge.errors.fact_needs_source',
  fact_prays: 'mod.knowledge.errors.fact_prays',
  text_not_public_domain: 'mod.knowledge.errors.text_not_public_domain',
  standard_too_long: 'mod.knowledge.errors.standard_too_long',
  no_names: 'mod.knowledge.errors.no_names',
  ...Object.fromEntries(LINE_ERRORS.map((code) => [code, `mod.lines.errors.${code}`])),
};

export function modError(e: unknown): string {
  const code = e instanceof ApiError ? e.code : 'generic';
  // A voice provider's own words (redacted by the server): what to fix.
  if (code === 'voice_failed' && e instanceof ApiError) return i18n.t('mod.hosts.errors.voice_failed', { reason: String(e.detail.reason ?? '') });
  const key = KNOWN[code];
  if (key) return i18n.t(key);
  const text = errorText(code);
  return code === 'generic' ? text : `${text} (${code})`;
}

export const modApi = api;

export { QWEN_VOICES, VOICES } from './voices';

/** Models suggested in the host editor; any other id of the provider works too. */
export const MODELS: Record<VoiceProvider, readonly string[]> = {
  openai: ['gpt-4o-mini-tts', 'tts-1', 'tts-1-hd'],
  elevenlabs: ['eleven_multilingual_v2', 'eleven_flash_v2_5', 'eleven_v3'],
  worker: ['qwen3-tts-1.7b-customvoice'],
};

/** What a new host of each provider starts with (Host\Hosts::SETTINGS and DEFAULT_MODEL). */
export const HOST_DEFAULTS: Record<VoiceProvider, { model: string; settings: HostSettings }> = {
  openai: { model: 'gpt-4o-mini-tts', settings: { speed: 1 } },
  elevenlabs: { model: 'eleven_flash_v2_5', settings: { stability: 0.5, similarity: 0.75, style: 0, speaker_boost: true, speed: 1, language: true } },
  worker: { model: 'qwen3-tts-1.7b-customvoice', settings: { temperature: 0.7 } },
};
