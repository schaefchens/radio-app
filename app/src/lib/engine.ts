import type {
  EvergreenFile,
  Lang,
  LiveFile,
  ProgramRef,
  SlotFile,
  SubmissionState,
  SubmissionType,
  TimelineItem,
  Voice,
} from '@arche/shared';
import { MINUTE_MS, floorMinute } from '@arche/shared';
import { Timeline } from './timeline';
import { evergreenAt } from './evergreen';
import { YTState } from './youtube';

/**
 * The sync engine: from the shared clock and the program files it decides
 * what is on air, drives the one YouTube player and our own audio, and keeps
 * both on time.
 *
 *   - What plays is a pure function of server time: item = timeline.at(now).
 *   - A song starts at (now − item.start); after that, drift is corrected at
 *     most once per cooldown and a few times per minute (`startSeconds` snaps
 *     to keyframes, and a seek loop would sound worse than a second of drift).
 *   - While an ad seems to be playing (PLAYING, but time stuck near zero) no
 *     correction happens; afterwards one seek catches up.
 *   - Nothing covers the player: when the item is not a song it is stopped
 *     and moved off stage, never hidden under something while playing.
 *   - With no program data for now (generator or network down) the evergreen
 *     loop plays, at the same second for everyone.
 */

export type StageMode = 'idle' | 'song' | 'host' | 'jingle' | 'contrib' | 'silence' | 'stage' | 'evergreen' | 'offline';

export interface EvergreenNow {
  yt: string;
  title: string;
  artist: string;
  thumb: string | null;
  start: number;
  dur: number;
}

export interface EngineState {
  channel: string;
  joined: boolean;
  mode: StageMode;
  item: TimelineItem | null;
  evergreen: EvergreenNow | null;
  program: ProgramRef | null;
  next: { program: ProgramRef; start: number } | null;
  upNext: TimelineItem | null;
  submissions: Partial<Record<SubmissionType, SubmissionState>>;
  /** Playback is blocked by the browser: the listener has to tap once. */
  needsTap: boolean;
  playerVisible: boolean;
  hostText: string | null;
  lastHost: { text: string; at: number } | null;
  listeners: number;
  voices: Voice[];
  hasData: boolean;
}

export interface PlayerLike {
  mounted: boolean;
  currentId: string | null;
  load(id: string, startSeconds: number): void;
  cue(id: string, startSeconds: number): void;
  play(): void;
  stop(): void;
  seek(seconds: number): void;
  time(): number;
  state(): number;
}

export interface AudioLike {
  unlocked: boolean;
  unlock(): void;
  play(url: string, offsetMs: number): Promise<boolean>;
  preload(url: string): void;
  stop(): void;
  resync(expectedMs: number, toleranceMs?: number): void;
}

export interface EngineDeps {
  now: () => number;
  player: PlayerLike;
  audio: AudioLike;
  fetchSlot: (channel: string, t: number) => Promise<SlotFile | null>;
  fetchSlotWalkingBack: (channel: string, t: number) => Promise<SlotFile | null>;
  fetchLive: (channel: string) => Promise<LiveFile | null>;
  fetchEvergreen: (url: string) => Promise<EvergreenFile | null>;
  /** Whether at least half of the player is on screen (YouTube autoplay rule). */
  canAutoplay: () => boolean;
  onChange: (state: EngineState) => void;
  onPlaybackError: (itemId: string, code: number) => void;
}

/** Drift beyond this (seconds) is corrected. Tuned by the device spike. */
export const DRIFT_THRESHOLD_S = 1.5;
const SEEK_COOLDOWN_MS = 10_000;
const MAX_SEEKS_PER_MINUTE = 3;
const TAP_HINT_AFTER_MS = 2500;
const LIVE_EVERY_MS = 30_000;

export function initialState(channel = ''): EngineState {
  return {
    channel,
    joined: false,
    mode: 'idle',
    item: null,
    evergreen: null,
    program: null,
    next: null,
    upNext: null,
    submissions: {},
    needsTap: false,
    playerVisible: false,
    hostText: null,
    lastHost: null,
    listeners: 0,
    voices: [],
    hasData: false,
  };
}

