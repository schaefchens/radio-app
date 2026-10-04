import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import type { TimelineItem } from '@arche/shared';
import { PlayerCard } from '@/components/home/PlayerCard';
import { closeFullStage } from '@/lib/fullStage';
import { closeTop, resetBackStack } from '@/lib/backStack';
import { useRadio } from '@/store/radio';
import { useSettings } from '@/store/settings';
import { useStage } from '@/store/stage';

vi.mock('@/lib/radio', () => ({
  joinRadio: vi.fn(),
  leaveRadio: vi.fn(),
  resumeRadio: vi.fn(),
  react: vi.fn(),
  switchChannel: vi.fn(),
}));

// jsdom has no Fullscreen API: a browser that grants it, and leaves it on Esc.
let fullscreenElement: Element | null = null;
const change = (el: Element | null): void => {
  fullscreenElement = el;
  document.dispatchEvent(new Event('fullscreenchange'));
};
const requestFullscreen = vi.fn(async () => change(document.documentElement));
const exitFullscreen = vi.fn(async () => change(null));

// A phone that can be turned: only the big stage's query answers, and only sideways.
let sideways = false;
const turned = new Set<() => void>();
window.matchMedia = ((query: string) => ({
  get matches() {
    return sideways && query.includes('landscape');
  },
  media: query,
  addEventListener: (_: string, f: () => void) => turned.add(f),
  removeEventListener: (_: string, f: () => void) => turned.delete(f),
})) as unknown as typeof window.matchMedia;
const turn = (to: 'sideways' | 'upright'): void => {
  sideways = to === 'sideways';
  act(() => turned.forEach((f) => f()));
};
const joined = (on: boolean): void => act(() => useRadio.setState((s) => ({ engine: { ...s.engine, joined: on } })));
const song = (id: string, title: string): TimelineItem => ({
  id, type: 'song', kind: 'song', start: Date.now() - 10_000, dur: 200_000, p: 'live', yt: 'AAAAAAAAAAA', title, artist: 'X', thumb: null, request: null, fallback: null,
});
const onAir = (item: TimelineItem): void => act(() => useRadio.setState((s) => ({ engine: { ...s.engine, item, mode: 'song' } })));

beforeEach(() => {
  resetBackStack();
  sideways = false;
  // The button sits in the song row, which shows what is on air.
  onAir(song('s1', 'One'));
  useSettings.setState({ lang: 'en' });
  fullscreenElement = null;
  requestFullscreen.mockClear();
  exitFullscreen.mockClear();
  Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, get: () => true });
  Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => fullscreenElement });
  Object.assign(document.documentElement, { requestFullscreen });
  Object.assign(document, { exitFullscreen });
});

afterEach(() => {
  vi.useRealTimers();
  act(() => closeFullStage());
  joined(false);
  useStage.setState({ overlays: 0 });
});

function card() {
  return render(
    <MemoryRouter>
      <PlayerCard />
    </MemoryRouter>,
  );
}

const open = async (): Promise<void> => {
  await act(async () => void fireEvent.click(screen.getByRole('button', { name: 'Full screen' })));
};
const layer = (): HTMLElement | null => document.body.querySelector(':scope > .stage-full');
const stage = (slot: Element | null | undefined) => {
  const s = useStage.getState();
  return { full: s.full, ours: s.slot !== null && s.slot === slot, inView: s.inView };
};

