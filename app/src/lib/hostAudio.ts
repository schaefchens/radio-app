/**
 * Our own audio (host clips, jingles, listener recordings): two <audio>
 * elements used alternately, so the next clip can load while the current one
 * plays.
 *
 * iOS lets a media element play without a gesture only after it has played
 * once inside one — hence `unlock()`, called from the "tap to join" handler,
 * which plays a silent clip on both elements. iOS also ignores
 * `element.volume`, so fades go through a Web Audio GainNode where available.
 */

// 0.15 s of silence (MP3, 8 kbps mono), generated with ffmpeg.
const SILENCE = 'data:audio/mpeg;base64,/+MYxAAAAANIAAAAAExBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVMQU1FMy4xMDBVVVVVVVVVVVVV/+MYxDsAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxHYAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxLEAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxMQAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV';

export class HostAudio {
  private readonly els: [HTMLAudioElement, HTMLAudioElement];
  private active = 0;
  private ctx: AudioContext | null = null;
  private gains: (GainNode | null)[] = [null, null];
  private volume = 1;
  unlocked = false;

  constructor() {
    const make = (): HTMLAudioElement => {
      const a = new Audio();
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

  private el(): HTMLAudioElement {
    return this.els[this.active]!;
  }

  /** Play `url` from `offsetMs` in. Resolves false if the browser refused. */
  async play(url: string, offsetMs: number): Promise<boolean> {
    const next = (this.active + 1) % 2;
    const el = this.els[next]!;
    this.stop();
    this.active = next;
    this.setGain(next, this.volume);
    if (!el.src.endsWith(url)) el.src = url;
    const seek = (): void => {
      try {
        el.currentTime = Math.max(0, offsetMs / 1000);
      } catch {
        /* not seekable yet */
      }
    };
    if (el.readyState >= 1) seek();
    else el.addEventListener('loadedmetadata', seek, { once: true });
    try {
      await el.play();
      return true;
    } catch {
      return false;
    }
  }

  /** Warm the idle element with the next clip. */
  preload(url: string): void {
    const idle = this.els[(this.active + 1) % 2]!;
    if (idle.paused && !idle.src.endsWith(url)) idle.src = url;
  }

  stop(): void {
    for (const el of this.els) {
      if (!el.paused) el.pause();
    }
  }

  playing(): boolean {
    return !this.el().paused;
  }

  positionMs(): number {
    return this.el().currentTime * 1000;
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

  private setGain(i: number, v: number): void {
    const g = this.gains[i];
    if (g && this.ctx) g.gain.setTargetAtTime(v, this.ctx.currentTime, 0.05);
  }

  /** Soften the end of the current clip (a fade the element volume can't do on iOS). */
  fadeOut(ms = 400): void {
    const g = this.gains[this.active];
    if (g && this.ctx) g.gain.setTargetAtTime(0, this.ctx.currentTime, ms / 3000);
    else window.setTimeout(() => this.stop(), ms);
  }
}
