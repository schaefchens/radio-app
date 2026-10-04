import { useStage } from '@/store/stage';
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
 * iPhones have none, and there the big stage fills the window.
 */

let listening = false;
let locked = false;
let returnFocus: HTMLElement | null = null;

export function openFullStage(): void {
  if (useStage.getState().full) return;
  returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  useStage.getState().setFull(true);
  setStageFull(true);
  listen();
  const root = document.documentElement;
  // Asked for in the tap itself: browsers allow fullscreen only from a user's gesture.
  if (!document.fullscreenEnabled || typeof root.requestFullscreen !== 'function') return;
  root.requestFullscreen({ navigationUI: 'hide' }).then(turnSideways, () => {
    /* refused (an embedding frame, a setting): the big stage still fills the window */
  });
}

export function closeFullStage(): void {
  if (!useStage.getState().full) return;
  useStage.getState().setFull(false);
  setStageFull(false);
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
