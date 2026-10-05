import { describe, expect, it } from 'vitest';
import { HostAudio } from '@/lib/hostAudio';

/**
 * A media element as far as HostAudio uses it: jsdom plays nothing, so its
 * state is ours — the file's length arrives a moment after `src` is set.
 */
function fake(el: HTMLAudioElement, lengthS: number): { plays: number; src: () => string; at: () => number; end: () => void } {
  const st = { paused: true, ended: false, readyState: 0, duration: NaN, currentTime: 0, plays: 0, src: '' };
  Object.defineProperties(el, {
    paused: { get: () => st.paused },
    ended: { get: () => st.ended },
    readyState: { get: () => st.readyState },
    duration: { get: () => st.duration },
    currentTime: { get: () => st.currentTime, set: (v: number) => (st.currentTime = v) },
    src: {
      get: () => st.src,
      set: (v: string) => {
        st.src = new URL(v, document.baseURI).href;
        st.readyState = 0;
        st.ended = false;
        setTimeout(() => {
          st.readyState = 1;
          st.duration = lengthS;
          el.dispatchEvent(new Event('loadedmetadata'));
        }, 5);
      },
    },
  });
  el.play = async () => {
    st.plays++;
    st.paused = false;
  };
  el.pause = () => {
    st.paused = true;
  };
  return {
    get plays() {
      return st.plays;
    },
    src: () => st.src,
    at: () => st.currentTime,
    end: () => {
      st.ended = true;
      st.paused = true;
      st.currentTime = lengthS;
    },
  };
}

function setup(lengthS = 3) {
  const audio = new HostAudio();
  const els = (audio as unknown as { els: [HTMLAudioElement, HTMLAudioElement] }).els;
  return { audio, a: fake(els[0], lengthS), b: fake(els[1], lengthS) };
}

describe('our own audio', () => {
  it('a clip starts where its item is, once its length is known — never from its start', async () => {
    const { audio, a, b } = setup();
    expect(await audio.play('/media/host/r.mp3', 1500)).toBe(true);
    const playing = a.plays + b.plays;
    expect(playing).toBe(1);
    expect(Math.round((b.plays ? b.at() : a.at()) * 10) / 10).toBeGreaterThanOrEqual(1.5);
  });

  it('re-entering an item in the quiet after its clip (a sheet closed, a resume) does not start the clip again, nor take the next one\'s element', async () => {
    const { audio, a, b } = setup();
    await audio.play('/media/host/r.mp3', 0);
    const clip = b.plays ? b : a;
    const idle = clip === b ? a : b;
    clip.end();
    audio.preload('/media/host/next.mp3');
    expect(idle.src()).toContain('/media/host/next.mp3');
    // Four seconds of quiet follow a listener's words; two of them have passed.
    expect(await audio.play('/media/host/r.mp3', 5000)).toBe(true);
    expect(clip.plays + idle.plays).toBe(1);
    expect(idle.src()).toContain('/media/host/next.mp3');
  });

  it('entering an item after its clip for the first time (a join in the quiet) plays nothing', async () => {
    const { audio, a, b } = setup();
    expect(await audio.play('/media/host/r.mp3', 5000)).toBe(true);
    expect(a.plays + b.plays).toBe(0);
  });
});


/**
 * As a browser does it: the length arrives `loadMs` after `src` is set, play()
 * settles a moment later, and a pause() before then rejects it with
 * AbortError ("interrupted by a call to pause()").
 */
