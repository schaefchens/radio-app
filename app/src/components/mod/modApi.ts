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
}

export interface ProgramSettings {
  host: { enabled: boolean; every_songs: number; intro: boolean; outro: boolean };
  jingle_every_songs: number;
  silence: { every_min: number; dur_s: number };
  closing_min: number;
  closed_min: number;
  max_queue_min: number;
  replay_contrib: boolean;
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
  host_name: string;
  host_avatar: string | null;
  host_voice_en: string;
  host_voice_de: string;
  host_style: string;
  default_day_plan_id: number | null;
  fallback_program_id: number | null;
}

export interface Overview {
  me: { id: string; role: string };
  channels: (ModChannel & { programs: ModProgram[] })[];
  library: { songs: number; jingles: number };
  review: number;
  reports: number;
  highlights: number;
  youtube: boolean;
}

export interface LibraryItem {
  id: number;
  kind: 'song' | 'jingle' | 'contrib';
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
  video_unplayable: 'mod.review.blockers.video_unplayable',
  recording_deleted: 'mod.review.blockers.recording_deleted',
};

export function modError(e: unknown): string {
  const code = e instanceof ApiError ? e.code : 'generic';
  const key = KNOWN[code];
  if (key) return i18n.t(key);
  const text = errorText(code);
  return code === 'generic' ? text : `${text} (${code})`;
}

export const modApi = api;

/** OpenAI TTS voices the host can use. */
export const VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse'] as const;
