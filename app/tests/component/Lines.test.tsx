import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import { LinesPanel } from '@/components/mod/LinesPanel';
import { ProgramsPanel } from '@/components/mod/ProgramsPanel';
import { DEFAULT_LINE_KINDS, type LinesOverview, type ModHost, type ModLine, type ModProgram, type Overview, type ProgramSettings } from '@/components/mod/modApi';
import { useOverview } from '@/components/mod/overview';
import { useSettings } from '@/store/settings';

/**
 * /mod › Lines: a host's recorded lines — how full each kind is, new ones
 * written on request, listened to, edited (recording again costs characters,
 * so it asks once more), paused and removed one by one or together — and the
 * program editor's choice of where the host's words come from.
 */

const hope: ModHost = {
  id: 1, name: 'Hope', avatar: null, color: '#2f7bff', about_en: '', about_de: '', style: '', provider: 'openai', model: 'gpt-4o-mini-tts', voices: { en: 'coral', de: 'coral' },
  instructions: '', settings: { speed: 1 }, max_chars_day: 0, active: true, key_set: false, key_unreadable: false, station_key: true, resting_until: 0, last_error: '',
  speaks: true, today: { chars: 0, calls: 0 }, used_in: [], lines_active: 2,
};

const overview = (over: Partial<LinesOverview> = {}): LinesOverview => ({
  host: { id: 1, name: 'Hope', color: '#2f7bff', avatar: null, provider: 'openai', model: 'gpt-4o-mini-tts', voices: { en: 'coral', de: 'coral' } },
  kinds: ['intro', 'outro', 'encourage', 'present', 'prayertime', 'prayer', 'break'],
  program_kinds: ['intro', 'outro', 'present', 'prayertime'],
  programs: [
    { id: 5, channel_id: 1, title: { en: 'Prayer hour', de: 'Gebetsstunde' }, format: 'prayer', mode: 'library', kinds: ['encourage'] },
    { id: 6, channel_id: 1, title: { en: 'Live', de: 'Live' }, format: 'music', mode: 'fresh', kinds: [] },
  ],
  pools: [{ kind: 'encourage', program_id: null, active: 2, target: 30, waiting: 1, failed: 0, old_voice: 0, aired_7d: 4 }],
  month: { chars: 1200, allowance: 100_000 },
  options: { refill: true, live: true, targets: { intro: 8, outro: 6, encourage: 30, present: 6, prayertime: 6, prayer: 12, break: 30 }, rest_hours: 72, month_chars: 100_000, old_voice: false },
  queued: 0,
  can_edit_options: false,
  ...over,
});

const line = (id: number, over: Partial<ModLine> = {}): ModLine => ({
  id, host_id: 1, kind: 'encourage', program_id: null, texts: { en: `Take a breath ${id}.`, de: `Atme durch ${id}.` },
  audio: { en: `/media/lines/${id}en.mp3`, de: `/media/lines/${id}de.mp3` }, durations: { en: 4100, de: 4600 }, tags: { time: 'any', mood: 'calm' },
  state: 'active', old_voice: false, source: 'model', chars: 40, uses: 3, last_aired: null, error: '', note: '', created: 0, updated: 1, ...over,
});

interface Call {
  url: string;
  method: string;
  body: Record<string, unknown> | null;
}

const json = (data: unknown): Response => new Response(JSON.stringify(data), { status: 200 });

/** Answers the panel's calls and records them. */
function station(o: LinesOverview, lines: ModLine[]): Call[] {
  const calls: Call[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET';
      const body = init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : null;
      calls.push({ url, method, body });
      const path = url.replace(/^\/api/, '');
      if (path === '/mod/hosts') return json({ hosts: [hope] });
      if (path.startsWith('/mod/lines/overview')) return json(o);
      if (path.startsWith('/mod/lines?')) return json({ lines, total: lines.length });
      if (path === '/mod/lines/bulk') return json({ ok: true, changed: (body?.ids as number[]).length });
      if (path === '/mod/lines/write') return json({ ok: true, queued: 1 });
      if (path === '/mod/hosts/1/lines') return json({ options: { ...o.options, ...body } });
      if (path.startsWith('/mod/lines')) return json({ line: lines[0] });
      return json({});
    }),
  );
  return calls;
}

