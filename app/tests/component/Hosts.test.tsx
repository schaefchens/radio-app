import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import type { ChannelsFile, DayFile, DayProgram, HostInfo, ProgramRef, TimelineItem } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';
import { StageVisual } from '@/components/stage/StageVisual';
import { HostBody } from '@/components/home/HostCard';
import { TrackTitle } from '@/components/home/NowPlaying';
import { useNowPlaying } from '@/components/home/useNowPlaying';
import { DayBlocks } from '@/components/home/TodayProgram';
import { ProgramSheet } from '@/components/schedule/ProgramSheet';
import { PrivacyNote } from '@/components/submit/VideoRequestSheet';
import { HostLineup } from '@/components/mod/HostLineup';
import { HostsPanel } from '@/components/mod/HostsPanel';
import type { LineupEntry, ModHost } from '@/components/mod/modApi';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';

/**
 * Several hosts share the station: a moment names who speaks it, the
 * schedule who hosts a program, the forms when ElevenLabs reads out what a
 * listener sends — and /mod's lineup decides who is on air and who steps in.
 */

const david: HostInfo = { name: 'David', avatar: null, color: '#e0763a', about: { en: 'Mornings and your requests.', de: 'Morgens und eure Wünsche.' }, voice: 'elevenlabs' };
const hope: HostInfo = { name: 'Hope', avatar: '/media/stage/hope.webp', color: '#2f7bff', about: { en: '', de: '' }, voice: 'openai' };
const live: ProgramRef = {
  id: 'live',
  title: { en: 'Live', de: 'Live' },
  subtitle: { en: '', de: '' },
  color: '#2f7bff',
  stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } },
  allowed: ['song', 'prayer'],
  format: 'music',
  voicedBy: ['openai'],
};
const channels: ChannelsFile = {
  v: 1,
  gen: 0,
  minClient: 1,
  channels: [{ id: 'main', name: { en: 'Arche', de: 'Arche' }, main: true, tz: 'Europe/Berlin', color: '#2f7bff', host: hope, evergreen: null }],
  features: { songRequests: true, contributions: true, realtime: false },
};

const moment = (host: HostInfo | null, text = 'Good morning, everyone.'): TimelineItem => ({
  id: 'h1', type: 'host', start: 0, dur: 12_000, p: 'live', kind: 'break', audio: { en: '/media/host/h.mp3' }, text: { en: text }, voices: [], prayers: [], notice: null, host,
});
const song: TimelineItem = { id: 's1', type: 'song', kind: 'song', start: 12_000, dur: 200_000, p: 'live', yt: 'AAAAAAAAAAA', title: 'One', artist: 'X', thumb: null, request: null, fallback: null };
const engineWith = (item: TimelineItem, extra: Partial<EngineState> = {}): EngineState => ({
  ...initialState('main'),
  joined: true,
  program: live,
  item,
  mode: item.type === 'host' ? 'host' : 'song',
  hostText: item.type === 'host' ? (item.text.en ?? null) : null,
  ...extra,
});

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
  useSession.setState({ channels });
});
afterEach(() => {
  vi.unstubAllGlobals();
  useSession.setState({ channels: null });
  useRadio.setState({ engine: initialState('main') });
});

describe('who speaks, on the stage', () => {
  it('names the moment\'s host, with a few words about them on the full stage — in rings of their color', () => {
    const { container } = render(<StageVisual engine={engineWith(moment(david))} />);
    expect(screen.getByText('David is speaking')).toBeTruthy();
    expect(screen.getByText('Mornings and your requests.')).toBeTruthy();
    const ring = container.querySelector('.stage-host-avatar span') as HTMLElement;
    expect(ring.style.borderColor).toBe('rgba(224, 118, 58, 0.6)');
  });

  it('the compact stage leaves the words about them out', () => {
    render(<StageVisual engine={engineWith(moment(david))} compact />);
    expect(screen.getByText('David is speaking')).toBeTruthy();
    expect(screen.queryByText('Mornings and your requests.')).toBeNull();
  });

  it('a moment from before several hosts is the channel\'s host, with their picture', () => {
    const { container } = render(<StageVisual engine={engineWith(moment(null))} />);
    expect(screen.getByText('Hope is speaking')).toBeTruthy();
    expect(container.querySelector('.stage-host-avatar img')?.getAttribute('src')).toBe('/media/stage/hope.webp');
  });
});

