import { create } from 'zustand';
import { isStandalone } from './platform';

/**
 * `?install=1` — put the visitor in front of the browser's own install dialog
 * (bible-assistant's pattern). The link is for QR codes, flyers and "install
 * the app" buttons on other sites, so the visitor has tapped something
 * *elsewhere*: user activation does not survive a navigation, so the zero-tap
 * attempt is opportunistic and the one-tap button is the path that works.
 * `beforeinstallprompt` is Chromium's; everywhere else (iOS above all) the
 * sheet shows written steps.
 */

type InstallChoice = { outcome: 'accepted' | 'dismissed'; platform: string };

/** Chromium's install event; not in lib.dom. */
interface BeforeInstallPromptEvent extends Event {
  readonly platforms: readonly string[];
  /** The pre-2019 shape of the outcome; still populated, and still the fallback. */
  readonly userChoice: Promise<InstallChoice>;
  /** Opens the browser's own dialog. Gesture-gated, and **single-use**. */
  prompt: () => Promise<InstallChoice> | void;
}

declare global {
  interface WindowEventMap {
    beforeinstallprompt: BeforeInstallPromptEvent;
    appinstalled: Event;
  }
  interface Window {
    /** Where public/install-event.js parks an event that came before the bundle. */
    __installPromptEvent?: BeforeInstallPromptEvent | null;
  }
}

export const INSTALL_PARAM = 'install';

/**
 * The URL is cleaned the moment it is read (nobody wants `?install=1` in a
 * shared link), so it cannot also be the memory: a reload between arrival and
 * answer — the update banner's button — would lose the sheet for exactly the
 * new visitors the link was made for. public/install-event.js reads this key too.
 */
const LATCH_KEY = 'arche.install.intent';

/**
 * How long to wait for a `beforeinstallprompt` before showing written steps.
 * Chrome may take a moment (installability is re-evaluated once the service
 * worker registers, after our bundle on a first visit); an iPhone never gets
 * the event and must not look at nothing for long. A late event still turns
 * the steps into the button.
 */
const EVENT_DEADLINE_MS = 3000;

type InstallState = {
  /** The link asked for the sheet, and it has not been answered. */
  open: boolean;
  /** An event is in hand: the button opens the real dialog. */
  promptable: boolean;
  /** The wait is over: show the written steps instead. */
  timedOut: boolean;
  /** They said yes to the browser's dialog (or installed from its menu). */
  installed: boolean;
};

export const useInstallStore = create<InstallState>(() => ({
  open: false,
  promptable: false,
  timedOut: false,
  installed: false,
}));

// Module scope, not component state: prompt() is single-use, and StrictMode's
// double mount must not reset "already tried".
let parked: BeforeInstallPromptEvent | null = null;
let autoAttempted = false;
let inFlight: Promise<PromptOutcome> | null = null;
let deadline: number | undefined;
let started = false;

function readLatch(): boolean {
  try {
    return sessionStorage.getItem(LATCH_KEY) === '1';
  } catch {
    // Private mode or blocked storage: only surviving a reload is lost.
    return false;
  }
}

function writeLatch(): void {
  try {
    sessionStorage.setItem(LATCH_KEY, '1');
  } catch {
    /* see readLatch */
  }
}

function clearLatch(): void {
  try {
    sessionStorage.removeItem(LATCH_KEY);
  } catch {
    /* see readLatch */
  }
}

/**
 * Drop `install` from a query string and keep everything else: a blanket
 * `replaceState(null, '', pathname)` would eat the rest of a link (and React
 * Router's own `history.state`).
 */
export function urlWithoutInstallParam(search: string, hash = '', pathname = ''): string {
  const params = new URLSearchParams(search);
  params.delete(INSTALL_PARAM);
  const rest = params.toString();
  return `${pathname}${rest ? `?${rest}` : ''}${hash}`;
}

/**
 * Read at module-eval time, not in initPwaInstall(), so no reordering in
 * main.tsx can put it behind something that rewrites the URL.
 */
const intended: boolean = (() => {
  if (typeof window === 'undefined') return false;
  const asked = new URLSearchParams(window.location.search).get(INSTALL_PARAM) === '1';
  if (!asked) return readLatch();
  writeLatch();
  window.history.replaceState(
    window.history.state,
    '',
    urlWithoutInstallParam(window.location.search, window.location.hash, window.location.pathname),
  );
  return true;
})();

export type PromptOutcome = 'accepted' | 'dismissed' | 'refused' | 'unavailable';