// The stage alone on the screen: the player follows the slot, so the slot
// must move there and back without the store losing it.
describe('the big stage', () => {
  it('moves the stage into a layer over the page, fullscreen, and back to its place', async () => {
    const { container } = card();
    const inPage = container.querySelector('[data-stage-slot]');
    expect(inPage).not.toBeNull();
    expect(useStage.getState().slot === inPage).toBe(true);

    await open();
    const big = layer()?.querySelector('[data-stage-slot]');
    expect(big).toBeTruthy();
    // As plain values: matching an object that holds a DOM node walks all of jsdom.
    expect(stage(big)).toEqual({ full: true, ours: true, inView: true });
    // The page keeps the stage's place, without a second slot.
    expect(container.querySelectorAll('[data-stage-slot]')).toHaveLength(0);
    expect(requestFullscreen).toHaveBeenCalledTimes(1);
    // The way out is at hand, below the stage.
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Exit full screen');

    await act(async () => void fireEvent.click(screen.getByRole('button', { name: 'Exit full screen' })));
    expect(layer()).toBeNull();
    expect(exitFullscreen).toHaveBeenCalledTimes(1);
    const back = container.querySelector('[data-stage-slot]');
    expect(back).toBeTruthy();
    expect(stage(back)).toEqual({ full: false, ours: true, inView: true });
  });

  it("closes with the browser's own way out of fullscreen", async () => {
    card();
    await open();
    expect(layer()).toBeTruthy();
    act(() => change(null));
    expect(layer()).toBeNull();
    expect(useStage.getState().full).toBe(false);
  });

  it('fills the window where the browser has no fullscreen (iPhone)', async () => {
    Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, get: () => undefined });
    card();
    await open();
    expect(requestFullscreen).not.toHaveBeenCalled();
    expect(layer()?.querySelector('[data-stage-slot]')).toBeTruthy();
  });

  it('closes when the page under it goes, and leaves fullscreen', async () => {
    const { unmount } = card();
    await open();
    unmount();
    expect(useStage.getState().full).toBe(false);
    expect(layer()).toBeNull();
    expect(exitFullscreen).toHaveBeenCalledTimes(1);
  });
});

// Home on a phone: the turn itself opens and closes it, as in YouTube's app.
describe('the big stage and a phone turned sideways', () => {
  it('opens while the radio plays, without asking the browser (a turn is no gesture), and closes upright again', () => {
    joined(true);
    card();
    turn('sideways');
    expect(layer()?.querySelector('[data-stage-slot]')).toBeTruthy();
    expect(requestFullscreen).not.toHaveBeenCalled();
    turn('upright');
    expect(layer()).toBeNull();
  });

  it('opens when the listener joins while sideways, not before', () => {
    card();
    turn('sideways');
    expect(layer()).toBeNull();
    joined(true);
    expect(layer()).toBeTruthy();
  });

  it('stays closed once the listener closes it, until the next turn', async () => {
    joined(true);
    card();
    turn('sideways');
    await act(async () => void fireEvent.click(screen.getByRole('button', { name: 'Exit full screen' })));
    expect(layer()).toBeNull();
    turn('upright');
    turn('sideways');
    expect(layer()).toBeTruthy();
  });

  it('opened with the button, it stays when the phone is turned upright', async () => {
    joined(true);
    card();
    sideways = true;
    await open();
    turn('upright');
    expect(layer()).toBeTruthy();
  });

  it('waits while a sheet is open on the page', () => {
    joined(true);
    card();
    useStage.setState({ overlays: 1 });
    turn('sideways');
    expect(layer()).toBeNull();
  });

  it("closes with Android's back button", () => {
    joined(true);
    card();
    turn('sideways');
    act(() => void closeTop());
    expect(layer()).toBeNull();
  });
});

// The bar below the video: the song row made small, veiled when quiet.
describe("the big stage's bar", () => {
  const bar = (): HTMLElement | null => document.body.querySelector('.stage-full-bar');
  const veiled = (): boolean => bar()?.hasAttribute('data-idle') ?? false;

  it('shows what plays and its reactions, veils itself after a few quiet seconds, and comes back on a move', async () => {
    vi.useFakeTimers();
    card();
    await open();
    const strip = bar();
    expect(strip?.textContent).toContain('One');
    expect(strip?.querySelector('[data-reaction="heart"]')).toBeTruthy();
    expect(veiled()).toBe(false);
    act(() => void vi.advanceTimersByTime(3000));
    expect(veiled()).toBe(true);
    act(() => void fireEvent.pointerMove(document));
    expect(veiled()).toBe(false);
  });

  it('stays while the emoji strip is open', async () => {
    vi.useFakeTimers();
    card();
    await open();
    const strip = bar();
    if (!strip) throw new Error('no bar');
    act(() => void fireEvent.click(strip.querySelector('[aria-expanded]') as HTMLElement));
    act(() => void vi.advanceTimersByTime(5000));
    expect(veiled()).toBe(false);
  });

  it('a new song shows itself, then the veil comes back', async () => {
    vi.useFakeTimers();
    card();
    await open();
    act(() => void vi.advanceTimersByTime(3000));
    expect(veiled()).toBe(true);
    onAir(song('s2', 'Two'));
    expect(veiled()).toBe(false);
    expect(bar()?.textContent).toContain('Two');
    act(() => void vi.advanceTimersByTime(3000));
    expect(veiled()).toBe(true);
  });
});