describe('who speaks, in the host card and the song row', () => {
  it('the card shows who spoke last while music plays', () => {
    useRadio.setState({ engine: engineWith(song, { lastHost: { text: 'That was for Jenny.', at: 0, host: david } }) });
    render(<HostBody />);
    expect(screen.getByText('David')).toBeTruthy();
    expect(screen.getByText('Mornings and your requests.')).toBeTruthy();
    expect(screen.getByText('That was for Jenny.')).toBeTruthy();
  });

  it('the song row names the host, and credits ElevenLabs where its voice is heard', () => {
    function Row() {
      const np = useNowPlaying();
      return np ? <TrackTitle np={np} /> : null;
    }
    useRadio.setState({ engine: engineWith(moment(david)) });
    const { unmount } = render(<Row />);
    expect(screen.getByText('On air: David')).toBeTruthy();
    expect(screen.getByText('AI host · voice: elevenlabs.io')).toBeTruthy();
    unmount();
    useRadio.setState({ engine: engineWith(moment(hope)) });
    render(<Row />);
    expect(screen.getByText('AI host')).toBeTruthy();
  });
});

describe('who hosts a program, in the schedule', () => {
  const program: DayProgram = { ...live, description: { en: 'Worship all day.', de: 'Lobpreis den ganzen Tag.' }, hosts: [david, hope] };
  const day: DayFile = { v: 1, channel: 'main', date: '2026-10-05', tz: 'Europe/Berlin', gen: 0, blocks: [{ start: 0, end: 3_600_000, p: 'live' }], programs: { live: program }, played: [] };

  it('a day\'s block names its hosts', () => {
    render(<DayBlocks day={day} />);
    expect(screen.getByText('with David · Hope')).toBeTruthy();
  });

  it('the program sheet introduces each, and says they take turns', () => {
    render(<ProgramSheet program={program} channel="main" next={null} onClose={() => undefined} />);
    const sheet = screen.getByRole('dialog', { hidden: true });
    expect(within(sheet).getByText('Hosts')).toBeTruthy();
    expect(within(sheet).getByText('Mornings and your requests.')).toBeTruthy();
    expect(within(sheet).getByText('They take turns: each airing has one of them.')).toBeTruthy();
  });
});

describe('the forms say when ElevenLabs reads it out', () => {
  const note = () =>
    render(
      <MemoryRouter>
        <PrivacyNote />
      </MemoryRouter>,
    );

  it('only in a program one of whose hosts speaks with ElevenLabs', () => {
    useRadio.setState({ engine: { ...initialState('main'), program: live } });
    const { unmount } = note();
    expect(screen.queryByText(/ElevenLabs/)).toBeNull();
    unmount();
    useRadio.setState({ engine: { ...initialState('main'), program: { ...live, voicedBy: ['openai', 'elevenlabs'] } } });
    note();
    expect(screen.getByText(/an AI voice from ElevenLabs \(USA\) may read it out/)).toBeTruthy();
  });
});

describe('/mod: a lineup of hosts', () => {
  const host = (id: number, name: string, provider: ModHost['provider'] = 'openai'): ModHost => ({
    id, name, avatar: null, color: '#2f7bff', about_en: '', about_de: '', style: '', provider, model: 'gpt-4o-mini-tts', voices: { en: 'coral' }, instructions: '',
    settings: { speed: 1 }, max_chars_day: 0, active: true, key_set: false, key_unreadable: false, station_key: true, resting_until: 0, last_error: '', speaks: true,
    today: { chars: 0, calls: 0 }, used_in: [],
  });
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ hosts: [host(1, 'Hope'), host(2, 'David', 'elevenlabs'), host(3, 'Grace')] }), { status: 200 })));
  });

  function Lineup({ initial, onChange }: { initial: LineupEntry[]; onChange: (v: LineupEntry[]) => void }) {
    return <HostLineup value={initial} onChange={onChange} emptyHint="None chosen" />;
  }

  it('the first added goes on air, the next ones are fallbacks; roles and order can change', async () => {
    const changed = vi.fn();
    const { rerender } = render(<Lineup initial={[]} onChange={changed} />);
    expect(await screen.findByText('None chosen')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Add a host:'), { target: { value: '2' } });
    expect(changed).toHaveBeenLastCalledWith([{ id: 2, role: 'main' }]);
    rerender(<Lineup initial={[{ id: 2, role: 'main' }]} onChange={changed} />);
    fireEvent.change(screen.getByLabelText('Add a host:'), { target: { value: '1' } });
    expect(changed).toHaveBeenLastCalledWith([{ id: 2, role: 'main' }, { id: 1, role: 'fallback' }]);
    rerender(<Lineup initial={[{ id: 2, role: 'main' }, { id: 1, role: 'fallback' }, { id: 3, role: 'fallback' }]} onChange={changed} />);
    fireEvent.click(screen.getByRole('button', { name: 'Move Grace up' }));
    expect(changed).toHaveBeenLastCalledWith([{ id: 2, role: 'main' }, { id: 3, role: 'fallback' }, { id: 1, role: 'fallback' }]);
    fireEvent.change(screen.getByLabelText('Role of Hope'), { target: { value: 'main' } });
    expect(changed).toHaveBeenLastCalledWith([{ id: 2, role: 'main' }, { id: 1, role: 'main' }, { id: 3, role: 'fallback' }]);
    fireEvent.click(screen.getByRole('button', { name: 'Remove David' }));
    expect(changed).toHaveBeenLastCalledWith([{ id: 1, role: 'fallback' }, { id: 3, role: 'fallback' }]);
  });

  it('says so when nobody is on air', async () => {
    render(<Lineup initial={[{ id: 1, role: 'fallback' }]} onChange={() => undefined} />);
    expect(await screen.findByText('At least one host has to be on air.')).toBeTruthy();
  });
});