function browserLike(el: HTMLAudioElement, lengthS: number, loadMs: number): { plays: number; playing: () => boolean } {
  const st = { paused: true, readyState: 0, duration: NaN, currentTime: 0, src: '', plays: 0 };
  let pending: ((e: Error) => void) | null = null;
  Object.defineProperties(el, {
    paused: { get: () => st.paused },
    ended: { get: () => false },
    error: { get: () => null },
    readyState: { get: () => st.readyState },
    duration: { get: () => st.duration },
    currentTime: { get: () => st.currentTime, set: (v: number) => (st.currentTime = v) },
    src: {
      get: () => st.src,
      set: (v: string) => {
        st.src = new URL(v, document.baseURI).href;
        st.readyState = 0;
        setTimeout(() => {
          st.readyState = 1;
          st.duration = lengthS;
          el.dispatchEvent(new Event('loadedmetadata'));
        }, loadMs);
      },
    },
  });
  el.play = () =>
    new Promise<void>((resolve, reject) => {
      st.plays++;
      st.paused = false;
      pending = reject;
      setTimeout(() => {
        if (pending !== reject) return;
        pending = null;
        resolve();
      }, 20);
    });
  el.pause = () => {
    st.paused = true;
    const abort = pending;
    pending = null;
    abort?.(new DOMException('The play() request was interrupted by a call to pause().', 'AbortError'));
  };
  return {
    get plays() {
      return st.plays;
    },
    playing: () => !st.paused,
  };
}

// The stage back in view, a sheet closed, a turned phone: the item is entered
// again while its clip is still starting. That was taken for the browser
// refusing it, and "Tap to resume" showed over a host who went on speaking.
describe('a clip started again while it starts', () => {
  const setupBrowserLike = (loadMs: [number, number]) => {
    const audio = new HostAudio();
    const els = (audio as unknown as { els: [HTMLAudioElement, HTMLAudioElement] }).els;
    return { audio, a: browserLike(els[0], 30, loadMs[0]), b: browserLike(els[1], 30, loadMs[1]) };
  };

  it('is no refusal when the second start pauses the first', async () => {
    const { audio, a, b } = setupBrowserLike([5, 5]);
    const first = audio.play('/media/host/r.mp3', 0);
    // The first is in play() by now; the second's stop() aborts it.
    await new Promise((r) => setTimeout(r, 10));
    const second = audio.play('/media/host/r.mp3', 10);
    expect(await first).toBe(true);
    expect(await second).toBe(true);
    expect(a.playing() !== b.playing()).toBe(true);
  });

  it('does not play the first once it has loaded: the host once spoke twice over himself', async () => {
    const { audio, a, b } = setupBrowserLike([40, 5]);
    const first = audio.play('/media/host/r.mp3', 0);
    const second = audio.play('/media/host/r.mp3', 0);
    expect(await second).toBe(true);
    expect(await first).toBe(true);
    await new Promise((r) => setTimeout(r, 60));
    expect([a.playing(), b.playing()].filter(Boolean)).toHaveLength(1);
  });
});

// The radio takes our clip paused from outside (the lock screen, headphones)
// as the listener pausing, and leaves. A clip still loading is paused too: the
// break after a long song is not fetched ahead, and at its start the radio
// left for everyone listening.
describe('paused from outside', () => {
  it('not while the clip loads or starts; yes once it plays and something else pauses it', async () => {
    const audio = new HostAudio();
    const els = (audio as unknown as { els: [HTMLAudioElement, HTMLAudioElement] }).els;
    browserLike(els[0], 30, 60);
    browserLike(els[1], 30, 60);
    const starting = audio.play('/media/host/late.mp3', 0);
    expect(audio.pausedFromOutside()).toBe(false);
    await new Promise((r) => setTimeout(r, 40));
    expect(audio.pausedFromOutside()).toBe(false);
    expect(await starting).toBe(true);
    expect(audio.pausedFromOutside()).toBe(false);
    // The lock screen pauses the element itself.
    for (const el of els) if (!el.paused) el.pause();
    expect(audio.pausedFromOutside()).toBe(true);
  });

  it('not for a clip entered after its end, which never starts', async () => {
    const { audio, a, b } = setup(3);
    expect(await audio.play('/media/host/r.mp3', 5000)).toBe(true);
    expect(a.plays + b.plays).toBe(0);
    expect(audio.pausedFromOutside()).toBe(false);
  });
});