const renderLines = () =>
  render(
    <MemoryRouter initialEntries={['/mod/lines?host=1']}>
      <LinesPanel />
    </MemoryRouter>,
  );

const listCalls = (calls: Call[]) => calls.filter((c) => c.url.startsWith('/api/mod/lines?')).map((c) => new URL(c.url, 'http://x').searchParams);

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('/mod › Lines', () => {
  it('shows the host, how full each kind is, this month\'s recordings, and the lines', async () => {
    station(overview(), [line(11), line(12, { state: 'draft', uses: 0 })]);
    renderLines();
    expect(await screen.findByText('Take a breath 11.')).toBeTruthy();
    expect(screen.getByText('Voice: OpenAI · gpt-4o-mini-tts · coral')).toBeTruthy();
    expect(screen.getByText('1,200 of 100,000 characters recorded this month.')).toBeTruthy();
    expect(screen.getByText('2 on air')).toBeTruthy();
    expect(screen.getByText('2 of 30')).toBeTruthy();
    expect(screen.getByText('Too few')).toBeTruthy();
    expect(within(screen.getByText('Take a breath 12.').closest('li')!).getByText('Waits for approval')).toBeTruthy();
    expect(screen.getByText(/Aired 3 times/)).toBeTruthy();
    expect(screen.getByText(/Not aired yet/)).toBeTruthy();
  });

  it('says when the host may record nothing yet', async () => {
    station(overview({ month: { chars: 0, allowance: 0 } }), []);
    renderLines();
    expect(await screen.findByText(/This host records nothing yet/)).toBeTruthy();
    expect(await screen.findByText('No lines match.')).toBeTruthy();
  });

  it('filters ask the server for the kind, the state and the words searched', async () => {
    const calls = station(overview(), [line(11)]);
    renderLines();
    await screen.findByText('Take a breath 11.');
    expect(listCalls(calls)[0]?.get('host')).toBe('1');
    fireEvent.change(screen.getByLabelText('Kind'), { target: { value: 'encourage' } });
    fireEvent.change(screen.getByLabelText('State'), { target: { value: 'old_voice' } });
    fireEvent.change(screen.getByLabelText('Search the words'), { target: { value: 'breath' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(listCalls(calls).some((p) => p.get('q') === 'breath')).toBe(true));
    const last = listCalls(calls).at(-1)!;
    expect([last.get('kind'), last.get('state'), last.get('q'), last.get('sort'), last.get('limit')]).toEqual(['encourage', 'old_voice', 'breath', 'newest', '50']);
  });

  it('plays a line in each language, one clip at a time', async () => {
    station(overview(), [line(11), line(12)]);
    const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
    const pause = vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined);
    renderLines();
    await screen.findByText('Take a breath 11.');
    fireEvent.click(screen.getAllByRole('button', { name: 'Play English' })[0]!);
    expect((play.mock.contexts[0] as HTMLAudioElement).src).toMatch(/\/media\/lines\/11en\.mp3$/);
    fireEvent.click(screen.getAllByRole('button', { name: 'Play German' })[1]!);
    expect((play.mock.contexts[1] as HTMLAudioElement).src).toMatch(/\/media\/lines\/12de\.mp3$/);
    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('changed words are recorded again only after asking, then sent with the tags', async () => {
    const calls = station(overview(), [line(11)]);
    renderLines();
    const row = (await screen.findByText('Take a breath 11.')).closest('li')!;
    fireEvent.click(within(row).getByRole('button', { name: 'Edit' }));
    fireEvent.change(within(row).getByLabelText('Words (English)'), { target: { value: 'Breathe in, slowly.' } });
    fireEvent.click(within(row).getByRole('button', { name: 'Save and record again' }));
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false);
    expect(within(row).getByText('Recording again costs characters. Save and record again?')).toBeTruthy();
    fireEvent.click(within(row).getByRole('button', { name: 'Yes' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true));
    const patch = calls.find((c) => c.method === 'PATCH')!;
    expect(patch.url).toBe('/api/mod/lines/11');
    expect(patch.body).toEqual({ texts: { en: 'Breathe in, slowly.', de: 'Atme durch 11.' }, tags: { time: 'any', mood: 'calm' } });
    expect(await screen.findByText('It is being recorded again.')).toBeTruthy();
  });

  it('a changed mood alone is saved without recording again', async () => {
    const calls = station(overview(), [line(11)]);
    renderLines();
    const row = (await screen.findByText('Take a breath 11.')).closest('li')!;
    fireEvent.click(within(row).getByRole('button', { name: 'Edit' }));
    fireEvent.change(within(row).getByLabelText('Mood'), { target: { value: 'joyful' } });
    fireEvent.click(within(row).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ tags: { time: 'any', mood: 'joyful' } }));
  });

  it('selected lines are paused together; removing them asks first', async () => {
    const calls = station(overview(), [line(11), line(12)]);
    renderLines();
    await screen.findByText('Take a breath 11.');
    fireEvent.click(screen.getByLabelText('Select all shown'));
    const bar = screen.getByRole('group', { name: 'Change the selected lines' });
    expect(within(bar).getByText('2 selected')).toBeTruthy();
    fireEvent.click(within(bar).getByRole('button', { name: 'Pause' }));
    await waitFor(() => expect(calls.find((c) => c.url === '/api/mod/lines/bulk')?.body).toEqual({ ids: [11, 12], action: 'pause' }));
    expect(await screen.findByText('2 lines changed.')).toBeTruthy();

    fireEvent.click(screen.getByLabelText('Select line 12'));
    const again = screen.getByRole('group', { name: 'Change the selected lines' });
    fireEvent.click(within(again).getByRole('button', { name: 'Remove' }));
    expect(within(again).getByText('Remove 1 line? Its recording is deleted.')).toBeTruthy();
    fireEvent.click(within(again).getByRole('button', { name: 'Yes' }));
    await waitFor(() => expect(calls.filter((c) => c.url === '/api/mod/lines/bulk').at(-1)?.body).toEqual({ ids: [12], action: 'remove' }));
  });

  it('new lines are written on request: kind, program, how many and a note', async () => {
    const calls = station(overview(), [line(11)]);
    renderLines();
    await screen.findByText('Take a breath 11.');
    fireEvent.click(screen.getByRole('button', { name: /^Write lines: Encouragement in the prayer time/ }));
    fireEvent.change(screen.getByLabelText('How many'), { target: { value: '3' } });
    fireEvent.change(screen.getByLabelText('A note for the writer (optional)'), { target: { value: 'Advent' } });
    fireEvent.click(screen.getByRole('button', { name: 'Write and record' }));
    await waitFor(() => expect(calls.find((c) => c.url === '/api/mod/lines/write')?.body).toEqual({ host_id: 1, kind: 'encourage', program_id: null, count: 3, hint: 'Advent' }));
    expect(await screen.findByText('3 lines are being written and recorded.')).toBeTruthy();

    // A welcome names its program: only programs of the host, the prayer hour's own kinds only in a prayer hour.
    fireEvent.click(screen.getByRole('button', { name: 'Write new lines' }));
    const form = screen.getByRole('button', { name: 'Write and record' }).closest('form')!;
    fireEvent.change(within(form).getByLabelText('Kind'), { target: { value: 'present' } });
    expect([...(within(form).getByLabelText('Program') as HTMLSelectElement).options].map((o) => o.textContent)).toEqual(['Prayer hour']);
    fireEvent.change(within(form).getByLabelText('Kind'), { target: { value: 'intro' } });
    fireEvent.change(within(form).getByLabelText('Program'), { target: { value: '6' } });
    fireEvent.click(within(form).getByRole('button', { name: 'Write and record' }));
    await waitFor(() => expect(calls.filter((c) => c.url === '/api/mod/lines/write').at(-1)?.body).toMatchObject({ kind: 'intro', program_id: 6, count: 5 }));
  });

  it('the options are an admin\'s: shown only when the server says so, and saved as the server keeps them', async () => {
    station(overview(), [line(11)]);
    const { unmount } = renderLines();
    await screen.findByText('Take a breath 11.');
    expect(screen.queryByRole('heading', { name: 'Options' })).toBeNull();
    unmount();

    const calls = station(overview({ can_edit_options: true }), [line(11)]);
    renderLines();
    const card = (await screen.findByRole('heading', { name: 'Options' })).closest('section')!;
    fireEvent.change(within(card).getByLabelText(/^Characters it may record each month/), { target: { value: '50000' } });
    fireEvent.click(within(card).getByLabelText('New lines go on air without approval'));
    fireEvent.click(within(card).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls.find((c) => c.url === '/api/mod/hosts/1/lines')?.body).toMatchObject({ month_chars: 50_000, live: false, rest_hours: 72 }));
    expect(await within(card).findByText('Options saved.')).toBeTruthy();
  });
});