export class RadioEngine {
  private timeline = new Timeline();
  private blocked = new Set<string>();
  private evergreenFile: EvergreenFile | null = null;
  private evergreenUrl: string | null = null;
  private key: string | null = null;
  private state: EngineState;
  private lang: Lang = 'en';
  private loop: ReturnType<typeof setInterval> | null = null;
  private lastMinuteFetched = 0;
  private lastLive = 0;
  private lastDrift = 0;
  private lastSeek = 0;
  private seeks: number[] = [];
  private adSince = 0;
  private loadStartedAt = 0;
  private fetching = false;
  private generation = 0;

  private readonly deps: EngineDeps;

  constructor(deps: EngineDeps) {
    this.deps = deps;
    this.state = initialState();
  }

  get snapshot(): EngineState {
    return this.state;
  }

  setLang(lang: Lang): void {
    this.lang = lang;
    this.refreshHostText();
    this.emit();
  }

  setEvergreenUrl(url: string | null): void {
    if (url === this.evergreenUrl) return;
    this.evergreenUrl = url;
    this.evergreenFile = null;
    if (url) void this.deps.fetchEvergreen(url).then((f) => (this.evergreenFile = f));
  }

  /** Switch channel (also the first start). Playback continues if joined. */
  async start(channel: string): Promise<void> {
    this.generation++;
    this.timeline.clear();
    this.blocked.clear();
    this.key = null;
    this.state = { ...initialState(channel), joined: this.state.joined };
    this.emit();
    await this.fetchNow(true);
    await this.fetchLiveNow();
    if (!this.loop) this.loop = setInterval(() => this.tick(), 250);
    this.tick();
  }

  stop(): void {
    if (this.loop) clearInterval(this.loop);
    this.loop = null;
    this.deps.player.stop();
    this.deps.audio.stop();
  }

  /** The "tap to join" gesture: unlock audio, then play whatever is on air. */
  join(): void {
    if (!this.deps.audio.unlocked) this.deps.audio.unlock();
    this.state = { ...this.state, joined: true };
    this.key = null; // re-enter the current item, now with sound
    this.tick();
  }

  /** A tap on "tap to resume" (a gesture): try to start the player. */
  resume(): void {
    if (!this.deps.audio.unlocked) this.deps.audio.unlock();
    this.deps.player.play();
    this.key = null;
    this.tick();
  }

  /** Enter the current item again (at the live position), e.g. when the stage reappears. */
  reenter(): void {
    this.key = null;
    this.tick();
  }

  /** Back from the background: clocks and players drifted; start over. */
  resync(): void {
    this.key = null;
    void this.fetchNow(true);
    this.tick();
  }

  // --- player callbacks ------------------------------------------------------------

  onPlayerState(s: number): void {
    if (s === YTState.PLAYING && this.state.needsTap) {
      this.state = { ...this.state, needsTap: false };
      this.emit();
    }
    if (s === YTState.PLAYING) this.checkDrift(true);
    if (s === YTState.ENDED && (this.state.mode === 'song' || this.state.mode === 'evergreen')) {
      // The video ended before its slot did: stage until the next item.
      this.deps.player.stop();
      this.state = { ...this.state, playerVisible: false, mode: 'stage' };
      this.emit();
    }
  }

  onPlayerError(code: number): void {
    const item = this.state.item;
    if (item?.type === 'song') {
      this.deps.onPlaybackError(item.id, code);
      this.deps.player.stop();
      this.state = { ...this.state, playerVisible: false, mode: 'stage' };
      if (item.fallback && this.state.joined) {
        void this.deps.audio.play(item.fallback, 0);
      }
      this.emit();
    } else if (this.state.mode === 'evergreen') {
      this.deps.player.stop();
      this.state = { ...this.state, playerVisible: false, mode: 'stage' };
      this.emit();
    }
  }

  // --- data -------------------------------------------------------------------------

  private async fetchNow(walkBack: boolean): Promise<void> {
    if (this.fetching) return;
    this.fetching = true;
    const gen = this.generation;
    const channel = this.state.channel;
    try {
      const now = this.deps.now();
      const slot = walkBack
        ? await this.deps.fetchSlotWalkingBack(channel, now)
        : await this.deps.fetchSlot(channel, now);
      if (gen !== this.generation) return;
      if (slot) {
        this.timeline.add(slot);
        this.lastMinuteFetched = floorMinute(now);
      }
    } finally {
      this.fetching = false;
    }
  }

