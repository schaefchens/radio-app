import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import type { ClosedReason, DayFile, DayProgram, ProgramRef, SubmissionState, SubmissionType } from '@arche/shared';
import { SubmitTiles } from '@/components/home/SubmitTiles';
import { LockedSheet } from '@/components/submit/LockedSheet';
import { addDays, localTime } from '@/lib/format';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { useSheets } from '@/store/sheets';

/**
 * Listeners tapped locked tiles and thought them broken: a locked tile now
 * says why it takes nothing, when it will again, and what can be sent
 * instead. The day: music that takes songs, stories and prayers, and a
 * preaching from 09:00 to 11:00 Berlin time that takes preachings only.
 */

const days = vi.hoisted(() => ({ files: {} as Record<string, unknown> }));
vi.mock('@/lib/programFiles', async (original) => ({
  ...(await original<typeof import('@/lib/programFiles')>()),
  fetchDay: async (_channel: string, date: string) => days.files[date] ?? null,
}));

const TZ = 'Europe/Berlin';
const TODAY = '2026-10-12';

/** UTC ms of a Berlin wall-clock time (October: summer time, UTC+2). */
const berlin = (date: string, hm: string): number => Date.parse(`${date}T${hm}:00+02:00`);

function dayProgram(id: string, title: string, allowed: SubmissionType[]): DayProgram {
  return { id, title: { en: title, de: title }, subtitle: { en: '', de: '' }, description: { en: '', de: '' }, color: '#000', stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } }, allowed, format: 'music', voicedBy: [], hosts: [] };
}

const PROGRAMS = {
  music: dayProgram('music', 'Morning Music', ['song', 'story', 'testimony', 'greeting', 'prayer']),
  sermon: dayProgram('sermon', 'Sunday Sermon', ['preaching']),
};

function day(date: string, blocks: [string, string, string][]): DayFile {
  const at = (hm: string) => (hm === '24:00' ? berlin(addDays(date, 1), '00:00') : berlin(date, hm));
  return { v: 1, channel: 'main', date, tz: TZ, gen: 0, blocks: blocks.map(([from, to, p]) => ({ start: at(from), end: at(to), p })), programs: PROGRAMS, played: [] };
}

const PLAN: [string, string, string][] = [
  ['00:00', '09:00', 'music'],
  ['09:00', '11:00', 'sermon'],
  ['11:00', '24:00', 'music'],
];

function onAir(
  p: DayProgram,
  submissions: Partial<Record<SubmissionType, SubmissionState>>,
  why: Partial<Record<SubmissionType, ClosedReason>> = {},
): void {
  const program: ProgramRef = { ...p };
  act(() => useRadio.setState((s) => ({ engine: { ...s.engine, channel: 'main', program, submissions, why }, today: days.files[TODAY] as DayFile })));
}

function Home() {
  const open = useSheets((s) => s.open);
  return (
    <MemoryRouter>
      <SubmitTiles />
      <LockedSheet open={open === 'locked'} onClose={() => useSheets.getState().close()} />
    </MemoryRouter>
  );
}

const tile = (name: RegExp) => screen.getAllByRole('button', { name })[0] as HTMLButtonElement;
const info = () => screen.getByRole('dialog', { name: 'Not possible right now' });

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  useSettings.setState({ lang: 'en' });
  useSession.setState({ channels: { v: 1, minClient: 1, channels: [{ id: 'main', name: { en: 'Arche', de: 'Arche' }, main: true, tz: TZ, color: '#000', host: null, evergreen: null }] } as never });
  days.files = Object.fromEntries([-1, 0, 1, 2, 3, 4, 5, 6].map((d) => [addDays(TODAY, d), day(addDays(TODAY, d), PLAN)]));
  act(() => useSheets.getState().close());
});

afterEach(() => {
  vi.useRealTimers();
  useRadio.setState((s) => ({ engine: { ...s.engine, program: null, submissions: {}, why: {} }, today: null }));
});