describe('/mod › Programs: where the host\'s words come from', () => {
  const settings = { host: { enabled: true, every_songs: 3, intro: true, outro: true }, format: 'music' } as unknown as ProgramSettings;
  const program = (over: Partial<ModProgram> = {}): ModProgram => ({
    id: 7, channel_id: 1, slug: 'live', title_en: 'Live', title_de: 'Live', subtitle_en: '', subtitle_de: '', description_en: '', description_de: '', tagline_en: '', tagline_de: '',
    color: '#2f7bff', image: null, stage_mode: 'ambient', allowed: ['song'], themes: [], moods: [], settings, active: 1, ...over,
  });

  const channel = { id: 1, slug: 'main', name_en: 'Main', name_de: 'Main', is_main: 1, timezone: 'Europe/Berlin', color: '#2f7bff', sort: 0, active: 1, default_day_plan_id: null, fallback_program_id: null, programs: [] };

  function programsStation(p: ModProgram): Call[] {
    const calls: Call[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';
        calls.push({ url, method, body: init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : null });
        if (url === '/api/mod/channels/1/programs') return json({ programs: [p] });
        if (url === '/api/mod/programs/7') return json({ program: p });
        if (url === '/api/mod/hosts') return json({ hosts: [] });
        // A save reloads the overview.
        if (url === '/api/mod/overview') return json({ channels: [channel] });
        return json({});
      }),
    );
    return calls;
  }

  beforeEach(() => {
    useOverview.setState({ data: { channels: [channel] } as unknown as Overview, channelId: null });
  });
  afterEach(() => useOverview.setState({ data: null, channelId: null }));

  it('from recorded lines, for the kinds chosen', async () => {
    const calls = programsStation(program({ lines: { mode: 'fresh', kinds: [] } }));
    render(<ProgramsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    expect(screen.queryByLabelText('Goodbye')).toBeNull();
    fireEvent.click(screen.getByRole('radio', { name: 'From recorded lines' }));
    // A music program has no prayer time: its moments only.
    expect(screen.queryByLabelText('Encouragement in the prayer time')).toBeNull();
    fireEvent.click(screen.getByLabelText('Goodbye'));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')?.body?.lines).toEqual({ mode: 'library', kinds: DEFAULT_LINE_KINDS.filter((k) => k !== 'outro') }));
  });

  it('a program from a server older than recorded lines saves without them', async () => {
    const calls = programsStation(program());
    render(<ProgramsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    expect(screen.queryByRole('radio', { name: 'From recorded lines' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true));
    expect(calls.find((c) => c.method === 'PATCH')?.body).not.toHaveProperty('lines');
  });
});
