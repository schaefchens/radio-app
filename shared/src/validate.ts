import { LANGS, SUBMISSION_STATES, SUBMISSION_TYPES, type SubmissionState, type SubmissionType } from './constants.ts';
import type {
  ChannelInfo,
  ChannelsFile,
  DayBlock,
  DayFile,
  DayProgram,
  EvergreenFile,
  EvergreenTrack,
  HostKind,
  I18nText,
  LangMap,
  LiveFile,
  PlayedEntry,
  ProgramRef,
  SlotFile,
  StageConfig,
  TimelineItem,
  Voice,
} from './program.ts';

/**
 * Runtime parsing of the program files. The PWA reads files a server wrote
 * minutes or days ago — possibly an older or newer generator — so everything
 * arriving over the network goes through here once and is typed afterwards.
 *
 * The rule is "tolerant of additions, strict about what we use": unknown keys
 * are ignored, an unknown item type is dropped (a newer generator may add one),
 * and a file whose `v` we do not know is rejected whole.
 */

type Obj = Record<string, unknown>;

const isObj = (v: unknown): v is Obj => typeof v === 'object' && v !== null && !Array.isArray(v);
const isStr = (v: unknown): v is string => typeof v === 'string';
const isNum = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);
const str = (v: unknown): string => (isStr(v) ? v : '');
const strOrNull = (v: unknown): string | null => (isStr(v) && v !== '' ? v : null);
const arr = (v: unknown): unknown[] => (Array.isArray(v) ? v : []);

function i18n(v: unknown): I18nText {
  const o = isObj(v) ? v : {};
  return { en: str(o.en), de: str(o.de) };
}

function langMap(v: unknown): LangMap<string> {
  const out: LangMap<string> = {};
  if (!isObj(v)) return out;
  for (const l of LANGS) if (isStr(v[l]) && v[l] !== '') out[l] = v[l];
  return out;
}

function compact<T>(items: unknown[], parse: (v: unknown) => T | null): T[] {
  const out: T[] = [];
  for (const it of items) {
    const p = parse(it);
    if (p !== null) out.push(p);
  }
  return out;
}

export function parseVoice(v: unknown): Voice | null {
  if (!isObj(v) || !isStr(v.id) || !isStr(v.text)) return null;
  return { id: v.id, name: str(v.name), country: str(v.country), text: v.text, at: isNum(v.at) ? v.at : 0 };
}

function stage(v: unknown): StageConfig {
  const o = isObj(v) ? v : {};
  const mode = o.mode === 'image' || o.mode === 'flyins' ? o.mode : 'ambient';
  return { mode, image: strOrNull(o.image), tagline: i18n(o.tagline) };
}

const isSubmissionType = (v: unknown): v is SubmissionType => SUBMISSION_TYPES.includes(v as SubmissionType);
const isSubmissionState = (v: unknown): v is SubmissionState => SUBMISSION_STATES.includes(v as SubmissionState);

export function parseProgramRef(v: unknown): ProgramRef | null {
  if (!isObj(v) || !isStr(v.id)) return null;
  return {
    id: v.id,
    title: i18n(v.title),
    subtitle: i18n(v.subtitle),
    color: str(v.color) || '#2f7bff',
    stage: stage(v.stage),
    allowed: arr(v.allowed).filter(isSubmissionType),
  };
}

const HOST_KINDS: HostKind[] = ['intro', 'break', 'announce', 'outro', 'prayer', 'contrib'];
const CONTRIB_KINDS = ['story', 'testimony', 'greeting', 'prayer'] as const;

export function parseItem(v: unknown): TimelineItem | null {
  if (!isObj(v) || !isStr(v.id) || !isNum(v.start) || !isNum(v.dur) || v.dur <= 0) return null;
  const base = { id: v.id, start: v.start, dur: v.dur, p: str(v.p) };
  switch (v.type) {
    case 'song': {
      if (!isStr(v.yt) || v.yt === '') return null;
      const r = isObj(v.request) ? { name: str(v.request.name), place: str(v.request.place) } : null;
      return {
        ...base,
        type: 'song',
        yt: v.yt,
        title: str(v.title),
        artist: str(v.artist),
        thumb: strOrNull(v.thumb),
        request: r,
        fallback: strOrNull(v.fallback),
      };
    }
    case 'host':
      return {
        ...base,
        type: 'host',
        kind: HOST_KINDS.includes(v.kind as HostKind) ? (v.kind as HostKind) : 'break',
        audio: langMap(v.audio),
        text: langMap(v.text),
        voices: compact(arr(v.voices), parseVoice),
      };
    case 'jingle':
      return isStr(v.audio) ? { ...base, type: 'jingle', audio: v.audio } : null;
    case 'silence':
      return { ...base, type: 'silence', label: i18n(v.label) };
    case 'contrib': {
      if (!isStr(v.audio)) return null;
      const kind = CONTRIB_KINDS.find((k) => k === v.kind) ?? 'story';
      return {
        ...base,
        type: 'contrib',
        kind,
        audio: v.audio,
        caption: langMap(v.caption),
        name: str(v.name),
        place: str(v.place),
      };
    }
    case 'gap':
      return { ...base, type: 'gap' };
    case 'stage':
      return { ...base, type: 'stage', label: i18n(v.label) };
    default:
      return null;
  }
}