describe('a locked tile', () => {
  it('is marked locked, says on the tile when it opens again, and opens no form', () => {
    vi.setSystemTime(berlin(TODAY, '09:30'));
    onAir(PROGRAMS.sermon, { preaching: 'open' });
    render(<Home />);
    const song = tile(/^Request a song/);
    expect(song.hasAttribute('data-locked')).toBe(true);
    expect(song.getAttribute('aria-label')).toBe('Request a song · Not part of this program');
    expect(within(song).getByText(`Again from ${localTime(berlin(TODAY, '11:00'), 'en')}`)).toBeTruthy();
    fireEvent.click(song);
    expect(useSheets.getState()).toMatchObject({ open: 'locked', locked: 'song' });
  });

  it('tapped, says why, when again and in which program, and offers what is possible now', async () => {
    vi.setSystemTime(berlin(TODAY, '09:30'));
    onAir(PROGRAMS.sermon, { preaching: 'open' });
    render(<Home />);
    fireEvent.click(tile(/^Request a song/));
    // Its own name, not the form's: the tile it explains comes first in it.
    expect(within(info()).getByText('Request a song')).toBeTruthy();
    expect(within(info()).getByText('“Sunday Sermon” doesn’t take song requests.')).toBeTruthy();
    await waitFor(() => expect(within(info()).getByText(`Possible again today from ${localTime(berlin(TODAY, '11:00'), 'en')}, in “Morning Music”.`)).toBeTruthy());
    fireEvent.click(within(info()).getByRole('button', { name: 'Suggest a video' }));
    expect(useSheets.getState().open).toBe('video');
  });

  it('closed late: the program ends soon, and the next one that takes it is named — tomorrow', async () => {
    vi.setSystemTime(berlin(TODAY, '23:50'));
    days.files[TODAY] = day(TODAY, [...PLAN.slice(0, 2), ['11:00', '24:00', 'music']]);
    days.files[addDays(TODAY, 1)] = day(addDays(TODAY, 1), [['00:00', '06:00', 'sermon'], ['06:00', '24:00', 'music']]);
    onAir(PROGRAMS.music, { song: 'closed', prayer: 'closed' }, { song: 'late', prayer: 'late' });
    render(<Home />);
    fireEvent.click(tile(/^Request a song/));
    expect(within(info()).getByText('“Morning Music” ends soon: anything sent now could no longer be aired.')).toBeTruthy();
    await waitFor(() =>
      expect(within(info()).getByText(`Possible again tomorrow from ${localTime(berlin(addDays(TODAY, 1), '06:00'), 'en')}, in “Morning Music”.`)).toBeTruthy(),
    );
    expect(within(info()).queryByText('Possible right now')).toBeNull();
  });

  it('full: it may open again in this program; otherwise the next program — and no time on the tile', async () => {
    vi.setSystemTime(berlin(TODAY, '08:00'));
    onAir(PROGRAMS.music, { song: 'closed', story: 'open', testimony: 'open', greeting: 'open', prayer: 'open' }, { song: 'full' });
    render(<Home />);
    const song = tile(/^Request a song/);
    expect(song.getAttribute('aria-label')).toBe('Request a song · Full right now');
    expect(within(song).getByText('Full right now')).toBeTruthy();
    fireEvent.click(song);
    expect(within(info()).getByText(/So much is already waiting for “Morning Music”/)).toBeTruthy();
    await waitFor(() => expect(within(info()).getByText(`Otherwise again today from ${localTime(berlin(TODAY, '11:00'), 'en')}, in “Morning Music”.`)).toBeTruthy());
  });

  it('with no program in the week that takes it, says so', async () => {
    vi.setSystemTime(berlin(TODAY, '12:00'));
    for (const date of Object.keys(days.files)) days.files[date] = day(date, [['00:00', '24:00', 'music']]);
    onAir(PROGRAMS.music, { song: 'open', prayer: 'open' });
    render(<Home />);
    fireEvent.click(tile(/^Suggest a video/));
    const sheet = info();
    await waitFor(() => expect(within(sheet).getByText('No program in the coming days takes video suggestions.')).toBeTruthy());
  });
});
