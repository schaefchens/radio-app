import { describe, expect, it, vi } from 'vitest';
import type { EvergreenFile, LiveFile, SlotFile, TimelineItem } from '@arche/shared';
import { BED_FADE_MS, RadioEngine, initialState, type AudioLike, type EngineState, type PlayerLike } from '@/lib/engine';
import { YTState } from '@/lib/youtube';

function fakePlayer(): PlayerLike & { calls: string[]; t: number; st: number } {
  return {
    mounted: true,
    currentId: null,
    calls: [],
    t: 0,
    st: YTState.UNSTARTED,
    load(id, s) { this.calls.push(`load ${id} ${s.toFixed(1)}`); this.currentId = id; },
    cue(id, s) { this.calls.push(`cue ${id} ${s.toFixed(1)}`); },
    play() { this.calls.push('play'); },
    stop() { this.calls.push('stop'); this.currentId = null; },
    seek(s) { this.calls.push(`seek ${s.toFixed(1)}`); },
    time() { return this.t; },
    state() { return this.st; },
  };
}

/** Our audio, playing on the test's clock: it knows where its file is. */
function fakeAudio(now: () => number): AudioLike & { calls: string[] } {
  let url: string | null = null;
  let base = 0;
  let since = 0;
  return {
    unlocked: false,
    calls: [],
    unlock() { this.unlocked = true; },
    async play(u, off, fade = 0) {
      this.calls.push(`play ${u} ${Math.round(off)}${fade ? ` fade ${fade}` : ''}`);
      url = u;
      base = off;
      since = now();
      return true;
    },
    preload(u) { this.calls.push(`preload ${u}`); },
    stop() { this.calls.push('stop'); url = null; },
    resync(ms) { this.calls.push(`resync ${Math.round(ms)}`); },
    fadeOut(ms) { this.calls.push(`fadeOut ${ms}`); },
    playingAt(u) { return u === url ? base + now() - since : null; },
  };
}

const items: TimelineItem[] = [
  { id: 's1', type: 'song', start: 0, dur: 200_000, p: 'live', yt: 'AAAAAAAAAAA', title: 'One', artist: 'X', thumb: null, request: null, fallback: '/media/jingles/j.mp3' },
  { id: 'h1', type: 'host', start: 200_000, dur: 20_000, p: 'live', kind: 'break', audio: { en: '/media/host/en.mp3', de: '/media/host/de.mp3' }, text: { en: 'Hello', de: 'Hallo' }, voices: [], prayers: [] },
  { id: 's2', type: 'song', start: 220_000, dur: 300_000, p: 'live', yt: 'BBBBBBBBBBB', title: 'Two', artist: 'Y', thumb: null, request: null, fallback: null },
];
const slotFile: SlotFile = { v: 1, channel: 'main', t: 0, gen: 0, current: 'live', next: null, submissions: { song: 'open' }, programs: {}, items };
const live: LiveFile = {
  v: 1,
  channel: 'main',
  gen: 0,
  listeners: 7,
  voices: [],
  wall: [{ id: 'pk3v9q2m7x4tb', text: 'Please pray for my mother.', at: 1000 }],
  blocked: [],
  pulse: 120,
};

function setup(opts: { slot?: SlotFile | null; canAutoplay?: boolean; evergreen?: EvergreenFile } = {}) {
  let now = 50_000;
  const player = fakePlayer();
  const audio = fakeAudio(() => now);
  const states: EngineState[] = [];
  const errors: [string, number][] = [];
  const engine = new RadioEngine({
    now: () => now,
    player,
    audio,
    fetchSlot: async () => (opts.slot === undefined ? slotFile : opts.slot),
    fetchSlotWalkingBack: async () => (opts.slot === undefined ? slotFile : opts.slot),
    fetchLive: async () => live,
    fetchEvergreen: async () => opts.evergreen ?? null,
    canAutoplay: () => opts.canAutoplay ?? true,
    onChange: (s) => states.push(s),
    onPlaybackError: (id, code) => errors.push([id, code]),
  });
  return { engine, player, audio, states, errors, setNow: (t: number) => (now = t), get now() { return now; } };
}