  private async fetchLiveNow(): Promise<void> {
    this.lastLive = this.deps.now();
    const gen = this.generation;
    const live = await this.deps.fetchLive(this.state.channel);
    if (!live || gen !== this.generation) return;
    const wasBlocked = this.state.item !== null && !this.blocked.has(this.state.item.id) && live.blocked.includes(this.state.item.id);
    this.blocked = new Set(live.blocked);
    this.state = { ...this.state, listeners: live.listeners, voices: live.voices };
    if (wasBlocked) this.key = null;
    this.emit();
  }

  // --- the loop -----------------------------------------------------------------------

  tick(): void {
    const now = this.deps.now();
    const minute = floorMinute(now);
    // One minute file per minute (every fetch is also a CDN "listener" hit);
    // a second of jitter keeps a million clients from arriving together.
    if (minute > this.lastMinuteFetched && now - minute > 1000 + (this.generation % 7) * 150) {
      this.lastMinuteFetched = minute;
      const thin = this.timeline.coveredUntil() < now + MINUTE_MS;
      void this.fetchNow(thin);
    }
    if (now - this.lastLive > LIVE_EVERY_MS) void this.fetchLiveNow();

    const item = this.timeline.at(now, this.blocked);
    if (item && item.type !== 'gap') {
      if (item.id !== this.key) this.enter(item, now);
      else this.maintain(item, now);
    } else {
      this.evergreenTick(now, item);
    }
  }

  private enter(item: TimelineItem, now: number): void {
    this.key = item.id;
    const { player, audio } = this.deps;
    const joined = this.state.joined;
    const offset = now - item.start;
    const base = this.contextFor(item, now);
    let mode: StageMode;
    let playerVisible = false;
    let needsTap = false;

    if (item.type === 'song') {
      audio.stop();
      mode = 'song';
      playerVisible = joined;
      if (joined) needsTap = this.startVideo(item.yt, offset / 1000);
    } else {
      player.stop();
      if (item.type === 'host' || item.type === 'jingle' || item.type === 'contrib') {
        mode = item.type;
        const url = item.type === 'host' ? (item.audio[this.lang] ?? item.audio.en ?? item.audio.de ?? null) : item.audio;
        if (joined && url) {
          void audio.play(url, offset).then((ok) => {
            if (!ok && this.key === item.id) {
              this.state = { ...this.state, needsTap: true };
              this.emit();
            }
          });
        } else {
          audio.stop();
        }
      } else {
        audio.stop();
        mode = item.type === 'silence' ? 'silence' : 'stage';
      }
    }
    this.state = { ...this.state, ...base, item, evergreen: null, mode, playerVisible, needsTap };
    this.refreshHostText();
    this.preloadNext(item);
    this.emit();
  }

  private maintain(item: TimelineItem, now: number): void {
    if (item.type === 'song') {
      if (this.state.joined && this.state.mode === 'song') this.checkDrift(false);
      if (this.state.joined && !this.state.needsTap && this.loadStartedAt && now - this.loadStartedAt > TAP_HINT_AFTER_MS) {
        const s = this.deps.player.state();
        if (s !== YTState.PLAYING && s !== YTState.BUFFERING) {
          this.state = { ...this.state, needsTap: true };
          this.emit();
        }
        this.loadStartedAt = 0;
      }
    } else if (this.state.joined && (item.type === 'host' || item.type === 'jingle' || item.type === 'contrib')) {
      if (now - this.lastDrift > 5000) {
        this.lastDrift = now;
        this.deps.audio.resync(now - item.start);
      }
    }
  }

