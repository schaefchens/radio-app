import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import type { ProgramRef, SubmissionState, SubmissionType } from '@arche/shared';
import { VideoRequestSheet } from '@/components/submit/VideoRequestSheet';
import { SubmitTiles } from '@/components/home/SubmitTiles';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { useSheets } from '@/store/sheets';
import { RULES_VERSION } from '@/content/rules';

/**
 * The fourth tile's sheet: one form for every kind of video a program takes —
 * a preaching, a testimony, a mission video, a film — with a picker that
 * offers only what the minute file lists as open, and never switches a kind
 * the listener is already using (a sermon link must not go through the
 * mission check by surprise).
 */

const program = (format: ProgramRef['format']): ProgramRef => ({
  id: format,
  title: { en: 'On air', de: 'Auf Sendung' },
  subtitle: { en: '', de: '' },
  color: '#2f7bff',
  stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } },
  allowed: [],
  format,
  voicedBy: ['openai'],
});

/** What the minute file says: the program on air and what it takes now. */
function onAir(format: ProgramRef['format'], submissions: Partial<Record<SubmissionType, SubmissionState>>): void {
  act(() => useRadio.setState((s) => ({ engine: { ...s.engine, channel: 'main', program: program(format), submissions } })));
}

const MISSION_HINT = /a report from the mission field, street preaching or a mission documentary/;
const TESTIMONY_HINT = /someone tells what God has done in their life/;

function sheet(open = true) {
  return (
    <MemoryRouter>
      <VideoRequestSheet kind="video" open={open} onClose={() => useSheets.getState().close()} />
    </MemoryRouter>
  );
}

const dialog = () => screen.getByRole('dialog', { hidden: true });
const kindButton = (name: string) => within(dialog()).getByRole('button', { name }) as HTMLButtonElement;
const pressed = () => ['Preaching', 'Testimony', 'Mission', 'Film'].filter((k) => kindButton(k).getAttribute('aria-pressed') === 'true');

beforeEach(() => {
  useSettings.setState({ lang: 'en', rules: RULES_VERSION });
});

afterEach(() => {
  vi.unstubAllGlobals();
  useRadio.setState((s) => ({ engine: { ...s.engine, program: null, submissions: {} } }));
});

describe('suggesting a video', () => {
  it('offers the kinds the minute file lists as open, the program\'s own first', () => {
    onAir('mission', { mission: 'open', testimony_video: 'closing' });
    render(sheet());
    expect(screen.getByRole('dialog', { hidden: true, name: 'Suggest a video' })).toBeTruthy();
    expect(['Preaching', 'Testimony', 'Mission', 'Film'].map((k) => kindButton(k).disabled)).toEqual([true, false, false, true]);
    // Not the first open kind in the list (Testimony): the program's own.
    expect(pressed()).toEqual(['Mission']);
    expect(within(dialog()).getByText(MISSION_HINT)).toBeTruthy();
  });

  it('sends the kind the listener picked, with its own hint', async () => {
    const fetch = vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    onAir('mission', { mission: 'open', testimony_video: 'open' });
    render(sheet());
    fireEvent.click(kindButton('Testimony'));
    expect(pressed()).toEqual(['Testimony']);
    expect(within(dialog()).getByText(TESTIMONY_HINT)).toBeTruthy();
    fireEvent.change(within(dialog()).getByLabelText('YouTube link'), { target: { value: 'https://youtu.be/AbCdEfGhIjK' } });
    fireEvent.change(within(dialog()).getByLabelText('Your first name'), { target: { value: 'Grace' } });
    fireEvent.click(within(dialog()).getByRole('button', { name: 'Send' }));
    await within(dialog()).findByText(/Thank you!/);
    const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/api/submissions/video');
    expect(JSON.parse(String(init.body))).toMatchObject({ type: 'testimony_video', url: 'https://youtu.be/AbCdEfGhIjK', name: 'Grace' });
  });

  it('an untouched form follows the program; a kind in use stays, closed, and Send waits', () => {
    onAir('preaching', { preaching: 'open' });
    render(sheet());
    expect(pressed()).toEqual(['Preaching']);
    // Nothing typed yet: the next program's own kind.
    onAir('mission', { mission: 'open' });
    expect(pressed()).toEqual(['Mission']);

    // A link pasted: Mission is the listener's now — never switched under them.
    fireEvent.change(within(dialog()).getByLabelText('YouTube link'), { target: { value: 'https://youtu.be/AbCdEfGhIjK' } });
    fireEvent.change(within(dialog()).getByLabelText('Your first name'), { target: { value: 'Grace' } });
    const send = within(dialog()).getByRole('button', { name: 'Send' }) as HTMLButtonElement;
    expect(send.disabled).toBe(false);
    onAir('music', { song: 'open' });
    expect(pressed()).toEqual(['Mission']);
    expect(kindButton('Mission').disabled).toBe(true);
    expect(within(dialog()).getByText('Not part of this program')).toBeTruthy();
    expect(send.disabled).toBe(true);
    onAir('mission', { mission: 'closed' });
    expect(within(dialog()).getByText('Closed for this program')).toBeTruthy();
    expect(send.disabled).toBe(true);
    onAir('mission', { mission: 'open', preaching: 'open' });
    expect(pressed()).toEqual(['Mission']);
    expect(send.disabled).toBe(false);
  });

  it('closed, the sheet forgets a kind that is no longer taken', () => {
    onAir('mission', { mission: 'open' });
    const { rerender } = render(sheet());
    fireEvent.click(kindButton('Mission'));
    onAir('preaching', { preaching: 'open' });
    expect(pressed()).toEqual(['Mission']);
    // Escape closes the sheet like its close button.
    fireEvent.keyDown(window, { key: 'Escape' });
    rerender(sheet(false));
    rerender(sheet(true));
    expect(pressed()).toEqual(['Preaching']);
  });
});

describe('the fourth tile', () => {
  const tile = (name: string) => screen.getAllByRole('button', { name })[0] as HTMLButtonElement;

  it('is not part of a program without videos, and closed when its videos are', () => {
    onAir('music', { song: 'open' });
    const { unmount } = render(<SubmitTiles />);
    expect(tile('Suggest a video · Not part of this program').getAttribute('aria-disabled')).toBe('true');
    unmount();
    onAir('preaching', { preaching: 'closed' });
    render(<SubmitTiles />);
    expect(tile('Suggest a video · Closed for this program').getAttribute('aria-disabled')).toBe('true');
  });

  it('opens the sheet while any kind of video is taken, and says when time runs out', () => {
    act(() => useSheets.getState().close());
    onAir('mission', { mission: 'closing', film: 'closed' });
    render(<SubmitTiles />);
    const button = tile('Suggest a video');
    expect(button.getAttribute('aria-disabled')).toBe('false');
    expect(within(button).getByText('Last chance')).toBeTruthy();
    expect(within(button).getByText('Preaching, testimony, mission or film')).toBeTruthy();
    fireEvent.click(button);
    expect(useSheets.getState().open).toBe('video');
  });
});