describe('RadioEngine', () => {
  it('before joining: shows the song but plays nothing', async () => {
    const s = setup();
    await s.engine.start('main');
    expect(s.engine.snapshot.mode).toBe('song');
    expect(s.engine.snapshot.playerVisible).toBe(false);
    expect(s.player.calls.filter((c) => c.startsWith('load'))).toEqual([]);
    s.engine.stop();
  });

  it('joining loads the song at the live position; host audio follows at its offset', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join();
    expect(s.audio.unlocked).toBe(true);
    expect(s.player.calls).toContain('load AAAAAAAAAAA 50.4');
    expect(s.engine.snapshot.playerVisible).toBe(true);
    s.setNow(205_000);
    s.engine.tick();
    expect(s.engine.snapshot.mode).toBe('host');
    expect(s.engine.snapshot.playerVisible).toBe(false);
    expect(s.audio.calls).toContain('play /media/host/en.mp3 5000');
    expect(s.engine.snapshot.hostText).toBe('Hello');
    s.engine.setLang('de');
    expect(s.engine.snapshot.hostText).toBe('Hallo');
    s.engine.stop();
  });

  it('stopping silences everything and it stays stopped until the listener joins again', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join();
    s.engine.leave();
    expect(s.engine.snapshot.joined).toBe(false);
    expect(s.engine.snapshot.playerVisible).toBe(false);
    expect(s.player.calls.at(-1)).toBe('stop');
    // The program moves on, but neither the host nor the next song starts.
    const played = s.audio.calls.filter((c) => c.startsWith('play')).length;
    const loaded = s.player.calls.filter((c) => c.startsWith('load')).length;
    s.setNow(205_000);
    s.engine.tick();
    s.setNow(230_000);
    s.engine.tick();
    expect(s.engine.snapshot.mode).toBe('song');
    expect(s.audio.calls.filter((c) => c.startsWith('play')).length).toBe(played);
    expect(s.player.calls.filter((c) => c.startsWith('load')).length).toBe(loaded);
    s.engine.join();
    expect(s.player.calls.at(-1)).toBe('load BBBBBBBBBBB 10.4');
    s.engine.stop();
  });

  it('corrects drift once, then respects the cooldown; waits out an ad', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join();
    s.player.st = YTState.PLAYING;
    s.player.t = 40; // 10 s behind
    s.engine.onPlayerState(YTState.PLAYING);
    expect(s.player.calls.some((c) => c.startsWith('seek 50'))).toBe(true);
    const seeks = () => s.player.calls.filter((c) => c.startsWith('seek')).length;
    const before = seeks();
    s.setNow(56_000);
    s.engine.tick();
    expect(seeks()).toBe(before); // cooldown
    s.player.t = 0.2; // an ad: playing, stuck at zero
    s.setNow(80_000);
    s.engine.tick();
    expect(seeks()).toBe(before);
    s.engine.stop();
  });

  it('asks for a tap instead of autoplaying when the stage is not visible', async () => {
    const s = setup({ canAutoplay: false });
    await s.engine.start('main');
    s.engine.join();
    expect(s.player.calls).toContain('cue AAAAAAAAAAA 50.0');
    expect(s.engine.snapshot.needsTap).toBe(true);
    s.engine.stop();
  });

  it('a player error reports the item and plays its fallback', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join();
    s.engine.onPlayerError(150);
    expect(s.errors).toEqual([['s1', 150]]);
    expect(s.audio.calls).toContain('play /media/jingles/j.mp3 0');
    expect(s.engine.snapshot.playerVisible).toBe(false);
    s.engine.stop();
  });

  it('without program data it plays the evergreen loop, and offline without that', async () => {
    const evergreen: EvergreenFile = { v: 1, channel: 'main', epoch: 0, total: 100_000, items: [{ yt: 'EEEEEEEEEEE', title: 'E', artist: '', dur: 100_000, thumb: null }] };
    const s = setup({ slot: null, evergreen });
    s.engine.setEvergreenUrl('/program/main/evergreen-1.json');
    await Promise.resolve();
    await s.engine.start('main');
    s.engine.join();
    s.engine.tick();
    expect(s.engine.snapshot.mode).toBe('evergreen');
    expect(s.player.calls).toContain('load EEEEEEEEEEE 50.4');

    const t = setup({ slot: null });
    await t.engine.start('main');
    expect(t.engine.snapshot.mode).toBe('offline');
    s.engine.stop();
    t.engine.stop();
  });

  it('keeps listeners, voices and the prayer wall from live.json; a channel switch clears the wall', async () => {
    const s = setup();
    vi.useFakeTimers();
    await s.engine.start('main');
    expect(s.engine.snapshot.listeners).toBe(7);
    expect(s.engine.snapshot.wall).toEqual(live.wall);
    const switching = s.engine.start('night');
    // Until night's live.json arrives, main's prayers must not show as night's.
    expect(s.engine.snapshot.wall).toEqual([]);
    // The same empty list every time: a new [] per state would loop a zustand selector.
    expect(s.engine.snapshot.wall).toBe(initialState().wall);
    await switching;
    s.engine.stop();
    vi.useRealTimers();
  });
});

