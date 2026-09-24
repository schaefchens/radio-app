import type { ChannelInfo, ReactionKind } from '@arche/shared';
import { api } from './api';
import { serverNow, syncClock, accept } from './clock';
import { RadioEngine } from './engine';
import { HostAudio } from './hostAudio';
import { fetchChannels, fetchDay, fetchEvergreen, fetchLive, fetchSlot, fetchSlotWalkingBack } from './programFiles';
import { flushPulse, reactToItem, reactToVoice, reportPlaybackError, setPulseInterval, startPulse } from './pulse';
import { YouTubePlayer } from './youtube';
import { stationDate } from './format';
import { setKeepAwake } from './wakeLock';
import { realtime } from './realtime/client';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { stageAvailable, useStage } from '@/store/stage';

/**
 * Wires the engine to the browser and the stores. One of each for the app's
 * lifetime: a single YouTube player, a single pair of audio elements.
 */

// Whether the player may be seen right now (see setStageVisible).
let stageVisible = false;
const player = new YouTubePlayer({
  onState: (s) => engine.onPlayerState(s),
  onError: (code) => engine.onPlayerError(code),
});
const audio = new HostAudio();

export const engine = new RadioEngine({
  now: serverNow,
  player,
  audio,
  fetchSlot,
  fetchSlotWalkingBack,
  fetchLive,
  fetchEvergreen,
  canAutoplay: () => stageVisible,
  onChange: (s) => {
    useRadio.getState().setEngine(s);
  },
  onPlaybackError: (item, code) => reportPlaybackError(item, code),
});

// Dev-only handle for poking at sync from the console (stripped from builds).
if (import.meta.env.DEV) {
  (window as unknown as { __arche: unknown }).__arche = { engine, player, audio, serverNow };
}

// The engine, the player and the audio elements are page-wide singletons:
// hot-replacing this module would start a second radio next to the first.
// Accepting the update here keeps it from reaching the components, and the
// page reloads instead. (Vite 8's decline() is a no-op.)
if (import.meta.hot) import.meta.hot.accept(() => window.location.reload());

let booted = false;

export async function bootRadio(): Promise<void> {
  if (booted) return;
  booted = true;
  const settings = useSettings.getState();
  engine.setLang(settings.lang);
  audio.setVolume(settings.volume);
  setKeepAwake(settings.keepAwake);

  // Clock first: everything that follows is a function of server time.
  const t0 = Date.now();
  const sessionNow = await useSession.getState().loadSession();
  if (sessionNow !== null) accept({ t0, t1: Date.now(), server: sessionNow }, true);
  void syncClock(async () => (await api<{ now: number }>('/time')).now, 3);

  const channels = await fetchChannels();
  useSession.getState().setChannels(channels);
  const list = channels?.channels ?? [];
  const wanted = list.find((c) => c.id === settings.channel) ?? list.find((c) => c.main) ?? list[0];
  await tuneTo(wanted ?? null);

  useSettings.subscribe((s, prev) => {
    if (s.lang !== prev.lang) engine.setLang(s.lang);
    if (s.volume !== prev.volume) {
      audio.setVolume(s.volume);
      player.setVolume(s.volume);
    }
    if (s.keepAwake !== prev.keepAwake) setKeepAwake(s.keepAwake);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      void syncClock(async () => (await api<{ now: number }>('/time')).now, 2);
      engine.resync();
    } else {
      flushPulse();
    }
  });
  // Channels (names, the fallback loop) change rarely: every 5 minutes, or
  // every minute while the channel has no fallback loop yet — a new station
  // that just got its first songs should not stay silent for long.
  let channelsAt = Date.now();
  setInterval(async () => {
    const known = useSession.getState().channels?.channels.find((x) => x.id === engine.snapshot.channel);
    if (Date.now() - channelsAt < (known?.evergreen ? 5 * 60_000 : 60_000)) return;
    channelsAt = Date.now();
    const c = await fetchChannels();
    if (c) {
      useSession.getState().setChannels(c);
      const current = c.channels.find((x) => x.id === engine.snapshot.channel);
      engine.setEvergreenUrl(current?.evergreen ?? null);
    }
  }, 30_000);
  setInterval(() => void loadToday(), 5 * 60_000);
}

async function tuneTo(channel: ChannelInfo | null): Promise<void> {
  if (!channel) {
    await engine.start('main');
    return;
  }
  useSettings.getState().setChannel(channel.id);
  engine.setEvergreenUrl(channel.evergreen);
  startPulse(channel.id, useSession.getState().config?.pulse ?? 120);
  await engine.start(channel.id);
  void loadToday();
}

export async function switchChannel(id: string): Promise<void> {
  const c = useSession.getState().channels?.channels.find((x) => x.id === id) ?? null;
  await tuneTo(c);
}

export async function loadToday(): Promise<void> {
  const channelId = engine.snapshot.channel;
  const ch = useSession.getState().channels?.channels.find((c) => c.id === channelId);
  if (!ch) return;
  useRadio.getState().setToday(await fetchDay(ch.id, stationDate(serverNow(), ch.tz)));
}

/** Mount the single YouTube player into its host element (after consent). */
export async function mountPlayer(el: HTMLElement): Promise<void> {
  const fresh = !player.mounted;
  await player.mount(el);
  await player.ready;
  player.setVolume(useSettings.getState().volume);
  // The join tap usually lands before the IFrame API has loaded; now that the
  // player exists, enter the current item again so the song actually starts.
  if (fresh) engine.reenter();
}

export function joinRadio(): void {
  engine.join();
}

export function resumeRadio(): void {
  engine.resume();
}

/**
 * YouTube's rules: the player plays only where it can be seen, uncovered. When
 * the stage goes away (moderation page, a sheet over it, scrolled out) a
 * playing video is paused; when it comes back the engine re-enters the item at
 * the live position. Our own audio (host, jingles) is not affected.
 */
export function setStageVisible(visible: boolean): void {
  if (visible === stageVisible) return;
  stageVisible = visible;
  if (!visible) {
    if (engine.snapshot.mode === 'song' || engine.snapshot.mode === 'evergreen') player.pause();
  } else if (engine.snapshot.joined) {
    engine.reenter();
  }
}

useStage.subscribe((s) => setStageVisible(stageAvailable(s)));

/**
 * A reaction goes one way only: over the room's WebSocket while connected
 * (the node aggregates and reports every 20 s), otherwise with the next pulse.
 */
export function react(itemId: string, kind: ReactionKind): void {
  if (realtime.isConnected() && realtime.react(itemId, kind)) return;
  reactToItem(itemId, kind);
}

/** A voice from the connected room is a message: reacting likes it there. */
export function reactVoice(voiceId: string, kind: ReactionKind): void {
  if (realtime.isConnected() && realtime.hasMessage(voiceId)) realtime.like(voiceId);
  else reactToVoice(voiceId, kind);
  useRadio.getState().answer(voiceId);
}

export { setPulseInterval };

/** While the listener records, the radio must not bleed into the microphone. */
export function setRadioMuted(muted: boolean): void {
  const v = muted ? 0 : useSettings.getState().volume;
  audio.setVolume(v);
  player.setVolume(v);
}
