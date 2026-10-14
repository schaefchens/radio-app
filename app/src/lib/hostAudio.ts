/**
 * Our own audio: the host, listeners' recordings and jingles (voice), and the
 * prayer hour's prayer music (music). Each has two <audio> elements used
 * alternately, so the next clip can load while the current one plays.
 *
 * Voice plays from its element straight to the speaker. Safari passes an
 * element routed through Web Audio over a ring buffer whose reader, once it
 * falls behind, skips ahead to the newest sound (WebKit's
 * WebAudioSourceProviderCocoa) — and on an iPhone SE the host's "Danke"
 * aired as "..ke", with words lost mid-clip and at its end too (2026-10-11).
 * Music goes through Web Audio only where its fades need it, on iOS, which
 * ignores `element.volume` (a moment of music lost under a fade goes
 * unheard). Everywhere else it plays as voice does and fades with its
 * element's own volume: through Web Audio, alone there once voice had left
 * it, the Android app played the prayer music as silence while the host was
 * heard (2026-10-14).
 *
 * Voice is played whole. A clip entered near its start begins at its first
 * word — what made it late (the tick, loading) is ours, and a word cut to
 * keep in step cost more than a moment that ends late —, it is never sought,
 * and when the program moves on its last words finish before the next clip
 * speaks.
 *
 * iOS lets a media element play without a gesture only after it has played
 * once inside one — hence `unlock()`, called from the "tap to join" handler,
 * which plays a silent clip on every element.
 *
 * Clips come from the CDN when there is one. Web Audio plays a cross-origin
 * element only if it was loaded with CORS (otherwise it outputs silence),
 * hence `crossOrigin`; a clip the edge cannot deliver is played from the site.
 */

import { cdnFailed, cdnUrl } from './cdn';
import type { OwnKind } from './engine';

// 0.15 s of silence (MP3, 8 kbps mono), generated with ffmpeg.
const SILENCE = 'data:audio/mpeg;base64,/+MYxAAAAANIAAAAAExBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVMQU1FMy4xMDBVVVVVVVVVVVVV/+MYxDsAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxHYAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxLEAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxMQAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV';

/** A voice clip entered this close to its start plays from its first word. */
export const WHOLE_MS = 2000;
/** A voice clip with this little left when the program moves on speaks it to the end. */
export const FINISH_MS = 3000;

/** Elements 0 and 1 speak, 2 and 3 play prayer music. */
const PAIRS: Record<OwnKind, [number, number]> = { voice: [0, 1], music: [2, 3] };
/** How often an element's own volume moves during a fade. */
const FADE_STEP_MS = 50;

export class HostAudio {
  private readonly els: HTMLAudioElement[];
  /** Per kind, the element its latest clip went to. */
  private active: Record<OwnKind, number> = { voice: 0, music: 2 };
  private ctx: AudioContext | null = null;
  /** Music on iOS only: voice never goes through Web Audio. */
  private gains: (GainNode | null)[] = [null, null, null, null];
  /** Per element, the fade of its own volume under way (no GainNode). */
  private fades: (number | null)[] = [null, null, null, null];
  private volume = 1;
  /** Per element, the play() of ours it is on (0: none, or we paused it since). */
  private started = [0, 0, 0, 0];
  /** Per element, the play() of ours still on its way: loading, waiting for the clip before, or play() not settled yet. */
  private pending = [0, 0, 0, 0];
  private plays = 0;
  unlocked = false;

  constructor() {
    const make = (): HTMLAudioElement => {
      const a = new Audio();
      a.crossOrigin = 'anonymous';
      a.preload = 'auto';
      a.setAttribute('playsinline', '');
      return a;
    };
    this.els = [make(), make(), make(), make()];
  }

  /** Must run inside a user gesture (the join tap). */
  unlock(): void {
    try {
      const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
      if (Ctor && !this.ctx && volumeIgnored()) {
        const ctx = new Ctor();
        this.ctx = ctx;
        for (const i of PAIRS.music) {
          const src = ctx.createMediaElementSource(this.els[i]!);
          const g = ctx.createGain();
          g.gain.value = this.volume;
          src.connect(g).connect(ctx.destination);
          this.gains[i] = g;
        }
      }
      void this.ctx?.resume();
    } catch {
      this.ctx = null; // plain element playback still works
    }
    for (const el of this.els) {
      el.src = SILENCE;
      const p = el.play();
      if (p) p.then(() => el.pause()).catch(() => undefined);
    }
    this.unlocked = true;
  }

