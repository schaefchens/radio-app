import type { Lang, ProgramFormat, VideoFormat, VoiceProvider } from '@arche/shared';
import { api, ApiError } from '@/lib/api';
import { errorText } from '@/i18n';
import i18n from '@/i18n';

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

/** What a provider can be told (Host\Hosts::SETTINGS): OpenAI only `speed`. */
export interface HostSettings {
  speed: number;
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
}

/** What a provider offers the host editor (POST /mod/hosts/catalog). */
export interface VoiceCatalog {
  voices: { id: string; name: string; category?: string; labels?: string; languages?: string[] }[];
  models: { id: string; name: string; languages?: string[]; cost?: number }[];
  account: { used: number; limit: number; resets: number; tier: string } | null;
  errors: string[];
  stub?: boolean;
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
  invalid_color: 'mod.hosts.errors.invalid_color',
  invalid_image: 'mod.hosts.errors.invalid_image',
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

/** OpenAI's built-in voices (Host\Hosts::OPENAI_VOICES); tts-1 and tts-1-hd lack ballad, marin and cedar. */
export const VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'] as const;

/** Models suggested in the host editor; any other id of the provider works too. */
export const MODELS: Record<VoiceProvider, readonly string[]> = {
  openai: ['gpt-4o-mini-tts', 'tts-1', 'tts-1-hd'],
  elevenlabs: ['eleven_multilingual_v2', 'eleven_flash_v2_5', 'eleven_v3'],
};

/** What a new host of each provider starts with (Host\Hosts::SETTINGS and DEFAULT_MODEL). */
export const HOST_DEFAULTS: Record<VoiceProvider, { model: string; settings: HostSettings }> = {
  openai: { model: 'gpt-4o-mini-tts', settings: { speed: 1 } },
  elevenlabs: { model: 'eleven_flash_v2_5', settings: { stability: 0.5, similarity: 0.75, style: 0, speaker_boost: true, speed: 1, language: true } },
};
