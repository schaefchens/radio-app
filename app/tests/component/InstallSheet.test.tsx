import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * `?install=1` (bible-assistant's lifecycle tests). The rule worth testing:
 * **prompt() is single-use, and its two failures mean opposite things** —
 * `NotAllowedError` keeps the event for the button, a success or
 * `InvalidStateError` spends it. Backwards, the sheet shows a button that
 * throws, or hides the only one that would have worked. Nothing is faked but
 * the event the browser hands the app. `resetModules` per case: the module
 * reads the URL when it is evaluated.
 */

type Choice = { outcome: 'accepted' | 'dismissed'; platform: string };

function installEvent(prompt: () => Promise<Choice>) {
  const e = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
    prompt: () => Promise<Choice>;
    userChoice: Promise<Choice>;
  };
  e.prompt = vi.fn(prompt);
  e.userChoice = Promise.resolve({ outcome: 'dismissed', platform: 'web' });
  return e;
}

const named = (name: string) => () => {
  const err = new Error(name);
  err.name = name;
  return err;
};
const notAllowed = named('NotAllowedError');
const invalidState = named('InvalidStateError');

const EARLY_SCRIPT = readFileSync(join(__dirname, '../../public/install-event.js'), 'utf8');
/** What index.html runs before the bundle; jsdom loads no document. */
const runEarlyScript = () => new Function(EARLY_SCRIPT)();

// Listeners live on the one jsdom window, not the module: without taking them
// off, a later dispatch would reach every earlier boot's prompt() too.
const realAdd = window.addEventListener.bind(window);
let added: Array<[string, EventListener]> = [];

const flush = () => new Promise((r) => setTimeout(r, 0));

async function boot(url: string) {
  window.history.replaceState(null, '', url);
  vi.resetModules();
  const mod = await import('@/lib/pwaInstall');
  mod.initPwaInstall();
  return mod;
}

beforeEach(() => {
  added = [];
  vi.spyOn(window, 'addEventListener').mockImplementation(((type: string, fn: EventListener, opts?: AddEventListenerOptions) => {
    added.push([type, fn]);
    realAdd(type, fn, opts);
  }) as typeof window.addEventListener);
});