  /** Wake our audio engine (iOS's, for the music) if the system put it to sleep — a call, the lock screen: inside a later tap, and as music starts. */
  wake(): void {
    if (this.ctx && this.ctx.state !== 'running') void this.ctx.resume().catch(() => undefined);
  }

  /**
   * Play `url` from `offsetMs` in. Voice entered within WHOLE_MS of its start
   * plays from its first word, once the clip before has spoken its last;
   * music starts where the program is, rising from silence over `fadeInMs`.
   * Resolves false only if the browser refused it while it was still ours to
   * play: stopped or replaced by us as it started (a re-entry, the next clip),
   * our own pause makes the browser abort its play() — and taking that for a
   * refusal put "Tap to resume" on screen while the clip played on.
   */
  async play(url: string, offsetMs: number, kind: OwnKind = 'voice', fadeInMs = 0): Promise<boolean> {
    // In the quiet after a clip (a listener's words, then a few seconds to
    // take them in), re-entering its item — a sheet closed, a join, a resume
    // — must not start it over, nor take the next clip's element.
    if (this.isOver(url, offsetMs)) return true;
    const i = this.pick(kind, cdnUrl(url));
    const el = this.els[i]!;
    this.stopEl(i);
    // Prayer music faded out before its piece ended; a voice clip that began
    // late still has its last words to say — under the music, or before the
    // next clip speaks.
    for (const j of PAIRS.music) if (j !== i) this.stopEl(j);
    const before = this.settleVoice(i);
    const behind = kind === 'voice' ? before : null;
    const whole = kind === 'voice' && offsetMs <= WHOLE_MS;
    this.active[kind] = i;
    const token = ++this.plays;
    this.started[i] = token;
    this.pending[i] = token;
    const refused = (): boolean => {
      if (this.started[i] !== token) return true;
      this.started[i] = 0;
      return false;
    };
    // On iOS: a context the system put to sleep since (a call, the lock
    // screen) would play the music as silence, with nothing refused.
    if (kind === 'music') this.wake();
    this.rise(i, fadeInMs);
    const started = Date.now();
    const start = async (src: string): Promise<boolean> => {
      if (!isSrc(el, src)) el.src = src;
      if (behind) await behind;
      // Stopped or replaced meanwhile: playing now would put the host's
      // words twice over each other.
      if (this.started[i] !== token) return true;
      if (!whole) {
        // Its length first: before it, a fresh element would play the clip's
        // start until it knew where to seek — which may be past its end.
        if (el.readyState < 1) await metadata(el);
        if (this.started[i] !== token) return true;
      }
      const at = whole ? 0 : Math.max(0, (offsetMs + Date.now() - started) / 1000);
      if (!whole && Number.isFinite(el.duration) && el.duration > 0 && at >= el.duration - 0.05) {
        // Past its end (the quiet after it): nothing of ours plays, and a clip
        // never started must not look paused from outside.
        this.started[i] = 0;
        return true;
      }
      try {
        // A fresh element is at its start already: seeking there would only wait for the network.
        if (el.currentTime !== at) el.currentTime = at;
      } catch {
        /* not seekable yet */
      }
      await el.play();
      return true;
    };
    const src = cdnUrl(url);
    try {
      return await start(src);
    } catch (e) {
      // NotSupportedError: the file did not load (CORS, network, the edge
      // down). NotAllowedError is the autoplay policy — the site cannot help.
      if (src === url || !(e instanceof DOMException) || e.name !== 'NotSupportedError') return refused();
      cdnFailed();
      try {
        return await start(url);
      } catch {
        return refused();
      }
    } finally {
      if (this.pending[i] === token) this.pending[i] = 0;
    }
  }

  /** `url` is loaded on one of our elements, and `offsetMs` lies past its end. */
  private isOver(url: string, offsetMs: number): boolean {
    return this.els.some(
      (el) =>
        (isSrc(el, cdnUrl(url)) || isSrc(el, url)) && el.readyState >= 1 && Number.isFinite(el.duration) && el.duration > 0 && offsetMs / 1000 >= el.duration - 0.05,
    );
  }

