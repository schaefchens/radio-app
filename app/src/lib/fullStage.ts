import { useRadio } from '@/store/radio';
import { useStage } from '@/store/stage';
import { pushBack } from './backStack';
import { hasPlugin, isNative, plugin } from './native';
import { setStageFull } from './wakeLock';

/**
 * The big stage: the stage alone on the screen, as large as it fits — a
 * television, a projector in a church, a phone turned sideways. Our own
 * layer over the page (StageRegion), not YouTube's fullscreen: that one shows
 * the video only — none of the host, the prayer hour or a group's notice —
 * and needs YouTube's controls, which would let a listener seek out of the
 * live program. The player keeps following the stage slot, so the video is
 * never moved in the DOM and does not reload.
 *
 * The browser's fullscreen only takes its bars away, where there is one:
 * iPhones have none, and there the big stage fills the window. The store
 * apps hide the phone's bars instead: Capacitor's WebView ends a page's
 * fullscreen the moment it begins, which would close the big stage at once.
 */

let listening = false;
let locked = false;
let auto = false;
let returnFocus: HTMLElement | null = null;
let releaseBack: (() => void) | null = null;

/** The button: in the tap itself, so the browser grants its fullscreen. */
export function openFullStage(): void {
  open(false);
}

function open(byTurn: boolean): void {
  if (useStage.getState().full) return;
  auto = byTurn;
  returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  useStage.getState().setFull(true);
  setStageFull(true);
  // Android's back button closes it, after any sheet opened over it.
  releaseBack = pushBack(closeFullStage);
  if (isNative()) {
    systemBars('hide');
    return;
  }
  listen();
  const root = document.documentElement;
  // Browsers allow fullscreen only from a user's gesture: a turned phone is none.
  if (byTurn || !document.fullscreenEnabled || typeof root.requestFullscreen !== 'function') return;
  root.requestFullscreen({ navigationUI: 'hide' }).then(turnSideways, () => {
    /* refused (an embedding frame, a setting): the big stage still fills the window */
  });
}

export function closeFullStage(): void {
  if (!useStage.getState().full) return;
  auto = false;
  useStage.getState().setFull(false);
  setStageFull(false);
  releaseBack?.();
  releaseBack = null;
  if (isNative()) systemBars('show');
  if (locked) {
    locked = false;
    try {
      screen.orientation.unlock();
    } catch {
      /* gone with the fullscreen already */
    }
  }
  if (document.fullscreenElement) void document.exitFullscreen().catch(() => {});
  returnFocus?.focus({ preventScroll: true });
  returnFocus = null;
}

/** A phone turned sideways: short and wide, with a finger for a pointer — not a low desktop window. */
const SIDEWAYS = '(orientation: landscape) and (max-height: 500px) and (pointer: coarse)';

/**
 * Home on a phone: turned sideways while the radio plays, the big stage opens
 * by itself, as in YouTube's app; upright again, it closes — when it opened by
 * itself. Closed by the listener, it stays closed until the next turn, and a
 * sheet open on the page keeps it closed. Returns the function that stops.
 */
export function followTurns(): () => void {
  if (typeof window.matchMedia !== 'function') return () => {};
  const sideways = window.matchMedia(SIDEWAYS);
  const sync = (): void => {
    const { full, overlays } = useStage.getState();
    if (!sideways.matches) {
      if (full && auto) closeFullStage();
    } else if (!full && overlays === 0 && useRadio.getState().engine.joined) open(true);
  };
  sideways.addEventListener('change', sync);
  // Joined while sideways: the play button on the page is the turn's last step.
  const off = useRadio.subscribe((s, prev) => {
    if (s.engine.joined && !prev.engine.joined) sync();
  });
  return () => {
    sideways.removeEventListener('change', sync);
    off();
  };
}

/**
 * A 16:9 stage on a phone held upright is no bigger than before: Android
 * turns it sideways while the page is fullscreen. Desktops and iPads refuse,
 * and nothing changes.
 */
async function turnSideways(): Promise<void> {
  if (!useStage.getState().full || typeof screen.orientation?.lock !== 'function') return;
  try {
    await screen.orientation.lock('landscape');
    locked = true;
  } catch {
    /* not a phone, or a browser that does not lock */
  }
}

/** The phone's status and navigation bars in the store apps (Capacitor 8 core). */
function systemBars(action: 'hide' | 'show'): void {
  if (!hasPlugin('SystemBars', action)) return;
  void plugin('SystemBars')
    .then((m) => (action === 'hide' ? m?.SystemBars.hide() : m?.SystemBars.show()))
    .catch(() => {});
}

function listen(): void {
  if (listening) return;
  listening = true;
  document.addEventListener('fullscreenchange', () => {
    const full = useStage.getState().full;
    // The browser's own way out (Esc, Android's back) takes the big stage with it.
    if (!document.fullscreenElement && full) closeFullStage();
    // Closed while the browser was still going fullscreen: the page must not stay so.
    else if (document.fullscreenElement === document.documentElement && !full) void document.exitFullscreen().catch(() => {});
  });
}