function programMap<T>(v: unknown, parse: (v: unknown) => T | null): Record<string, T> {
  const out: Record<string, T> = {};
  if (!isObj(v)) return out;
  for (const [k, p] of Object.entries(v)) {
    const parsed = parse(p);
    if (parsed !== null) out[k] = parsed;
  }
  return out;
}

export function parseSlotFile(v: unknown): SlotFile | null {
  if (!isObj(v) || v.v !== 1 || !isStr(v.channel) || !isNum(v.t)) return null;
  const submissions: SlotFile['submissions'] = {};
  if (isObj(v.submissions)) {
    for (const [k, s] of Object.entries(v.submissions)) {
      if (isSubmissionType(k) && isSubmissionState(s)) submissions[k] = s;
    }
  }
  const next = isObj(v.next) && isStr(v.next.p) && isNum(v.next.start) ? { p: v.next.p, start: v.next.start } : null;
  const items = compact(arr(v.items), parseItem).sort((a, b) => a.start - b.start);
  return {
    v: 1,
    channel: v.channel,
    t: v.t,
    gen: isNum(v.gen) ? v.gen : 0,
    current: strOrNull(v.current),
    next,
    submissions,
    programs: programMap(v.programs, parseProgramRef),
    items,
  };
}

function parseDayProgram(v: unknown): DayProgram | null {
  const ref = parseProgramRef(v);
  if (!ref || !isObj(v)) return null;
  return { ...ref, description: i18n(v.description) };
}

function parseBlock(v: unknown): DayBlock | null {
  if (!isObj(v) || !isNum(v.start) || !isNum(v.end) || !isStr(v.p) || v.end <= v.start) return null;
  return { start: v.start, end: v.end, p: v.p };
}

function parsePlayed(v: unknown): PlayedEntry | null {
  if (!isObj(v) || !isNum(v.start) || (v.type !== 'song' && v.type !== 'contrib')) return null;
  return {
    start: v.start,
    type: v.type,
    title: str(v.title),
    artist: str(v.artist),
    thumb: strOrNull(v.thumb),
    p: str(v.p),
  };
}

export function parseDayFile(v: unknown): DayFile | null {
  if (!isObj(v) || v.v !== 1 || !isStr(v.channel) || !isStr(v.date)) return null;
  return {
    v: 1,
    channel: v.channel,
    date: v.date,
    tz: str(v.tz) || 'UTC',
    gen: isNum(v.gen) ? v.gen : 0,
    blocks: compact(arr(v.blocks), parseBlock).sort((a, b) => a.start - b.start),
    programs: programMap(v.programs, parseDayProgram),
    played: compact(arr(v.played), parsePlayed).sort((a, b) => a.start - b.start),
  };
}

export function parseLiveFile(v: unknown): LiveFile | null {
  if (!isObj(v) || v.v !== 1 || !isStr(v.channel)) return null;
  return {
    v: 1,
    channel: v.channel,
    gen: isNum(v.gen) ? v.gen : 0,
    listeners: isNum(v.listeners) ? Math.max(0, Math.round(v.listeners)) : 0,
    voices: compact(arr(v.voices), parseVoice),
    blocked: arr(v.blocked).filter(isStr),
    pulse: isNum(v.pulse) ? Math.max(0, v.pulse) : 0,
  };
}

function parseChannel(v: unknown): ChannelInfo | null {
  if (!isObj(v) || !isStr(v.id)) return null;
  const host = isObj(v.host) ? v.host : {};
  return {
    id: v.id,
    name: i18n(v.name),
    main: v.main === true,
    tz: str(v.tz) || 'Europe/Berlin',
    color: str(v.color) || '#2f7bff',
    host: { name: str(host.name), avatar: strOrNull(host.avatar) },
    evergreen: strOrNull(v.evergreen),
  };
}

export function parseChannelsFile(v: unknown): ChannelsFile | null {
  if (!isObj(v) || v.v !== 1) return null;
  const f = isObj(v.features) ? v.features : {};
  return {
    v: 1,
    gen: isNum(v.gen) ? v.gen : 0,
    minClient: isNum(v.minClient) ? v.minClient : 1,
    channels: compact(arr(v.channels), parseChannel),
    features: {
      songRequests: f.songRequests === true,
      contributions: f.contributions === true,
      realtime: f.realtime === true,
    },
  };
}

function parseTrack(v: unknown): EvergreenTrack | null {
  if (!isObj(v) || !isStr(v.yt) || !isNum(v.dur) || v.dur <= 0) return null;
  return { yt: v.yt, title: str(v.title), artist: str(v.artist), dur: v.dur, thumb: strOrNull(v.thumb) };
}

export function parseEvergreenFile(v: unknown): EvergreenFile | null {
  if (!isObj(v) || v.v !== 1 || !isStr(v.channel) || !isNum(v.epoch)) return null;
  const items = compact(arr(v.items), parseTrack);
  const total = items.reduce((s, t) => s + t.dur, 0);
  return { v: 1, channel: v.channel, epoch: v.epoch, total, items };
}
