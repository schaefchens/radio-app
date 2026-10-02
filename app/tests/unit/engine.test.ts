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
    pause() { this.calls.push('pause'); },
    stop() { this.calls.push('stop'); this.currentId = null; },
    seek(s) { this.calls.push(`seek ${s.toFixed(1)}`); },
    time() { return this.t; },
    state() { return this.st; },
  };
}

/**
 * Our audio, playing on the test's clock: it knows where its file is.
 * `outside` is what the lock screen, headphones or a call do to the element.
 */
function fakeAudio(now: () => number): AudioLike & { calls: string[]; outside: 'paused' | 'resumed' | null } {
  let url: string | null = null;
  let base = 0;
  let since = 0;
  return {
    unlocked: false,
    calls: [],
    outside: null,
    unlock() { this.unlocked = true; },
    wake() { this.calls.push('wake'); },
    async play(u, off, fade = 0) {
      this.calls.push(`play ${u} ${Math.round(off)}${fade ? ` fade ${fade}` : ''}`);
      url = u;
      base = off;
      since = now();
      this.outside = null;
      return true;
    },
    preload(u) { this.calls.push(`preload ${u}`); },
    stop() { this.calls.push('stop'); url = null; this.outside = null; },
    resync(ms) { this.calls.push(`resync ${Math.round(ms)}`); },
    fadeOut(ms) { this.calls.push(`fadeOut ${ms}`); },
    playingAt(u) { return u === url && this.outside !== 'paused' ? base + now() - since : null; },
    playing() { return (url !== null && this.outside !== 'paused') || this.outside === 'resumed'; },
    pausedFromOutside() { return url !== null && this.outside === 'paused'; },
  };
}