describe('prayer music', () => {
  const PAD = '/media/beds/pad.mp3';
  const bed = (id: string, start: number, dur: number, offset: number, audio = PAD): TimelineItem => ({
    id, type: 'bed', start, dur, p: 'prayer', audio, offset, label: { en: 'What can we pray for?', de: 'Wofür dürfen wir beten?' },
  });
  const slot = (items: TimelineItem[]): SlotFile => ({ ...slotFile, current: 'prayer', items });
  const plays = (calls: string[]) => calls.filter((c) => c.startsWith('play'));

  it('enters at its offset into the file plus the time since it began, rising in', async () => {
    const s = setup({ slot: slot([bed('b1', 40_000, 60_000, 30_000)]) });
    await s.engine.start('main');
    s.engine.join();
    expect(s.engine.snapshot.mode).toBe('bed');
    expect(s.engine.snapshot.playerVisible).toBe(false);
    expect(plays(s.audio.calls)).toEqual([`play ${PAD} 40000 fade ${BED_FADE_MS}`]);
    s.engine.stop();
  });

  it('fades out at the end of its piece, and a song after it stops our audio', async () => {
    const song: TimelineItem = { ...items[2]!, id: 's9', start: 100_000 };
    const s = setup({ slot: slot([bed('b1', 40_000, 60_000, 0), song]) });
    await s.engine.start('main');
    s.engine.join();
    s.setNow(99_000);
    s.engine.tick();
    s.engine.tick();
    expect(s.audio.calls.filter((c) => c.startsWith('fadeOut'))).toEqual([`fadeOut ${BED_FADE_MS}`]);
    s.setNow(100_500);
    s.engine.tick();
    expect(s.engine.snapshot.mode).toBe('song');
    expect(s.audio.calls.at(-1)).toBe('stop');
    s.engine.stop();
  });

  it('a piece that goes on with the same file plays on: no fade, no new start, nothing preloaded', async () => {
    const s = setup({ slot: slot([bed('b1', 40_000, 60_000, 0), bed('b2', 100_000, 60_000, 60_000)]) });
    await s.engine.start('main');
    s.engine.join();
    s.setNow(99_500);
    s.engine.tick();
    s.setNow(100_200);
    s.engine.tick();
    expect(s.engine.snapshot.item?.id).toBe('b2');
    expect(plays(s.audio.calls)).toHaveLength(1);
    expect(s.audio.calls.some((c) => c.startsWith('fadeOut') || c.startsWith('preload'))).toBe(false);
    s.engine.stop();
  });

  it('a piece that starts the file again fades the last one out and rises in', async () => {
    const s = setup({ slot: slot([bed('b1', 40_000, 60_000, 240_000), bed('b2', 100_000, 60_000, 0)]) });
    await s.engine.start('main');
    s.engine.join();
    s.setNow(99_500);
    s.engine.tick();
    s.setNow(100_200);
    s.engine.tick();
    expect(s.audio.calls.filter((c) => c.startsWith('fadeOut'))).toHaveLength(1);
    expect(plays(s.audio.calls).at(-1)).toBe(`play ${PAD} 200 fade ${BED_FADE_MS}`);
    s.engine.stop();
  });

  it('entering again while it plays (a sheet closed over the stage) does not start it over', async () => {
    const s = setup({ slot: slot([bed('b1', 40_000, 60_000, 0)]) });
    await s.engine.start('main');
    s.engine.join();
    s.setNow(70_000);
    s.engine.reenter();
    expect(plays(s.audio.calls)).toHaveLength(1);
    expect(s.audio.calls.at(-1)).toBe('resync 30000');
    s.engine.stop();
  });

  it('warms the next piece when it is another file', async () => {
    const s = setup({ slot: slot([bed('b1', 40_000, 60_000, 0), bed('b2', 100_000, 60_000, 0, '/media/beds/other.mp3')]) });
    await s.engine.start('main');
    s.engine.join();
    expect(s.audio.calls).toContain('preload /media/beds/other.mp3');
    s.engine.stop();
  });
});
