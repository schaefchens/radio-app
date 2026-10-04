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
  WallEntry,
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

export type StageMode = 'idle' | 'song' | 'host' | 'jingle' | 'contrib' | 'bed' | 'silence' | 'stage' | 'evergreen' | 'offline';

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
  /** The prayer wall from live.json: anonymous typed prayer requests, newest
   *  first — in a prayer hour each one from the start of its reading. */
  wall: WallEntry[];
  /** The wall entries on air right now (a request being read out). */
  praying: string[];
  /** While a prayer hour is on air: requests it received, not yet read out. */
  collected: number | null;
  hasData: boolean;
}

export interface PlayerLike {
  mounted: boolean;
  currentId: string | null;
  load(id: string, startSeconds: number): void;
  cue(id: string, startSeconds: number): void;
  play(): void;
  pause(): void;
  stop(): void;
  seek(seconds: number): void;
  time(): number;
  state(): number;
}

export interface AudioLike {
  unlocked: boolean;
  unlock(): void;
  /** Wake our audio engine again (inside a tap): a phone may have put it to sleep since. */
  wake(): void;
  /** `fadeInMs` > 0 rises from silence instead of starting at full volume. */
  play(url: string, offsetMs: number, fadeInMs?: number): Promise<boolean>;
  preload(url: string): void;
  stop(): void;
  resync(expectedMs: number, toleranceMs?: number): void;
  fadeOut(ms: number): void;
  /** Where in `url` playback is (ms) when it is the clip playing now, else null. */
  playingAt(url: string): number | null;
  /** Any of our clips is playing. */
  playing(): boolean;
  /** The clip we started was paused, and not by us (the lock screen, headphones, a call). */
  pausedFromOutside(): boolean;
}

type OwnAudioItem = Extract<TimelineItem, { type: 'host' | 'jingle' | 'contrib' | 'bed' }>;

/** Items that play our own audio, not the YouTube player. */
const ownAudio = (item: TimelineItem): item is OwnAudioItem =>
  item.type === 'host' || item.type === 'jingle' || item.type === 'contrib' || item.type === 'bed';

/** Where in its file an item of our own audio is at `now`: a piece of prayer music starts into the track. */
const audioPosition = (item: OwnAudioItem, now: number): number => (item.type === 'bed' ? item.offset : 0) + now - item.start;

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
  /** The page is on screen (not a background tab, not the lock screen). Default: always. */
  pageVisible?: () => boolean;
  onChange: (state: EngineState) => void;
  onPlaybackError: (itemId: string, code: number) => void;
}

/** Drift beyond this (seconds) is corrected. Tuned by the device spike. */
export const DRIFT_THRESHOLD_S = 1.5;
const SEEK_COOLDOWN_MS = 10_000;
const MAX_SEEKS_PER_MINUTE = 3;
const TAP_HINT_AFTER_MS = 2500;
const LIVE_EVERY_MS = 30_000;
/** Prayer music rises and fades over this long: a piece can end mid-track. */
export const BED_FADE_MS = 1500;
/** Our audio already playing this close to where an item needs it is that item going on. */
const CONTINUE_MS = 2000;
/** A pause YouTube reports this soon after one of our own commands is that command's. */
const OWN_COMMAND_MS = 1500;
/** One empty wall for every reset: a fresh [] per state would give a zustand
 *  selector a new reference each time and loop React. */
const NO_WALL: WallEntry[] = [];
const NO_IDS: string[] = [];

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
    wall: NO_WALL,
    praying: NO_IDS,
    collected: null,
    hasData: false,
  };
}

