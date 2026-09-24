import { describe, expect, it, vi } from 'vitest';
import type { EvergreenFile, LiveFile, SlotFile, TimelineItem } from '@arche/shared';
import { RadioEngine, type AudioLike, type EngineState, type PlayerLike } from '@/lib/engine';
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

function fakeAudio(): AudioLike & { calls: string[] } {
  return {
    unlocked: false,
    calls: [],
    unlock() { this.unlocked = true; },
    async play(url, off) { this.calls.push(`play ${url} ${Math.round(off)}`); return true; },
    preload(url) { this.calls.push(`preload ${url}`); },
    stop() { this.calls.push('stop'); },
    resync() {},
  };
}

const items: TimelineItem[] = [
  { id: 's1', type: 'song', start: 0, dur: 200_000, p: 'live', yt: 'AAAAAAAAAAA', title: 'One', artist: 'X', thumb: null, request: null, fallback: '/media/jingles/j.mp3' },
  { id: 'h1', type: 'host', start: 200_000, dur: 20_000, p: 'live', kind: 'break', audio: { en: '/media/host/en.mp3', de: '/media/host/de.mp3' }, text: { en: 'Hello', de: 'Hallo' }, voices: [] },
  { id: 's2', type: 'song', start: 220_000, dur: 300_000, p: 'live', yt: 'BBBBBBBBBBB', title: 'Two', artist: 'Y', thumb: null, request: null, fallback: null },
];
const slotFile: SlotFile = { v: 1, channel: 'main', t: 0, gen: 0, current: 'live', next: null, submissions: { song: 'open' }, programs: {}, items };
const live: LiveFile = { v: 1, channel: 'main', gen: 0, listeners: 7, voices: [], blocked: [], pulse: 120 };

function setup(opts: { slot?: SlotFile | null; canAutoplay?: boolean; evergreen?: EvergreenFile } = {}) {
  let now = 50_000;
  const player = fakePlayer();
  const audio = fakeAudio();
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

  it('keeps listeners and voices from live.json', async () => {
    const s = setup();
    vi.useFakeTimers();
    await s.engine.start('main');
    expect(s.engine.snapshot.listeners).toBe(7);
    s.engine.stop();
    vi.useRealTimers();
  });
});