afterEach(() => {
  for (const [type, fn] of added) window.removeEventListener(type, fn);
  delete window.__installPromptEvent;
  sessionStorage.clear();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('?install=1', () => {
  it('opens the sheet and takes the parameter out of the URL, keeping the rest', async () => {
    const mod = await boot('/schedule?ch=main&install=1');
    expect(mod.useInstallStore.getState().open).toBe(true);
    expect(window.location.pathname + window.location.search).toBe('/schedule?ch=main');
  });

  it('leaves every other visitor alone, Chrome’s own install banner included', async () => {
    runEarlyScript();
    const mod = await boot('/');
    const event = installEvent(() => Promise.reject(notAllowed()));
    window.dispatchEvent(event);
    expect(mod.useInstallStore.getState().open).toBe(false);
    expect(event.defaultPrevented).toBe(false);
    expect(window.__installPromptEvent).toBeUndefined();
  });

  it('adopts an event the early script caught before the bundle ran', async () => {
    // Nothing re-fires the event: without the parked copy, Chrome would get
    // written steps instead of its real dialog.
    window.history.replaceState(null, '', '/?install=1');
    runEarlyScript();
    const early = installEvent(() => Promise.reject(notAllowed()));
    window.dispatchEvent(early);
    expect(early.defaultPrevented).toBe(true);

    const mod = await boot('/?install=1');
    await vi.waitFor(() => expect(mod.useInstallStore.getState().promptable).toBe(true));
    expect(early.prompt).toHaveBeenCalledTimes(1);
    // A hand-off slot, not a second owner: the early listener re-parks every
    // event, so one left there may since have been spent.
    expect(window.__installPromptEvent).toBeNull();
  });

  it('the early script still listens after a reload took the parameter away', async () => {
    // The update banner's reload: the URL is clean by then, the latch is not.
    await boot('/?install=1');
    expect(window.location.search).toBe('');
    runEarlyScript();
    const event = installEvent(() => Promise.reject(notAllowed()));
    window.dispatchEvent(event);
    expect(window.__installPromptEvent).toBe(event);
  });

  it('does nothing when it already is the installed app', async () => {
    vi.stubGlobal('matchMedia', () => ({ matches: true }) as MediaQueryList);
    const mod = await boot('/?install=1');
    window.dispatchEvent(installEvent(() => Promise.reject(notAllowed())));
    expect(mod.useInstallStore.getState().open).toBe(false);
    // Forgotten, so a later visit in the browser is not greeted by a sheet.
    expect(sessionStorage.length).toBe(0);
  });
});

describe('the browser’s dialog', () => {
  it('tries once without a tap; the refusal keeps the event for the button', async () => {
    const mod = await boot('/?install=1');
    const event = installEvent(() => Promise.reject(notAllowed()));
    window.dispatchEvent(event);
    await vi.waitFor(() => expect(event.prompt).toHaveBeenCalledTimes(1));
    expect(mod.useInstallStore.getState().promptable).toBe(true);
    expect(mod.useInstallStore.getState().open).toBe(true);

    // Chrome re-fires the event when installability is re-evaluated: no
    // second attempt per re-evaluation.
    const second = installEvent(() => Promise.reject(notAllowed()));
    window.dispatchEvent(second);
    await flush();
    expect(second.prompt).not.toHaveBeenCalled();
    await expect(mod.promptInstall()).resolves.toBe('refused');
    expect(second.prompt).toHaveBeenCalledTimes(1);
  });

  it('accepted: confirms, and the spent event cannot be prompted again', async () => {
    const mod = await boot('/?install=1');
    const event = installEvent(() => Promise.resolve({ outcome: 'accepted', platform: 'web' }));
    window.dispatchEvent(event);
    await vi.waitFor(() => expect(mod.useInstallStore.getState().installed).toBe(true));
    await expect(mod.promptInstall()).resolves.toBe('unavailable');
    expect(event.prompt).toHaveBeenCalledTimes(1);
  });

  it('dismissed: closes the sheet instead of asking again', async () => {
    const mod = await boot('/?install=1');
    window.dispatchEvent(installEvent(() => Promise.resolve({ outcome: 'dismissed', platform: 'web' })));
    await vi.waitFor(() => expect(mod.useInstallStore.getState().open).toBe(false));
    expect(sessionStorage.length).toBe(0);
  });

  it('InvalidStateError retires the event instead of leaving a button that throws', async () => {
    const mod = await boot('/?install=1');
    window.dispatchEvent(installEvent(() => Promise.reject(invalidState())));
    await vi.waitFor(() => expect(mod.useInstallStore.getState().promptable).toBe(false));
    await expect(mod.promptInstall()).resolves.toBe('unavailable');
  });

  it('falls back to written steps, and a late event brings the button back', async () => {
    vi.useFakeTimers();
    const mod = await boot('/?install=1');
    vi.advanceTimersByTime(3000);
    expect(mod.useInstallStore.getState().timedOut).toBe(true);
    window.dispatchEvent(installEvent(() => Promise.reject(notAllowed())));
    expect(mod.useInstallStore.getState().promptable).toBe(true);
  });
});

describe('the sheet', () => {
  it('waits for the welcome dialog, then installs with one tap', async () => {
    const mod = await boot('/?install=1');
    // The same module instances as the booted lib (resetModules above).
    await import('@/i18n');
    const { useSettings } = await import('@/store/settings');
    const { InstallSheet } = await import('@/components/common/InstallSheet');
    useSettings.setState({ lang: 'en', welcomed: false });
    let accept: (c: Choice) => void = () => undefined;
    const event = installEvent(() => Promise.reject(notAllowed()));
    window.dispatchEvent(event);
    await vi.waitFor(() => expect(mod.useInstallStore.getState().promptable).toBe(true));
    // Let the zero-tap refusal settle: a tap inside it re-attaches to that
    // attempt (and no finger is that fast).
    await flush();

    render(<InstallSheet />);
    const sheet = screen.getByRole('dialog', { name: 'Install Arche Radio', hidden: true });
    // A first visitor: two modals at once, and this one would sit under the
    // welcome dialog's top layer.
    expect(sheet.inert).toBe(true);
    act(() => useSettings.getState().setWelcomed());
    expect(sheet.inert).toBe(false);

    event.prompt = vi.fn(() => new Promise<Choice>((res) => (accept = res)));
    fireEvent.click(screen.getByRole('button', { name: 'Install' }));
    expect(event.prompt).toHaveBeenCalledTimes(1);
    await act(async () => accept({ outcome: 'accepted', platform: 'web' }));
    expect(screen.getByRole('dialog', { name: 'Added to your home screen' })).toBeTruthy();
  });
});