export class RadioEngine {
  private timeline = new Timeline();
  private blocked = new Set<string>();
  /** live.json's wall as it came: entries whose reading has not begun are held back (dueWall()). */
  private liveWall: WallEntry[] = NO_WALL;
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
  /** The video in the player has played since we gave it: a pause before that is a load's, not a listener's. */
  private videoPlayed = false;
  /** When we last told the player to load, cue, seek, pause or stop. */
  private commandAt = 0;
  private fadingOut: string | null = null;
  private fetching: { gen: number; done: Promise<void> } | null = null;
  private generation = 0;
  /** The minute file the program's context (the tiles, what comes next) was read from. */
  private contextT = -1;

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
    this.wakeAudio();
    this.state = { ...this.state, joined: true };
    this.key = null; // re-enter the current item, now with sound
    this.tick();
  }

  /**
   * The listener stops the radio: back to where a fresh page starts — the
   * program still follows on screen, nothing plays until they join again.
   */
  leave(): void {
    if (!this.state.joined) return;
    this.stopVideo();
    this.deps.audio.stop();
    this.state = { ...this.state, joined: false, playerVisible: false, needsTap: false };
    this.key = null; // re-enter the current item, now without sound
    this.tick();
  }

  /** A tap on "tap to resume" (a gesture): try to start the player. */
  resume(): void {
    this.wakeAudio();
    this.command();
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
    this.deps.audio.wake();
    this.key = null;
    void this.fetchNow(true);
    this.tick();
  }

  /**
   * The stage went away (a sheet over it, scrolled off, a page without it):
   * the video may not play unseen. The pause that follows is ours, not the
   * listener's.
   */
  stageHidden(): void {
    if (this.state.mode !== 'song' && this.state.mode !== 'evergreen') return;
    this.command();
    this.deps.player.pause();
  }

  // --- player callbacks ------------------------------------------------------------

  onPlayerState(s: number): void {
    const video = this.state.mode === 'song' || this.state.mode === 'evergreen';
    if (s === YTState.PLAYING) {
      // Nothing plays while the listener is out or no song is on air. A
      // report still on its way when they pressed pause, or a resume from
      // outside (the lock screen, headphones, a key), played on behind the
      // stage — and the drift check then sought it to the live position,
      // which for YouTube starts a stopped video.
      if (!this.state.joined || !video) {
        this.stopVideo();
        return;
      }
      if (!this.deps.canAutoplay()) {
        this.stageHidden();
        return;
      }
      this.videoPlayed = true;
      if (this.state.needsTap) {
        this.state = { ...this.state, needsTap: false };
        this.emit();
      }
      this.checkDrift(true);
      return;
    }
    if (
      s === YTState.PAUSED &&
      video &&
      this.state.joined &&
      this.videoPlayed &&
      this.deps.now() - this.commandAt > OWN_COMMAND_MS &&
      this.deps.canAutoplay() &&
      this.pageVisible()
    ) {
      // Paused from outside the app — a click on the video (on a desktop
      // YouTube pauses on a click even without its controls), the lock
      // screen, headphones, a key. That is the listener pausing, as with the
      // song bar's Pause: left "playing" in the engine's eyes, the radio
      // started again by itself at the next re-entry or segment.
      this.leave();
      return;
    }
    if (s === YTState.ENDED && video) {
      // The video ended before its slot did: stage until the next item.
      this.stopVideo();
      this.state = { ...this.state, playerVisible: false, mode: 'stage' };
      this.emit();
    }
  }

  onPlayerError(code: number): void {
    const item = this.state.item;
    if (item?.type === 'song') {
      this.deps.onPlaybackError(item.id, code);
      this.stopVideo();
      this.state = { ...this.state, playerVisible: false, mode: 'stage' };
      if (item.fallback && this.state.joined) {
        void this.deps.audio.play(item.fallback, 0);
      }
      this.emit();
    } else if (this.state.mode === 'evergreen') {
      this.stopVideo();
      this.state = { ...this.state, playerVisible: false, mode: 'stage' };
      this.emit();
    }
  }

  /** Inside a tap: unlock our audio the first time; later wake it, as a phone may have put it to sleep (a call, the lock screen) and the host then spoke without a sound. */
  private wakeAudio(): void {
    const { audio } = this.deps;
    if (!audio.unlocked) audio.unlock();
    else audio.wake();
  }

  /** Our own command to the player: a pause or stop that follows is ours, not the listener's. */
  private command(): void {
    this.commandAt = this.deps.now();
    this.videoPlayed = false;
  }

  private stopVideo(): void {
    this.command();
    this.deps.player.stop();
  }

  private pageVisible(): boolean {
    return this.deps.pageVisible?.() ?? true;
  }

  // --- data -------------------------------------------------------------------------

  private async fetchNow(walkBack: boolean): Promise<void> {
    // One fetch at a time per channel. One still on its way for the channel
    // before — a switch, or a tap on "join" before the first start — is
    // waited for, not taken for this one: that left the new channel's
    // timeline empty and the listener on the fallback loop for a minute.
    while (this.fetching) {
      if (this.fetching.gen === this.generation) return;
      await this.fetching.done;
    }
    const gen = this.generation;
    const channel = this.state.channel;
    // Before the first start there is no channel: `program//slots/…` is ten 404s.
    if (!channel) return;
    let finish = (): void => {};
    this.fetching = { gen, done: new Promise<void>((resolve) => (finish = resolve)) };
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
      this.fetching = null;
      finish();
    }
  }

  private async fetchLiveNow(): Promise<void> {
    if (!this.state.channel) return;
    this.lastLive = this.deps.now();
    const gen = this.generation;
    const live = await this.deps.fetchLive(this.state.channel);
    if (!live || gen !== this.generation) return;
    const wasBlocked = this.state.item !== null && !this.blocked.has(this.state.item.id) && live.blocked.includes(this.state.item.id);
    this.blocked = new Set(live.blocked);
    this.liveWall = live.wall.length ? live.wall : NO_WALL;
    this.state = { ...this.state, listeners: live.listeners, voices: live.voices, wall: this.dueWall(this.deps.now()), collected: live.collected ?? null };
    if (wasBlocked) this.key = null;
    this.emit();
  }

  /**
   * The wall as it may be shown at `now`: in a prayer hour a request appears
   * as its reading begins (`from`) — live.json brings it a minute or two
   * early. The same array while nothing changes (a new one would make a
   * zustand selector loop React).
   */
  private dueWall(now: number): WallEntry[] {
    const raw = this.liveWall;
    if (!raw.some((e) => e.from !== undefined && e.from > now)) return raw;
    const due = raw.filter((e) => e.from === undefined || e.from <= now);
    const shown = this.state.wall;
    if (due.length === shown.length && due.every((e, i) => e === shown[i])) return shown;
    return due.length ? due : NO_WALL;
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
    const wall = this.dueWall(now);
    if (wall !== this.state.wall) {
      // A request's reading has begun: it comes onto the wall now, for everyone at once.
      this.state = { ...this.state, wall };
      this.emit();
    }
    // Nothing of ours plays while the listener is out: a clip resumed from
    // outside (the lock screen, headphones, a key) is stopped again.
    if (!this.state.joined && this.deps.audio.playing()) this.deps.audio.stop();

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
    const { audio } = this.deps;
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
      this.stopVideo();
      if (ownAudio(item)) {
        mode = item.type;
        const url = this.audioUrl(item);
        if (joined && url) this.playOwn(item, url, now);
        else audio.stop();
      } else {
        audio.stop();
        mode = item.type === 'silence' ? 'silence' : 'stage';
      }
    }
    const praying = item.type === 'host' && item.prayers.length > 0 ? item.prayers : NO_IDS;
    this.state = { ...this.state, ...base, item, evergreen: null, mode, playerVisible, needsTap, praying };
    this.refreshHostText();
    this.preloadNext(item);
    this.emit();
  }

  private maintain(item: TimelineItem, now: number): void {
    // A video of a video program can run most of an hour — a film two: the
    // tiles (intake closes before its program ends) and what comes next
    // follow each minute file, not only the start of the next item.
    if ((this.timeline.slotAt(now)?.t ?? -1) !== this.contextT) {
      this.state = { ...this.state, ...this.contextFor(item, now) };
      this.emit();
    }
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
    } else if (this.state.joined && ownAudio(item)) {
      if (this.pageVisible() && this.deps.audio.pausedFromOutside()) {
        // Paused from outside (the lock screen, headphones, a call): the
        // listener pausing, as with the song bar's Pause.
        this.leave();
        return;
      }
      if (item.type === 'bed' && item.start + item.dur - now <= BED_FADE_MS) {
        // The music stops mid-track when its piece ends: fade it out first —
        // unless the next piece goes on with the same file.
        if (this.fadingOut !== item.id && !this.continuesInto(item)) {
          this.fadingOut = item.id;
          this.deps.audio.fadeOut(BED_FADE_MS);
        }
      } else if (now - this.lastDrift > 5000) {
        this.lastDrift = now;
        this.deps.audio.resync(audioPosition(item, now));
      }
    }
  }

  private audioUrl(item: OwnAudioItem): string | null {
    return item.type === 'host' ? (item.audio[this.lang] ?? item.audio.en ?? item.audio.de ?? null) : item.audio;
  }

  private playOwn(item: OwnAudioItem, url: string, now: number): void {
    const { audio } = this.deps;
    const at = audioPosition(item, now);
    // Already playing where this item needs it — the next piece of the same
    // prayer music, or the stage back after a sheet (reenter): stay on it.
    // Starting it again would dip the music and fade it in once more.
    const playing = audio.playingAt(url);
    if (playing !== null && Math.abs(playing - at) < CONTINUE_MS) {
      audio.resync(at);
      return;
    }
    void audio.play(url, at, item.type === 'bed' ? BED_FADE_MS : 0).then((ok) => {
      if (!ok && this.key === item.id) {
        this.state = { ...this.state, needsTap: true };
        this.emit();
      }
    });
  }

  /** The item after this piece of prayer music goes on with the same file, where this one ends. */
  private continuesInto(item: Extract<TimelineItem, { type: 'bed' }>): boolean {
    const next = this.timeline.next(item.start);
    return next?.type === 'bed' && next.audio === item.audio && next.start === item.start + item.dur && Math.abs(next.offset - (item.offset + item.dur)) < CONTINUE_MS;
  }

  private evergreenTick(now: number, gap: TimelineItem | null): void {
    const file = this.evergreenFile;
    const pos = file ? evergreenAt(file, now) : null;
    if (!pos) {
      if (this.key !== 'offline') {
        this.key = 'offline';
        this.stopVideo();
        this.deps.audio.stop();
        this.state = { ...this.state, item: gap, evergreen: null, mode: 'offline', playerVisible: false, needsTap: false, praying: NO_IDS, hasData: this.timeline.coveredUntil() > 0 };
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
      praying: NO_IDS,
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
    this.command();
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
    // A seek starts a stopped YouTube video: never one for a listener who is out.
    if (!this.state.joined) return;
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
    this.commandAt = now;
    player.seek(expected + 0.3);
  }

  private contextFor(item: TimelineItem, now: number): Pick<EngineState, 'program' | 'next' | 'submissions' | 'upNext' | 'hasData'> {
    const slot = this.timeline.slotAt(now);
    this.contextT = slot?.t ?? -1;
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
    } else if (next.type === 'jingle' || next.type === 'contrib' || (next.type === 'bed' && !(item.type === 'bed' && item.audio === next.audio))) {
      this.deps.audio.preload(next.audio);
    }
  }

  private emit(): void {
    this.deps.onChange(this.state);
  }
}
