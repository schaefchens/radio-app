import { describe, expect, it, vi } from 'vitest';
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
  it('a voice clip entered near its start plays from its first word: "Danke" aired as "..ke" when it began where the clock was', async () => {
    const { audio, a, b } = setup(30);
    expect(await audio.play('/media/host/r.mp3', 400)).toBe(true);
    expect(a.plays + b.plays).toBe(1);
    expect(b.plays ? b.at() : a.at()).toBe(0);
  });

  it('joined in the middle, a clip starts where its item is, once its length is known — never from its start', async () => {
    const { audio, a, b } = setup(30);
    expect(await audio.play('/media/host/r.mp3', 5000)).toBe(true);
    expect(a.plays + b.plays).toBe(1);
    expect(Math.round((b.plays ? b.at() : a.at()) * 10) / 10).toBeGreaterThanOrEqual(5);
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
  // From its start (played at once) and joined in the middle (its length first).
  it.each([0, 5000])('not while the clip loads or starts; yes once it plays and something else pauses it (entered at %i ms)', async (offset) => {
    const audio = new HostAudio();
    const els = (audio as unknown as { els: [HTMLAudioElement, HTMLAudioElement] }).els;
    browserLike(els[0], 30, 60);
    browserLike(els[1], 30, 60);
    const starting = audio.play('/media/host/late.mp3', offset);
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

/**
 * An element that plays as far as the test says: `run(s)` moves it on, and at
 * the clip's end it ends as a browser's does — paused, then 'pause' and 'ended'.
 */
function speaking(el: HTMLAudioElement, lengthS: number) {
  const st = { paused: true, ended: false, readyState: 0, duration: NaN, currentTime: 0, src: '' };
  Object.defineProperties(el, {
    paused: { get: () => st.paused },
    ended: { get: () => st.ended },
    error: { get: () => null },
    readyState: { get: () => st.readyState },
    duration: { get: () => st.duration },
    currentTime: { get: () => st.currentTime, set: (v: number) => (st.currentTime = v) },
    src: {
      get: () => st.src,
      set: (v: string) => {
        Object.assign(st, { src: new URL(v, document.baseURI).href, readyState: 1, duration: lengthS, ended: false, currentTime: 0 });
      },
    },
  });
  el.play = async () => {
    st.paused = false;
    st.ended = false;
  };
  el.pause = () => {
    if (st.paused) return;
    st.paused = true;
    el.dispatchEvent(new Event('pause'));
  };
  return {
    playing: () => !st.paused,
    at: () => st.currentTime,
    run(s: number) {
      if (st.paused) return;
      st.currentTime = Math.min(lengthS, st.currentTime + s);
      if (st.currentTime < lengthS) return;
      st.ended = true;
      st.paused = true;
      el.dispatchEvent(new Event('pause'));
      el.dispatchEvent(new Event('ended'));
    },
  };
}

type Speaking = ReturnType<typeof speaking>;

function speakers(lengthS = 10) {
  const audio = new HostAudio();
  const els = (audio as unknown as { els: HTMLAudioElement[] }).els;
  const [a, b, m, n] = els.map((el) => speaking(el, lengthS)) as [Speaking, Speaking, Speaking, Speaking];
  return { audio, a, b, m, n };
}

// A clip begun late (loading, the tick, a slow phone) runs into what follows,
// and the next item cut off its last words.
describe('the last words of a clip', () => {
  it('are spoken when the program moves on to a song; a clip with more left stops', async () => {
    const { audio, a, b } = speakers();
    await audio.play('/media/host/one.mp3', 0);
    const one = a.playing() ? a : b;
    one.run(8.5);
    audio.finish();
    expect(one.playing()).toBe(true);

    await audio.play('/media/host/two.mp3', 0);
    const two = a.playing() ? a : b;
    expect(two).not.toBe(one);
    two.run(2);
    audio.finish();
    expect(two.playing()).toBe(false);
  });

  it('come before the next clip, which then plays from its first word', async () => {
    const { audio, a, b } = speakers();
    await audio.play('/media/host/one.mp3', 0);
    const one = a.playing() ? a : b;
    const two = one === a ? b : a;
    one.run(8.5);
    const next = audio.play('/media/contrib/two.mp3', 300);
    await new Promise((r) => setTimeout(r, 0));
    expect(one.playing()).toBe(true);
    expect(two.playing()).toBe(false);
    one.run(1.5);
    expect(await next).toBe(true);
    expect(two.playing()).toBe(true);
    expect(two.at()).toBe(0);
  });

  it('of a clip stuck on the network neither hold the next one back for ever nor come over it later', async () => {
    vi.useFakeTimers();
    try {
      const { audio, a, b } = speakers();
      await audio.play('/media/host/one.mp3', 0);
      const one = a.playing() ? a : b;
      const two = one === a ? b : a;
      one.run(9); // a second left, and then nothing comes
      const next = audio.play('/media/host/two.mp3', 0);
      await vi.advanceTimersByTimeAsync(1500);
      expect(two.playing()).toBe(false);
      await vi.advanceTimersByTimeAsync(1000);
      expect(await next).toBe(true);
      expect(one.playing()).toBe(false);
      expect(two.playing()).toBe(true);
    } finally {
      vi.useRealTimers();
    }
  });

  it('go on under prayer music, which plays on elements of its own and stops for the next voice', async () => {
    const { audio, a, b, m, n } = speakers();
    await audio.play('/media/host/one.mp3', 0);
    const one = a.playing() ? a : b;
    one.run(9);
    await audio.play('/media/beds/pad.mp3', 0, 'music', 1500);
    const pad = m.playing() ? m : n;
    expect(pad.playing()).toBe(true);
    expect(one.playing()).toBe(true);
    one.run(1);
    await audio.play('/media/host/two.mp3', 0);
    expect(pad.playing()).toBe(false);
  });
});

// Safari passes an element routed through Web Audio over a ring buffer whose
// reader skips ahead when it falls behind: on an iPhone SE words went missing.
describe('voice and Web Audio', () => {
  it('voice never goes through it; prayer music does, for its fades', () => {
    const routed: HTMLMediaElement[] = [];
    const gain = { value: 1, cancelScheduledValues() {}, setValueAtTime() {}, setTargetAtTime() {} };
    class FakeContext {
      state = 'running';
      currentTime = 0;
      destination = {};
      createMediaElementSource(el: HTMLMediaElement) {
        routed.push(el);
        return { connect: (node: unknown) => node };
      }
      createGain() {
        return { gain, connect: (node: unknown) => node };
      }
      resume() {
        return Promise.resolve();
      }
    }
    vi.stubGlobal('AudioContext', FakeContext);
    try {
      const audio = new HostAudio();
      const els = (audio as unknown as { els: HTMLAudioElement[] }).els;
      els.forEach((el) => fake(el, 3));
      audio.unlock();
      expect(routed).toEqual([els[2], els[3]]);
    } finally {
      vi.unstubAllGlobals();
    }
  });

  it('without it a listener recording still mutes the voice, which iOS will not turn down', () => {
    const audio = new HostAudio();
    const els = (audio as unknown as { els: HTMLAudioElement[] }).els;
    audio.setVolume(0);
    expect(els.slice(0, 2).map((el) => el.muted)).toEqual([true, true]);
    audio.setVolume(0.6);
    expect(els.slice(0, 2).map((el) => el.muted)).toEqual([false, false]);
    expect(els[0]!.volume).toBeCloseTo(0.6);
  });
});
