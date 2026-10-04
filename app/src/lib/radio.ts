import type { ChannelInfo, ReactionKind } from '@arche/shared';
import { api } from './api';
import { serverNow, syncClock, accept } from './clock';
import { RadioEngine } from './engine';
import { HostAudio } from './hostAudio';
import { fetchChannels, fetchDay, fetchEvergreen, fetchLive, fetchSlot, fetchSlotWalkingBack } from './programFiles';
import { flushPulse, reactToItem, reactToVoice, reportPlaybackError, setPulseInterval, startPulse } from './pulse';
import { YouTubePlayer } from './youtube';
import { stationDate } from './format';
import { onAppBackground, onAppForeground } from './native';
import { reconcileReminders, startReminders } from './reminders';
import { navigateTo } from './appNav';
import { tapHaptic, warmHaptics } from './haptics';
import { setKeepAwake, setListening } from './wakeLock';
import { realtime } from './realtime/client';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { stageAvailable, useStage } from '@/store/stage';

/**
 * Wires the engine to the browser and the stores. One of each for the app's
 * lifetime: a single YouTube player, a single pair of audio elements.
 */

// Whether the player may be seen right now, what the stage store last said,
// and whether a decision is waiting for the current commit (see setStageVisible).
let stageVisible = false;
let stageWanted = false;
let stageSettling = false;
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
  pageVisible: () => document.visibilityState !== 'hidden',
  onChange: (s) => {
    useRadio.getState().setEngine(s);
    showPlaybackState(s.joined);
    setListening(s.joined);
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
  bindMediaKeys();
  warmHaptics();
  // Before the channel is picked: a reminder's tap that started the app
  // chooses the channel (store apps only).
  startReminders((channel) => {
    openChannel(channel);
    navigateTo('/');
  });
  // The store apps: nothing may play in the background (YouTube's terms;
  // the apps declare no background audio), so going there is the lock
  // screen's Pause. On return the stage says why it is quiet. Before the
  // first await: a listener may join while the program still loads.
  onAppBackground(() => {
    if (!engine.snapshot.joined) return;
    engine.leave();
    useRadio.getState().setLeftInBackground(true);
  });

  // Clock first: everything that follows is a function of server time.
  const t0 = Date.now();
  const sessionNow = await useSession.getState().loadSession();
  if (sessionNow !== null) accept({ t0, t1: Date.now(), server: sessionNow }, true);
  void syncClock(async () => (await api<{ now: number }>('/time')).now, 3);

  const channels = await fetchChannels();
  useSession.getState().setChannels(channels);
  const list = channels?.channels ?? [];
  // Read now, not at the start: a tapped reminder may have chosen it meanwhile.
  const chosen = useSettings.getState().channel;
  const wanted = list.find((c) => c.id === chosen) ?? list.find((c) => c.main) ?? list[0];
  await tuneTo(wanted ?? null);
  picked = true;
  // A reminder tapped while it tuned in chose another channel.
  const tapped = useSettings.getState().channel;
  if (tapped) openChannel(tapped);
  reconcileReminders('boot');

  useSettings.subscribe((s, prev) => {
    if (s.lang !== prev.lang) {
      engine.setLang(s.lang);
      // Their text is in the listener's language.
      reconcileReminders('lang');
    }
    if (s.volume !== prev.volume) {
      audio.setVolume(s.volume);
      player.setVolume(s.volume);
    }
    if (s.keepAwake !== prev.keepAwake) setKeepAwake(s.keepAwake);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') backInFront();
    else flushPulse();
  });
  // iOS reports a return through the App plugin more reliably than through
  // visibilitychange.
  onAppForeground(backInFront);
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
  setInterval(() => {
    void loadToday();
    // The day files move on (a new day, a changed plan): reminders follow.
    reconcileReminders('tick');
  }, 5 * 60_000);
}

let backAt = 0;
let picked = false;

/** Visible again: the clock and the engine catch up (once, whichever signal comes first). */
function backInFront(): void {
  if (Date.now() - backAt < 1000) return;
  backAt = Date.now();
  void syncClock(async () => (await api<{ now: number }>('/time')).now, 2);
  engine.resync();
  reconcileReminders('resume');
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

/**
 * A tapped reminder's channel: before boot has picked one, boot takes it;
 * afterwards the radio switches (an unknown channel changes nothing).
 */
export function openChannel(id: string): void {
  if (!picked) {
    useSettings.getState().setChannel(id);
    return;
  }
  const known = useSession.getState().channels?.channels.some((c) => c.id === id);
  if (known && id !== engine.snapshot.channel) void switchChannel(id);
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
  useRadio.getState().setLeftInBackground(false);
  engine.join();
}

export function resumeRadio(): void {
  engine.resume();
}

/** Stop: nothing plays until the listener taps play again, as after a reload. */
export function leaveRadio(): void {
  engine.leave();
}

/**
 * The lock screen, headphones and media keys do what the song bar does:
 * pause leaves, play joins live. Without handlers they paused and resumed
 * the players themselves, behind the engine's back.
 */
function bindMediaKeys(): void {
  const session = navigator.mediaSession as MediaSession | undefined;
  if (!session) return;
  const on = (action: MediaSessionAction, handler: () => void): void => {
    try {
      session.setActionHandler(action, handler);
    } catch {
      /* an action this browser does not know */
    }
  };
  on('play', joinRadio);
  on('pause', leaveRadio);
  on('stop', leaveRadio);
}

let shownJoined: boolean | null = null;

/** What the lock screen's button shows: play while the listener is out, pause while in. */
function showPlaybackState(joined: boolean): void {
  const session = navigator.mediaSession as MediaSession | undefined;
  if (!session || joined === shownJoined) return;
  shownJoined = joined;
  session.playbackState = joined ? 'playing' : 'paused';
}

/**
 * YouTube's rules: the player plays only where it can be seen, uncovered. When
 * the stage goes away (moderation page, a sheet over it, scrolled out) a
 * playing video is paused; when it comes back the engine re-enters the item at
 * the live position. Our own audio (host, jingles) is not affected.
 */
export function setStageVisible(visible: boolean): void {
  stageWanted = visible;
  if (stageSettling) return;
  stageSettling = true;
  // A page change hands the stage from one slot to the next within one
  // commit (old one gone, new one there): decide once that has settled, so
  // the song plays on instead of pausing and seeking back in.
  queueMicrotask(() => {
    stageSettling = false;
    if (stageWanted === stageVisible) return;
    stageVisible = stageWanted;
    if (!stageVisible) engine.stageHidden();
    else if (engine.snapshot.joined) engine.reenter();
  });
}

useStage.subscribe((s) => setStageVisible(stageAvailable(s)));

/**
 * A reaction goes one way only: over the room's WebSocket while connected
 * (the node aggregates and reports every 20 s), otherwise with the next pulse.
 */
export function react(itemId: string, kind: ReactionKind): void {
  tapHaptic();
  if (realtime.isConnected() && realtime.react(itemId, kind)) return;
  reactToItem(itemId, kind);
}

/**
 * A reaction on a community voice or a prayer on the wall. A voice from the
 * connected room is a message: reacting likes it there.
 */
export function reactVoice(voiceId: string, kind: ReactionKind): void {
  tapHaptic();
  if (realtime.isConnected() && realtime.hasMessage(voiceId)) realtime.like(voiceId);
  else reactToVoice(voiceId, kind);
}

export { setPulseInterval };

/** While the listener records, the radio must not bleed into the microphone. */
export function setRadioMuted(muted: boolean): void {
  const v = muted ? 0 : useSettings.getState().volume;
  audio.setVolume(v);
  player.setVolume(v);
}