/**
 * Open the browser's install dialog. **Nothing may be awaited between a click
 * and `event.prompt()`**: Chrome checks user activation synchronously, and an
 * `await` spends the gesture — which is why this returns a promise instead of
 * being `async`. A second call while one is open gets the same promise, so a
 * remount does not open a second dialog.
 */
export function promptInstall(): Promise<PromptOutcome> {
  if (inFlight) return inFlight;
  const event = parked;
  if (!event) return Promise.resolve('unavailable');

  let choice: Promise<InstallChoice>;
  try {
    // Current Chrome resolves prompt() to the choice; the original shape
    // returned void and carried it in userChoice.
    choice = event.prompt() ?? event.userChoice;
  } catch (err) {
    return Promise.resolve(afterRefusal(err));
  }

  const settled = choice.then(
    ({ outcome }): PromptOutcome => {
      // Spent: prompt() on it again throws InvalidStateError.
      parked = null;
      if (outcome === 'accepted') {
        clearLatch();
        useInstallStore.setState({ promptable: false, installed: true });
      } else {
        // They have just answered the real dialog; asking again over it is
        // the one thing not to do.
        closeInstallCard();
      }
      return outcome;
    },
    (err: unknown) => afterRefusal(err),
  );
  inFlight = settled.finally(() => {
    inFlight = null;
  });
  return inFlight;
}

/**
 * A prompt() that threw. `NotAllowedError` (no gesture) is the normal result
 * of the zero-tap attempt and leaves the event usable for the button;
 * `InvalidStateError` means it is spent, and keeping it would leave a button
 * that throws every time.
 */
function afterRefusal(err: unknown): PromptOutcome {
  if (err instanceof Error && err.name === 'InvalidStateError') {
    parked = null;
    useInstallStore.setState({ promptable: false });
    return 'unavailable';
  }
  return 'refused';
}

function adopt(event: BeforeInstallPromptEvent): void {
  parked = event;
  // The early script re-parks every event it sees, also the one adopted now;
  // left set, the slot would hold an event that may since have been spent.
  window.__installPromptEvent = null;
  useInstallStore.setState({ promptable: true });
  if (!useInstallStore.getState().open || autoAttempted) return;
  // Once per page load. Almost always refused (no activation after a
  // navigation), and the refusal is what puts the button in front of them.
  autoAttempted = true;
  void promptInstall();
}

/** Close the sheet and forget the intent, so a reload does not open it again. */
export function closeInstallCard(): void {
  window.clearTimeout(deadline);
  clearLatch();
  useInstallStore.setState({ open: false });
}

/**
 * Call once, before React mounts and **before** `initPwaUpdate()`: registering
 * the service worker is one of the things that makes Chrome fire
 * `beforeinstallprompt`, and a listener added after it has missed it.
 */
export function initPwaInstall(): void {
  if (started || typeof window === 'undefined') return;
  started = true;
  if (!intended) return;
  if (isStandalone()) {
    clearLatch();
    return;
  }

  // For the whole session: Chrome re-fires the event whenever installability
  // is re-evaluated, and a late one turns the written steps into the button.
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    adopt(e);
  });
  // An install made from the browser's own menu while the sheet is up.
  window.addEventListener('appinstalled', () => {
    parked = null;
    clearLatch();
    useInstallStore.setState({ promptable: false, installed: true });
  });

  useInstallStore.setState({ open: true });
  const early = window.__installPromptEvent;
  if (early) adopt(early);

  deadline = window.setTimeout(() => {
    useInstallStore.setState({ timedOut: true });
  }, EVENT_DEADLINE_MS);
}

export type InstallPlatform = 'ios' | 'firefox' | 'safari' | 'other';

/**
 * Which written steps to show when there is no install API. The order matters:
 * iPadOS reports a desktop Mac and only its touch screen tells it apart, and
 * every Chromium browser carries "Safari" in its UA, so desktop Safari is
 * known only by what is absent.
 */
export function installPlatform(
  ua: string = typeof navigator === 'undefined' ? '' : navigator.userAgent,
  touchPoints: number = typeof navigator === 'undefined' ? 0 : (navigator.maxTouchPoints ?? 0),
): InstallPlatform {
  if (/iPad|iPhone|iPod/.test(ua)) return 'ios';
  if (/Macintosh/.test(ua) && touchPoints > 1) return 'ios';
  if (/Firefox\/|FxiOS/.test(ua)) return 'firefox';
  if (/Safari\//.test(ua) && !/Chrome|Chromium|Edg\/|OPR\//.test(ua)) return 'safari';
  return 'other';
}
