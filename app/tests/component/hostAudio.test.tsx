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