  /**
   * The element for a clip: one that has it already (fetched ahead, or a
   * start of it still waiting), else the idle one — never one that still
   * speaks its last words.
   */
  private pick(kind: OwnKind, src: string): number {
    const active = this.active[kind];
    const [x, y] = PAIRS[kind];
    const idle = active === x ? y : x;
    for (const j of [active, idle]) if (this.els[j]!.paused && isSrc(this.els[j]!, src)) return j;
    return this.els[idle]!.paused || !this.els[active]!.paused ? idle : active;
  }

  /**
   * Voice gives way: a clip with at most FINISH_MS left speaks to its end —
   * begun late, it would lose its last words — and any other stops. Resolves
   * once those words are spoken (null: nothing left to wait for).
   */
  private settleVoice(except: number): Promise<void> | null {
    let behind: Promise<void> | null = null;
    for (const j of PAIRS.voice) {
      if (j === except) continue;
      if (this.finishing(j)) behind = this.spoken(j);
      else this.stopEl(j);
    }
    return behind;
  }

  private finishing(j: number): boolean {
    const el = this.els[j]!;
    return this.started[j] !== 0 && this.pending[j] === 0 && !el.paused && !el.ended && el.duration - el.currentTime <= FINISH_MS / 1000;
  }

  /**
   * Resolves when element `j` has spoken to its end. One that stalls is
   * stopped once its words should have been spoken: it must not hold the next
   * clip back, nor speak over it later.
   */
  private spoken(j: number): Promise<void> {
    const el = this.els[j]!;
    const token = this.started[j];
    return new Promise((resolve) => {
      const done = (): void => {
        el.removeEventListener('ended', done);
        el.removeEventListener('pause', done);
        clearTimeout(timer);
        resolve();
      };
      const timer = setTimeout(
        () => {
          if (this.started[j] === token) this.stopEl(j);
          done();
        },
        (el.duration - el.currentTime) * 1000 + 1000,
      );
      el.addEventListener('ended', done);
      el.addEventListener('pause', done);
    });
  }

  /** Warm the idle element of its kind with the next clip. */
  preload(url: string, kind: OwnKind = 'voice'): void {
    const [x, y] = PAIRS[kind];
    const idle = this.els[this.active[kind] === x ? y : x]!;
    const src = cdnUrl(url);
    if (idle.paused && !isSrc(idle, src)) idle.src = src;
  }

  /** Everything of ours stops at once: the listener's pause. */
  stop(): void {
    this.els.forEach((_, j) => this.stopEl(j));
  }

  /**
   * The program moved on to something not ours (a song, silence): a voice
   * clip speaks its last words — begun late, it would lose them — and the
   * rest stops. Prayer music faded out before its piece ended.
   */
  finish(): void {
    for (const j of PAIRS.music) this.stopEl(j);
    void this.settleVoice(-1);
  }

  private stopEl(j: number): void {
    this.started[j] = 0;
    this.stopFade(j);
    const el = this.els[j]!;
    if (!el.paused) el.pause();
  }

  /** Where in `url` playback is (ms) when one of our elements plays it, else null. */
  playingAt(url: string): number | null {
    const el = this.els.find((e) => !e.paused && !e.ended && (isSrc(e, cdnUrl(url)) || isSrc(e, url)));
    return el ? el.currentTime * 1000 : null;
  }

  /** Any of our clips is playing. */
  playing(): boolean {
    return this.els.some((el) => !el.paused && !el.ended);
  }

  /**
   * A clip we started is paused, and not by us: the lock screen, headphones
   * or a call paused the element itself. (One that ended or failed is not,
   * nor one still loading: a clip not fetched ahead — the break after a song
   * longer than the five fixed minutes — is paused while it loads, and the
   * radio left for the listener at the start of the break.)
   */
  pausedFromOutside(): boolean {
    return this.els.some((el, j) => this.started[j] !== 0 && this.pending[j] === 0 && el.paused && !el.ended && !el.error);
  }

  /** Keep the prayer music on the shared clock. Voice is never sought: begun late, it ends late — sought, it lost words. */
  resync(expectedMs: number, toleranceMs = 800): void {
    const el = this.els[this.active.music]!;
    if (el.paused || el.readyState < 1) return;
    if (Math.abs(el.currentTime * 1000 - expectedMs) > toleranceMs) el.currentTime = expectedMs / 1000;
  }