  private evergreenTick(now: number, gap: TimelineItem | null): void {
    const file = this.evergreenFile;
    const pos = file ? evergreenAt(file, now) : null;
    if (!pos) {
      if (this.key !== 'offline') {
        this.key = 'offline';
        this.deps.player.stop();
        this.deps.audio.stop();
        this.state = { ...this.state, item: gap, evergreen: null, mode: 'offline', playerVisible: false, needsTap: false, hasData: this.timeline.coveredUntil() > 0 };
        this.emit();
      }
      return;
    }
    const key = `eg:${pos.index}:${pos.start}`;
    if (key === this.key) {
      if (this.state.joined) this.checkDrift(false, pos.start);
      return;
    }
    this.key = key;
    this.deps.audio.stop();
    const needsTap = this.state.joined ? this.startVideo(pos.track.yt, pos.offset / 1000) : false;
    this.state = {
      ...this.state,
      item: gap,
      mode: 'evergreen',
      evergreen: { yt: pos.track.yt, title: pos.track.title, artist: pos.track.artist, thumb: pos.track.thumb, start: pos.start, dur: pos.track.dur },
      playerVisible: this.state.joined,
      needsTap,
    };
    this.emit();
  }

  /** Load a video at an offset. Returns true when the listener must tap first. */
  private startVideo(yt: string, startSeconds: number): boolean {
    const { player } = this.deps;
    if (!player.mounted) return true;
    this.adSince = 0;
    // No cooldown for a fresh video: `startSeconds` snaps to a keyframe, so the
    // first PLAYING gets one correction right away.
    this.lastSeek = 0;
    this.seeks = [];
    if (!this.deps.canAutoplay()) {
      player.cue(yt, startSeconds);
      return true;
    }
    player.load(yt, startSeconds + 0.4); // loading takes a moment; start slightly ahead
    this.loadStartedAt = this.deps.now();
    return false;
  }

  private checkDrift(force: boolean, startOverride?: number): void {
    const now = this.deps.now();
    if (!force && now - this.lastDrift < 5000) return;
    this.lastDrift = now;
    const { player } = this.deps;
    if (player.state() !== YTState.PLAYING) return;
    const start = startOverride ?? (this.state.item?.type === 'song' ? this.state.item.start : null);
    if (start === null) return;
    const expected = (now - start) / 1000;
    const actual = player.time();
    // An ad: playing, but the programme time is stuck at the very beginning.
    if (actual < 1 && expected > 5) {
      if (!this.adSince) this.adSince = now;
      return;
    }
    if (this.adSince && actual >= 1) this.adSince = 0;
    if (Math.abs(actual - expected) <= DRIFT_THRESHOLD_S) return;
    this.seeks = this.seeks.filter((t) => now - t < 60_000);
    if (now - this.lastSeek < SEEK_COOLDOWN_MS || this.seeks.length >= MAX_SEEKS_PER_MINUTE) return;
    this.lastSeek = now;
    this.seeks.push(now);
    player.seek(expected + 0.3);
  }

  private contextFor(item: TimelineItem, now: number): Pick<EngineState, 'program' | 'next' | 'submissions' | 'upNext' | 'hasData'> {
    const slot = this.timeline.slotAt(now);
    const program = this.timeline.program(item.p) ?? this.timeline.program(slot?.current);
    const nextProgram = slot?.next ? this.timeline.program(slot.next.p) : null;
    return {
      program,
      next: slot?.next && nextProgram ? { program: nextProgram, start: slot.next.start } : null,
      submissions: slot?.submissions ?? {},
      upNext: this.timeline.next(now),
      hasData: true,
    };
  }

  private refreshHostText(): void {
    const item = this.state.item;
    if (item?.type === 'host') {
      const text = item.text[this.lang] ?? item.text.en ?? item.text.de ?? null;
      this.state = { ...this.state, hostText: text, lastHost: text ? { text, at: item.start } : this.state.lastHost };
    } else if (this.state.hostText !== null) {
      this.state = { ...this.state, hostText: null };
    }
  }

  private preloadNext(item: TimelineItem): void {
    const next = this.timeline.next(item.start);
    if (!next) return;
    if (next.type === 'host') {
      const url = next.audio[this.lang] ?? next.audio.en ?? next.audio.de;
      if (url) this.deps.audio.preload(url);
    } else if (next.type === 'jingle' || next.type === 'contrib') {
      this.deps.audio.preload(next.audio);
    }
  }

  private emit(): void {
    this.deps.onChange(this.state);
  }
}