describe('/mod: trying a host\'s voice', () => {
  const grace: ModHost = {
    id: 7, name: 'Grace', avatar: null, color: '#bd2eff', about_en: '', about_de: '', style: '', provider: 'openai', model: 'gpt-4o-mini-tts', voices: { en: 'marin', de: 'marin' },
    instructions: 'Calm.', settings: { speed: 0.95 }, max_chars_day: 0, active: true, key_set: false, key_unreadable: false, station_key: true, key_hint: '', resting_until: 0,
    last_error: '', speaks: true, today: { chars: 0, calls: 0 }, used_in: [],
  };

  it('every voice is in the list — not only the one already chosen — and the try names the voice that spoke', async () => {
    const tried: { voices: Record<string, string> }[] = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.endsWith('/mod/hosts/try')) {
        const body = JSON.parse(String(init?.body)) as { lang: 'en' | 'de'; draft: { voices: Record<string, string>; model: string } };
        tried.push(body.draft);
        return new Response(JSON.stringify({ audio: 'AAAA', ms: 2400, provider: 'openai', voice: body.draft.voices[body.lang], model: body.draft.model }), { status: 200 });
      }
      return new Response(JSON.stringify({ hosts: [grace] }), { status: 200 });
    }));
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
    render(<HostsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    // The select's name is its whole label: "Voice", then the hint.
    const voice = screen.getByRole('combobox', { name: /^Voice/ }) as HTMLSelectElement;
    expect(voice.value).toBe('marin');
    expect([...voice.options].map((o) => o.value)).toEqual(expect.arrayContaining(['coral', 'onyx', 'marin', 'shimmer', 'cedar']));
    expect(screen.getByRole('option', { name: 'onyx – male, deep' })).toBeTruthy();
    fireEvent.change(voice, { target: { value: 'onyx' } });
    fireEvent.click(screen.getByRole('button', { name: 'Play' }));
    expect(await screen.findByText(/^2\.4 s of speech by onyx \(gpt-4o-mini-tts\) for \d+ characters\.$/)).toBeTruthy();
    expect(tried[0]?.voices).toEqual({ en: 'onyx', de: 'onyx' });
    // A voice of one's own: the field opens with it.
    fireEvent.change(voice, { target: { value: '__other' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'A voice id of your own (voice_…)' }), { target: { value: 'voice_abc123' } });
    expect(voice.value).toBe('__other');
  });

  it('the model is a list of the known ones, and still takes any other id', async () => {
    const saved: { model: string }[] = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.endsWith('/mod/hosts/7') && init?.method === 'PATCH') {
        saved.push(JSON.parse(String(init.body)) as { model: string });
        return new Response(JSON.stringify({ host: grace }), { status: 200 });
      }
      if (url.endsWith('/mod/hosts/catalog')) return new Response(JSON.stringify({ voices: [], models: [], account: null, errors: [], workers_online: 1 }), { status: 200 });
      return new Response(JSON.stringify({ hosts: [grace] }), { status: 200 });
    }));
    render(<HostsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    // Switched there and back, one list each time — the other provider's leaves.
    fireEvent.click(screen.getByRole('radio', { name: 'Own computer (Qwen)' }));
    expect((screen.getAllByRole('combobox', { name: /^Model/ }) as HTMLSelectElement[]).map((m) => m.value)).toEqual(['qwen3-tts-1.7b-customvoice']);
    fireEvent.click(screen.getByRole('radio', { name: 'OpenAI' }));
    const model = screen.getByRole('combobox', { name: /^Model/ }) as HTMLSelectElement;
    expect([...model.options].map((o) => o.value)).toEqual(['gpt-4o-mini-tts', 'tts-1', 'tts-1-hd', '__other']);
    expect(model.value).toBe('gpt-4o-mini-tts');
    expect(screen.queryByRole('textbox', { name: 'Another model id' })).toBeNull();
    // A successor OpenAI names one day goes in without a deploy.
    fireEvent.change(model, { target: { value: '__other' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Another model id' }), { target: { value: 'gpt-5-mini-tts' } });
    expect(model.value).toBe('__other');
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await screen.findByText('Host saved.');
    expect(saved[0]?.model).toBe('gpt-5-mini-tts');
  });
});