  setVolume(v: number): void {
    this.volume = Math.max(0, Math.min(1, v));
    this.els.forEach((el, i) => {
      if (this.gains[i]) {
        this.setGain(i, this.volume);
        return;
      }
      // iOS ignores the volume (and shows no slider), not muting: the radio
      // must not bleed into a listener's recording.
      el.muted = this.volume === 0;
      // A fade under way ends where it should: the new volume, or silence.
      if (this.fades[i] == null) el.volume = this.volume;
    });
  }

  /** Element `i` at the listener's volume, music rising from silence over `riseMs`. */
  private rise(i: number, riseMs: number): void {
    if (this.gains[i] && this.ctx) {
      this.setGain(i, this.volume, riseMs);
      return;
    }
    // A piece faded out before stays silent unless it is raised again.
    this.fade(i, riseMs > 0 ? 0 : this.volume, null, riseMs);
  }

  private setGain(i: number, v: number, riseMs = 0): void {
    const g = this.gains[i];
    if (!g || !this.ctx) return;
    const t = this.ctx.currentTime;
    // A fade still running on this element (its last clip faded out) must not
    // pull the new one down.
    g.gain.cancelScheduledValues(t);
    if (riseMs > 0) g.gain.setValueAtTime(0, t);
    g.gain.setTargetAtTime(v, t, riseMs > 0 ? riseMs / 3000 : 0.05);
  }

  /**
   * Moves element `i`'s own volume from `from` to `to` (null: the listener's
   * volume as it is then) over `ms`, then calls `then` — the fade where no
   * GainNode does it.
   */
  private fade(i: number, from: number, to: number | null, ms: number, then?: () => void): void {
    const el = this.els[i]!;
    this.stopFade(i);
    el.muted = this.volume === 0;
    const t0 = Date.now();
    const step = (): boolean => {
      const k = ms > 0 ? Math.min(1, (Date.now() - t0) / ms) : 1;
      el.volume = from + ((to ?? this.volume) - from) * k;
      return k >= 1;
    };
    if (step()) {
      then?.();
      return;
    }
    this.fades[i] = window.setInterval(() => {
      if (!step()) return;
      this.stopFade(i);
      then?.();
    }, FADE_STEP_MS);
  }

  private stopFade(i: number): void {
    const f = this.fades[i];
    if (f == null) return;
    window.clearInterval(f);
    this.fades[i] = null;
  }

  /** Soften the end of the prayer music. */
  fadeOut(ms = 400): void {
    const i = this.active.music;
    const g = this.gains[i];
    if (g && this.ctx) {
      g.gain.cancelScheduledValues(this.ctx.currentTime);
      g.gain.setTargetAtTime(0, this.ctx.currentTime, ms / 3000);
      return;
    }
    // Down to silence, then this piece stops — by then the next one may be
    // playing on the other element.
    const el = this.els[i]!;
    const token = this.started[i];
    this.fade(i, el.volume, 0, ms, () => {
      if (this.started[i] !== token) return; // a newer clip is on this element now
      this.started[i] = 0;
      el.pause();
    });
  }
}

/**
 * iOS ignores a media element's volume — it reads 1 whatever was set — so
 * only there do the fades need Web Audio.
 */
function volumeIgnored(): boolean {
  const probe = document.createElement('audio');
  try {
    probe.volume = 0.5;
  } catch {
    return true;
  }
  return probe.volume !== 0.5;
}

/** Resolves once `el` knows its length — or failed, or 3 s went by (play() then says what is wrong). */
function metadata(el: HTMLAudioElement): Promise<void> {
  return new Promise((resolve) => {
    const done = (): void => {
      el.removeEventListener('loadedmetadata', done);
      el.removeEventListener('error', done);
      clearTimeout(timer);
      resolve();
    };
    const timer = setTimeout(done, 3000);
    el.addEventListener('loadedmetadata', done);
    el.addEventListener('error', done);
  });
}

/** Whether the element already holds `src` (a path or an absolute URL: the edge's copy is another file than the site's). */
function isSrc(el: HTMLAudioElement, src: string): boolean {
  try {
    return el.src === new URL(src, document.baseURI).href;
  } catch {
    return false;
  }
}
