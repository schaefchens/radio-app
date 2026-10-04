import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import { PlayerCard } from '@/components/home/PlayerCard';
import { closeFullStage } from '@/lib/fullStage';
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

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
  fullscreenElement = null;
  requestFullscreen.mockClear();
  exitFullscreen.mockClear();
  Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, get: () => true });
  Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => fullscreenElement });
  Object.assign(document.documentElement, { requestFullscreen });
  Object.assign(document, { exitFullscreen });
});

afterEach(() => act(() => closeFullStage()));

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
    expect(document.activeElement?.textContent).toBe('Exit full screen');

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