const items: TimelineItem[] = [
  { id: 's1', type: 'song', kind: 'song', start: 0, dur: 200_000, p: 'live', yt: 'AAAAAAAAAAA', title: 'One', artist: 'X', thumb: null, request: null, fallback: '/media/jingles/j.mp3' },
  { id: 'h1', type: 'host', start: 200_000, dur: 20_000, p: 'live', kind: 'break', audio: { en: '/media/host/en.mp3', de: '/media/host/de.mp3' }, text: { en: 'Hello', de: 'Hallo' }, voices: [], prayers: [] },
  { id: 's2', type: 'song', kind: 'song', start: 220_000, dur: 300_000, p: 'live', yt: 'BBBBBBBBBBB', title: 'Two', artist: 'Y', thumb: null, request: null, fallback: null },
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
  let stage = opts.canAutoplay ?? true;
  let page = true;
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
    canAutoplay: () => stage,
    pageVisible: () => page,
    onChange: (s) => states.push(s),
    onPlaybackError: (id, code) => errors.push([id, code]),
  });
  return {
    engine,
    player,
    audio,
    states,
    errors,
    setNow: (t: number) => (now = t),
    get now() { return now; },
    setStage: (v: boolean) => (stage = v),
    setPage: (v: boolean) => (page = v),
  };
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

  /** An engine at a real minute, recording which files it asks for; `slowSlot` holds the minute files back. */
  function recording(opts: { slowSlot?: boolean } = {}) {
    const T = 1_789_999_980_000; // a minute boundary
    const clock = { now: T + 50_000 };
    const asked: string[] = [];
    const slot = (channel: string): SlotFile => ({ ...slotFile, channel, items: items.map((it) => ({ ...it, id: `${channel}-${it.id}`, start: it.start + T })) });
    const held: (() => void)[] = [];
    const player = fakePlayer();
    const engine = new RadioEngine({
      now: () => clock.now,
      player,
      audio: fakeAudio(() => clock.now),
      fetchSlot: (ch) => {
        asked.push(`slot ${ch}`);
        return opts.slowSlot ? new Promise((resolve) => held.push(() => resolve(slot(ch)))) : Promise.resolve(slot(ch));
      },
      fetchSlotWalkingBack: async (ch) => (asked.push(`back ${ch}`), slot(ch)),
      fetchLive: async (ch) => (asked.push(`live ${ch}`), live),
      fetchEvergreen: async () => null,
      canAutoplay: () => true,
      onChange: () => {},
      onPlaybackError: () => {},
    });
    return { engine, player, asked, clock, release: () => held.splice(0).forEach((go) => go()) };
  }

  it('a tap on "join" before the first start asks for nothing, and the start still gets its program', async () => {
    const r = recording();
    r.engine.join(); // the page is up before the boot has tuned in
    await r.engine.start('main');
    expect(r.asked).toEqual(['back main', 'live main']);
    expect(r.player.calls).toContain('load AAAAAAAAAAA 50.4');
    r.engine.stop();
  });

  it('switching channel while a minute file of the old one is on its way still fetches the new one', async () => {
    const r = recording({ slowSlot: true });
    await r.engine.start('a');
    r.clock.now += 60_000;
    r.engine.tick(); // channel a's next minute file, still on its way…
    const switching = r.engine.start('b');
    r.release(); // …arrives after the switch, and is not b's
    await switching;
    expect(r.asked.filter((a) => !a.startsWith('live'))).toEqual(['back a', 'slot a', 'back b']);
    expect(r.engine.snapshot.item?.id).toBe('b-s1');
    r.engine.stop();
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

  it('a pause from outside — a tap on the video, the lock screen — is the listener pausing: nothing starts again by itself', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join();
    s.engine.onPlayerState(YTState.PLAYING);
    s.setNow(60_000);
    s.engine.onPlayerState(YTState.PAUSED);
    expect(s.engine.snapshot.joined).toBe(false);
    expect(s.player.calls.at(-1)).toBe('stop');
    // A sheet closes over the stage, the host and the next song come: silence.
    const loads = () => s.player.calls.filter((c) => c.startsWith('load')).length;
    const loaded = loads();
    s.engine.reenter();
    s.setNow(205_000);
    s.engine.tick();
    s.setNow(230_000);
    s.engine.tick();
    expect(loads()).toBe(loaded);
    expect(s.audio.calls.filter((c) => c.startsWith('play'))).toEqual([]);
    s.engine.stop();
  });

  it('pauses that are not the listener’s keep the radio on: the next song loading, the stage going away, the page hidden', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join();
    s.engine.onPlayerState(YTState.PLAYING);
    // YouTube pauses the video it had as it takes the next one.
    s.setNow(220_100);
    s.engine.tick();
    expect(s.engine.snapshot.item?.id).toBe('s2');
    s.engine.onPlayerState(YTState.PAUSED);
    expect(s.engine.snapshot.joined).toBe(true);
    // A sheet over the stage: we pause the video ourselves.
    s.engine.onPlayerState(YTState.PLAYING);
    s.setNow(230_000);
    s.setStage(false);
    s.engine.stageHidden();
    expect(s.player.calls.at(-1)).toBe('pause');
    s.setNow(233_000);
    s.engine.onPlayerState(YTState.PAUSED);
    expect(s.engine.snapshot.joined).toBe(true);
    // The phone in the background: YouTube pauses itself, and plays on when the page is back.
    s.setStage(true);
    s.engine.reenter();
    s.engine.onPlayerState(YTState.PLAYING);
    s.setNow(240_000);
    s.setPage(false);
    s.engine.onPlayerState(YTState.PAUSED);
    expect(s.engine.snapshot.joined).toBe(true);
    s.engine.stop();
  });

  it('YouTube playing while the listener is out, or while our own audio is on air, is stopped — never sought', async () => {
    const s = setup();
    const stops = () => s.player.calls.filter((c) => c === 'stop').length;
    await s.engine.start('main');
    s.engine.join();
    s.engine.leave();
    // A report from before the pause, or the lock screen's play on the video:
    // the drift check sought it to live, and a seek starts a stopped video.
    s.player.st = YTState.PLAYING;
    s.player.t = 10;
    let before = stops();
    s.engine.onPlayerState(YTState.PLAYING);
    expect(stops()).toBe(before + 1);
    expect(s.player.calls.some((c) => c.startsWith('seek'))).toBe(false);
    s.engine.join();
    s.setNow(205_000);
    s.engine.tick();
    expect(s.engine.snapshot.mode).toBe('host');
    before = stops();
    s.engine.onPlayerState(YTState.PLAYING);
    expect(stops()).toBe(before + 1);
    s.engine.stop();
  });

  it('our audio paused from outside is the listener pausing; resumed from outside while they are out, it stops again', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join();
    s.setNow(205_000);
    s.engine.tick();
    expect(s.engine.snapshot.mode).toBe('host');
    // In the background a phone may pause it: back on screen, it plays on.
    s.setPage(false);
    s.audio.outside = 'paused';
    s.setNow(206_000);
    s.engine.tick();
    expect(s.engine.snapshot.joined).toBe(true);
    s.setPage(true);
    s.engine.resync();
    s.engine.tick();
    expect(s.engine.snapshot.joined).toBe(true);
    // On screen, the lock screen's or the headphones' pause.
    s.audio.outside = 'paused';
    s.setNow(207_000);
    s.engine.tick();
    expect(s.engine.snapshot.joined).toBe(false);
    // Their play on the element itself, past the app: stopped again.
    s.audio.outside = 'resumed';
    s.setNow(208_000);
    s.engine.tick();
    expect(s.audio.calls.at(-1)).toBe('stop');
    expect(s.engine.snapshot.joined).toBe(false);
    s.engine.stop();
  });

  it('every later tap wakes our audio engine: a phone may have put it to sleep', async () => {
    const s = setup();
    await s.engine.start('main');
    s.engine.join(); // the first tap unlocks it
    expect(s.audio.calls).not.toContain('wake');
    s.engine.leave();
    s.engine.join();
    s.engine.resume();
    expect(s.audio.calls.filter((c) => c === 'wake')).toHaveLength(2);
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

describe('the prayer hour', () => {
  it('knows which wall requests the host is praying for while the moment airs', async () => {
    const moment: TimelineItem = {
      id: 'h9', type: 'host', start: 40_000, dur: 20_000, p: 'prayer', kind: 'prayer',
      audio: { en: '/media/host/p.mp3' }, text: { en: 'Lord, we pray…' }, voices: [], prayers: ['pk3v9q2m7x4tb'],
    };
    const quiet: TimelineItem = { id: 'q9', type: 'silence', start: 60_000, dur: 60_000, p: 'prayer', label: { en: 'Silent prayer', de: 'Stilles Gebet' } };
    const s = setup({ slot: { ...slotFile, items: [moment, quiet] } });
    await s.engine.start('main');
    expect(s.engine.snapshot.praying).toEqual(['pk3v9q2m7x4tb']);
    s.setNow(61_000);
    s.engine.tick();
    expect(s.engine.snapshot.mode).toBe('silence');
    // The same empty list every time: a new [] per state would loop a zustand selector.
    expect(s.engine.snapshot.praying).toBe(initialState().praying);
    s.engine.stop();
  });
});

describe('a preaching', () => {
  it('plays as the video it is, and the tiles follow each minute file while it runs', async () => {
    const T = 1_789_999_980_000; // a minute boundary
    let now = T + 10_000;
    const sermon: TimelineItem = {
      id: 'p1', type: 'song', kind: 'preaching', start: T, dur: 40 * 60_000, p: 'sermon', yt: 'CCCCCCCCCCC',
      title: 'The Prodigal Son', artist: 'Pastor Ruth', thumb: null, request: { name: 'Samuel', place: 'Accra' }, fallback: null,
    };
    // Twenty minutes in, the program's intake closes: only the minute files say so.
    const minute = (t: number): SlotFile => ({
      ...slotFile, t: t - (t % 60_000), current: 'sermon', items: [sermon], submissions: { preaching: t >= T + 20 * 60_000 ? 'closed' : 'open' },
    });
    const player = fakePlayer();
    const engine = new RadioEngine({
      now: () => now,
      player,
      audio: fakeAudio(() => now),
      fetchSlot: async (_ch, t) => minute(t),
      fetchSlotWalkingBack: async (_ch, t) => minute(t),
      fetchLive: async () => live,
      fetchEvergreen: async () => null,
      canAutoplay: () => true,
      onChange: () => {},
      onPlaybackError: () => {},
    });
    await engine.start('main');
    engine.join();
    expect(engine.snapshot.mode).toBe('song');
    expect(player.calls).toContain('load CCCCCCCCCCC 10.4');
    expect(engine.snapshot.submissions).toEqual({ preaching: 'open' });

    now = T + 20 * 60_000 + 5000;
    engine.tick(); // asks for this minute's file…
    await vi.waitFor(() => {
      engine.tick(); // …and reads it, still in the same preaching
      expect(engine.snapshot.submissions).toEqual({ preaching: 'closed' });
    });
    expect(engine.snapshot.item?.id).toBe('p1');
    expect(player.calls.filter((c) => c.startsWith('load'))).toHaveLength(1);
    engine.stop();
  });
});
