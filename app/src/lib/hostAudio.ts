/**
 * Our own audio (host clips, jingles, listener recordings, prayer music): two
 * <audio> elements used alternately, so the next clip can load while the
 * current one plays. Only one plays at a time.
 *
 * iOS lets a media element play without a gesture only after it has played
 * once inside one — hence `unlock()`, called from the "tap to join" handler,
 * which plays a silent clip on both elements. iOS also ignores
 * `element.volume`, so fades go through a Web Audio GainNode where available.
 *
 * Clips come from the CDN when there is one. Web Audio plays a cross-origin
 * element only if it was loaded with CORS (otherwise it outputs silence),
 * hence `crossOrigin`; a clip the edge cannot deliver is played from the site.
 */

import { cdnFailed, cdnUrl } from './cdn';

// 0.15 s of silence (MP3, 8 kbps mono), generated with ffmpeg.
const SILENCE = 'data:audio/mpeg;base64,/+MYxAAAAANIAAAAAExBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVMQU1FMy4xMDBVVVVVVVVVVVVV/+MYxDsAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxHYAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxLEAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxMQAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV';

export class HostAudio {
  private readonly els: [HTMLAudioElement, HTMLAudioElement];
  private active = 0;
  private ctx: AudioContext | null = null;
  private gains: (GainNode | null)[] = [null, null];
  private volume = 1;
  /** Per element, the play() of ours it is on (0: none, or we paused it since). */
  private started = [0, 0];
  /** Per element, the play() of ours still on its way: loading, or play() not settled yet. */
  private pending = [0, 0];
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
    this.els = [make(), make()];
  }

  /** Must run inside a user gesture (the join tap). */
  unlock(): void {
    try {
      const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
      if (Ctor && !this.ctx) {
        this.ctx = new Ctor();
        this.els.forEach((el, i) => {
          const src = this.ctx!.createMediaElementSource(el);
          const g = this.ctx!.createGain();
          g.gain.value = this.volume;
          src.connect(g).connect(this.ctx!.destination);
          this.gains[i] = g;
        });
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

  /** Inside a later tap: wake our audio engine if the system put it to sleep (a call, the lock screen). */
  wake(): void {
    if (this.ctx && this.ctx.state !== 'running') void this.ctx.resume().catch(() => undefined);
  }

  private el(): HTMLAudioElement {
    return this.els[this.active]!;
  }

  /**
   * Play `url` from `offsetMs` in, rising from silence over `fadeInMs`.
   * Resolves false only if the browser refused it while it was still ours to
   * play: stopped or replaced by us as it started (a re-entry, the next clip),
   * our own pause makes the browser abort its play() — and taking that for a
   * refusal put "Tap to resume" on screen while the clip played on.
   */
  async play(url: string, offsetMs: number, fadeInMs = 0): Promise<boolean> {
    // In the quiet after a clip (a listener's words, then a few seconds to
    // take them in), re-entering its item — a sheet closed, a join, a resume
    // — must not start it over, nor take the next clip's element.
    if (this.isOver(url, offsetMs)) return true;
    const next = (this.active + 1) % 2;
    const el = this.els[next]!;
    this.stop();
    this.active = next;
    const token = ++this.plays;
    this.started[next] = token;
    this.pending[next] = token;
    const refused = (): boolean => {
      if (this.started[next] !== token) return true;
      this.started[next] = 0;
      return false;
    };
    this.setGain(next, this.volume, fadeInMs);
    const started = Date.now();
    const start = async (src: string): Promise<boolean> => {
      if (!isSrc(el, src)) el.src = src;
      // Its length first: before it, a fresh element would play the clip's
      // start until it knew where to seek — which may be past its end.
      if (el.readyState < 1) await metadata(el);
      // Stopped or replaced while it loaded: playing now would put the
      // host's words twice over each other.
      if (this.started[next] !== token) return true;
      const at = Math.max(0, (offsetMs + Date.now() - started) / 1000);
      if (Number.isFinite(el.duration) && el.duration > 0 && at >= el.duration - 0.05) {
        // Past its end (the quiet after it): nothing of ours plays, and a clip
        // never started must not look paused from outside.
        this.started[next] = 0;
        return true;
      }
      try {
        el.currentTime = at;
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
      if (this.pending[next] === token) this.pending[next] = 0;
    }
  }

  /** `url` is loaded on one of our elements, and `offsetMs` lies past its end. */
  private isOver(url: string, offsetMs: number): boolean {
    return this.els.some(
      (el) =>
        (isSrc(el, cdnUrl(url)) || isSrc(el, url)) && el.readyState >= 1 && Number.isFinite(el.duration) && el.duration > 0 && offsetMs / 1000 >= el.duration - 0.05,
    );
  }

  /** Warm the idle element with the next clip. */
  preload(url: string): void {
    const idle = this.els[(this.active + 1) % 2]!;
    const src = cdnUrl(url);
    if (idle.paused && !isSrc(idle, src)) idle.src = src;
  }

  stop(): void {
    this.started = [0, 0];
    for (const el of this.els) {
      if (!el.paused) el.pause();
    }
  }

  /** Where in `url` playback is (ms) when it is the clip playing now, else null. */
  playingAt(url: string): number | null {
    const el = this.el();
    if (el.paused || el.ended || !(isSrc(el, cdnUrl(url)) || isSrc(el, url))) return null;
    return el.currentTime * 1000;
  }

  /** Any of our clips is playing. */
  playing(): boolean {
    return this.els.some((el) => !el.paused && !el.ended);
  }

  /**
   * The clip we started is paused, and not by us: the lock screen, headphones
   * or a call paused the element itself. (One that ended or failed is not,
   * nor one still loading: a clip not fetched ahead — the break after a song
   * longer than the five fixed minutes — is paused while it loads, and the
   * radio left for the listener at the start of the break.)
   */
  pausedFromOutside(): boolean {
    const el = this.el();
    return this.started[this.active] !== 0 && this.pending[this.active] === 0 && el.paused && !el.ended && !el.error;
  }

  /** Correct drift of the playing clip against the shared clock. */
  resync(expectedMs: number, toleranceMs = 800): void {
    const el = this.el();
    if (el.paused || el.readyState < 1) return;
    if (Math.abs(el.currentTime * 1000 - expectedMs) > toleranceMs) el.currentTime = expectedMs / 1000;
  }

  setVolume(v: number): void {
    this.volume = Math.max(0, Math.min(1, v));
    this.els.forEach((el, i) => {
      this.setGain(i, this.volume);
      if (!this.gains[i]) el.volume = this.volume; // no Web Audio: best effort (ignored on iOS)
    });
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

  /** Soften the end of the current clip (a fade the element volume can't do on iOS). */
  fadeOut(ms = 400): void {
    const g = this.gains[this.active];
    if (g && this.ctx) {
      g.gain.cancelScheduledValues(this.ctx.currentTime);
      g.gain.setTargetAtTime(0, this.ctx.currentTime, ms / 3000);
      return;
    }
    // Without Web Audio there is no fade: stop this clip only — by then the
    // next one may be playing on the other element.
    const i = this.active;
    const el = this.el();
    const token = this.started[i];
    window.setTimeout(() => {
      if (this.started[i] !== token) return; // a newer clip is on this element now
      this.started[i] = 0;
      el.pause();
    }, ms);
  }
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
