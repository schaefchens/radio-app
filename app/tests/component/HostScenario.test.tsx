import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@/i18n';
import { HostsPanel } from '@/components/mod/HostsPanel';
import type { ModHost, ScenarioCatalog, ScenarioResult } from '@/components/mod/modApi';
import { useSettings } from '@/store/settings';

/**
 * "Test a moment" in the host editor: a program's moment written by the real
 * writer with the unsaved settings, spoken as it would air in the editor's
 * language, the words and their delivery changeable and spoken again, and a
 * test show the next moment remembers.
 */

type Handler = (init?: RequestInit, url?: string) => Response | Promise<Response>;
const json = (data: unknown, status = 200): Response => new Response(JSON.stringify(data), { status });

/** fetch for /api, by "METHOD /path" without the query (GET when no method is given). */
function serve(routes: Record<string, Handler>) {
  const calls: { method: string; path: string; body: Record<string, unknown> | null }[] = [];
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace(/^\/api/, '');
    const method = init?.method ?? 'GET';
    calls.push({ method, path, body: init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : null });
    const handler = routes[`${method} ${path.split('?')[0]}`];
    return handler ? handler(init, path) : json({ error: 'not_found' }, 404);
  }));
  return calls;
}

const grace: ModHost = {
  id: 7, name: 'Grace', avatar: null, color: '#bd2eff', about_en: '', about_de: '', style: '', provider: 'openai', model: 'gpt-4o-mini-tts', voices: { en: 'marin', de: 'marin' },
  instructions: 'Calm.', settings: { speed: 1 }, max_chars_day: 5000, active: true, key_set: false, key_unreadable: false, station_key: true, key_hint: '', resting_until: 0,
  last_error: '', speaks: true, today: { chars: 1200, calls: 3 }, used_in: [],
};

const catalog: ScenarioCatalog = {
  programs: [
    { id: 1, channel: 'ARCHE', title: { en: 'ARCHE Live', de: 'ARCHE Live' }, format: 'music', moments: ['intro', 'break', 'announce', 'outro'] },
    { id: 2, channel: 'ARCHE', title: { en: 'Prayer Hour', de: 'Gebetsstunde' }, format: 'prayer', moments: ['intro', 'present', 'reading'] },
  ],
  efforts: ['low', 'medium'],
  effort: 'low',
};

const written = (over: Partial<ScenarioResult> = {}): ScenarioResult => ({
  moment: 'break', kind: 'break', texts: { en: 'What a song. Next is Oceans.', de: 'Was für ein Lied. Gleich kommt Oceans.' }, delivery: 'Bright and warm.',
  source: 'openai', theirs: false, seconds: 4.2, spoken: { en: 'What a song. Next is Oceans.', de: 'Was für ein Lied. Gleich kommt Oceans.' },
  songs: { previous: { id: 11, title: 'Goodness of God', artist: 'Bethel', kind: 'song' }, next: { id: 12, title: 'Oceans', artist: 'Hillsong UNITED', kind: 'song' } },
  given: { moment: { kind: 'break' }, show: { so_far: [] } }, ...over,
});

const clip = (body: Record<string, unknown>) => json({ audio: 'AAAA', ms: 3100, provider: 'openai', voice: 'marin', model: 'gpt-4o-mini-tts', spoken: body.text, direction: `Speak natural English. Calm. ${String(body.delivery)}` });

async function openTest(): Promise<void> {
  render(<HostsPanel />);
  fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
  fireEvent.click(screen.getByRole('button', { name: /Test a moment/ }));
  await screen.findByRole('combobox', { name: 'Program' });
}

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
  vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined);
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('/mod: testing a moment of a program', () => {
  it('asks for the programs only once opened, and offers each program\'s own moments', async () => {
    const calls = serve({ 'GET /mod/hosts': () => json({ hosts: [grace] }), 'GET /mod/hosts/scenarios': () => json(catalog) });
    render(<HostsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    expect(calls.some((c) => c.path === '/mod/hosts/scenarios')).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: /Test a moment/ }));
    const moment = (await screen.findByRole('combobox', { name: 'Moment' })) as HTMLSelectElement;
    expect([...moment.options].map((o) => o.text)).toEqual(['The program begins', 'Between two songs', 'A song request', 'The program ends']);
    fireEvent.change(screen.getByRole('combobox', { name: 'Program' }), { target: { value: '2' } });
    expect([...(screen.getByRole('combobox', { name: 'Moment' }) as HTMLSelectElement).options].map((o) => o.value)).toEqual(['intro', 'present', 'reading']);
    expect(screen.getByText(/Today 1200 of 5000 characters are used\./)).toBeTruthy();
  });

  it('writes the moment with the unsaved settings, speaks the editor\'s language with its delivery, and builds a test show', async () => {
    const tries: Record<string, unknown>[] = [];
    let writes = 0;
    const calls = serve({
      'GET /mod/hosts': () => json({ hosts: [grace] }),
      'GET /mod/hosts/scenarios': () => json(catalog),
      'POST /mod/hosts/scenario': () => json(written({ given: { moment: { kind: 'break', n: ++writes }, show: {} } })),
      'POST /mod/hosts/try': (init) => {
        const body = JSON.parse(String(init?.body)) as Record<string, unknown>;
        tries.push(body);
        return clip(body);
      },
    });
    await openTest();
    fireEvent.change(screen.getByRole('textbox', { name: /^Style notes/ }), { target: { value: 'Short and bright.' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Moment' }), { target: { value: 'break' } });
    fireEvent.click(screen.getByRole('button', { name: 'Write and speak' }));
    expect(await screen.findByText(/^3\.1 s by marin \(gpt-4o-mini-tts\)\.$/)).toBeTruthy();
    const scenario = calls.find((c) => c.path === '/mod/hosts/scenario')?.body;
    expect(scenario).toMatchObject({ host_id: 7, program_id: 1, moment: 'break', effort: 'low', earlier: [] });
    expect((scenario?.draft as { style: string }).style).toBe('Short and bright.');
    // Only the editor's language, by itself — as a listener hears it.
    expect(tries).toHaveLength(1);
    expect(tries[0]).toMatchObject({ lang: 'en', text: 'What a song. Next is Oceans.', delivery: 'Bright and warm.', theirs: false });
    expect(screen.getByText(/Written by the AI in 4\.2 s · Before: Goodness of God – Bethel · After: Oceans – Hillsong UNITED/)).toBeTruthy();
    expect(screen.getByText('The voice got: What a song. Next is Oceans. Direction: Speak natural English. Calm. Bright and warm.')).toBeTruthy();
    expect(screen.getByText('Test show: 1 moment')).toBeTruthy();

    // The German words changed, and the mood: spoken as they now are, without the writer.
    fireEvent.change(screen.getByRole('textbox', { name: 'German' }), { target: { value: 'Was für ein Lied!' } });
    fireEvent.change(screen.getByRole('textbox', { name: /How it should sound/ }), { target: { value: 'Quiet and tender.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Play German' }));
    await waitFor(() => expect(tries).toHaveLength(2));
    expect(tries[1]).toMatchObject({ lang: 'de', text: 'Was für ein Lied!', delivery: 'Quiet and tender.' });
    expect(calls.filter((c) => c.path === '/mod/hosts/scenario')).toHaveLength(1);

    // The next moment remembers the first, as it was last spoken, and goes on from its song.
    fireEvent.click(screen.getByRole('button', { name: 'Write and speak' }));
    await waitFor(() => expect(calls.filter((c) => c.path === '/mod/hosts/scenario')).toHaveLength(2));
    const second = calls.filter((c) => c.path === '/mod/hosts/scenario')[1]?.body;
    expect(second?.earlier).toEqual([{ moment: 'break', previous_id: 11, next_id: 12, texts: { en: 'What a song. Next is Oceans.', de: 'Was für ein Lied!' } }]);
    expect(second?.previous_id).toBe(12);
    expect(await screen.findByText('Test show: 2 moments')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Start over' }));
    expect(screen.queryByText(/Test show:/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Write and speak' }));
    await waitFor(() => expect(calls.filter((c) => c.path === '/mod/hosts/scenario')).toHaveLength(3));
    expect(calls.filter((c) => c.path === '/mod/hosts/scenario')[2]?.body?.earlier).toEqual([]);
  });

  it('a song found in the library is the song before; the fallback words say so', async () => {
    const calls = serve({
      'GET /mod/hosts': () => json({ hosts: [grace] }),
      'GET /mod/hosts/scenarios': () => json(catalog),
      'GET /mod/library': () => json({ items: [{ id: 44, kind: 'song', title: 'Tuvo Shel Elohim @SOLUIsrael', artist: 'Goodness of God in HEBREW' }, { id: 45, kind: 'jingle', title: 'Station ID', artist: '' }] }),
      'POST /mod/hosts/scenario': () => json(written({ source: 'template:error', delivery: '' })),
      'POST /mod/hosts/try': (init) => clip(JSON.parse(String(init?.body)) as Record<string, unknown>),
    });
    await openTest();
    fireEvent.change(screen.getByRole('textbox', { name: 'Song before: Search the library' }), { target: { value: 'Tuvo' } });
    fireEvent.click(await screen.findByRole('button', { name: 'Tuvo Shel Elohim @SOLUIsrael – Goodness of God in HEBREW' }));
    expect(screen.queryByRole('button', { name: /Station ID/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Write and speak' }));
    expect(await screen.findByText(/The AI did not answer \(error\): the station's own fallback words\./)).toBeTruthy();
    expect(calls.find((c) => c.path === '/mod/hosts/scenario')?.body?.previous_id).toBe(44);
  });

  it('a host on our computers is spoken there, asked after until done; an ElevenLabs host speaks nothing by itself', async () => {
    let polls = 0;
    const joy: ModHost = { ...grace, id: 9, name: 'Joy', provider: 'worker', model: 'qwen3-tts-1.7b-customvoice', voices: { en: 'Ryan', de: 'Sohee' }, settings: { temperature: 0.7 }, max_chars_day: 0, worker_online: true };
    serve({
      'GET /mod/hosts': () => json({ hosts: [joy] }),
      'GET /mod/workers': () => json({ workers: [] }),
      'POST /mod/hosts/catalog': () => json({ voices: [], models: [], account: null, errors: [], workers_online: 1 }),
      'GET /mod/hosts/scenarios': () => json(catalog),
      'POST /mod/hosts/scenario': () => json(written()),
      'POST /mod/hosts/try': () => json({ task: 5, provider: 'worker', voice: 'Ryan', model: 'qwen3-tts-1.7b-customvoice', spoken: 'What a song. Next is Oceans.', direction: 'Calm. Bright and warm.' }, 202),
      'GET /mod/hosts/try/5': () => json(++polls === 1 ? { state: 'leased' } : { state: 'done', audio: 'AAAA', ms: 2600 }),
    });
    await openTest();
    fireEvent.click(screen.getByRole('button', { name: 'Write and speak' }));
    expect(await screen.findByText('Being spoken on the computer …')).toBeTruthy();
    expect(await screen.findByText(/^2\.6 s by Ryan \(qwen3-tts-1\.7b-customvoice\)\.$/, {}, { timeout: 4000 })).toBeTruthy();
    expect(screen.getByText('The voice got: What a song. Next is Oceans. Direction: Calm. Bright and warm.')).toBeTruthy();
    vi.unstubAllGlobals();

    const tries: unknown[] = [];
    const eli: ModHost = { ...grace, id: 8, name: 'Eli', provider: 'elevenlabs', model: 'eleven_flash_v2_5', key_set: true };
    serve({
      'GET /mod/hosts': () => json({ hosts: [eli] }),
      'POST /mod/hosts/catalog': () => json({ voices: [], models: [], account: null, errors: [], stub: true }),
      'GET /mod/hosts/scenarios': () => json(catalog),
      'POST /mod/hosts/scenario': () => json(written()),
      'POST /mod/hosts/try': (init) => {
        tries.push(init?.body);
        return clip(JSON.parse(String(init?.body)) as Record<string, unknown>);
      },
    });
    document.body.innerHTML = '';
    await openTest();
    fireEvent.click(screen.getByRole('button', { name: 'Write and speak' }));
    expect(await screen.findByRole('button', { name: 'Play English' })).toBeTruthy();
    expect(screen.getByText(/Nothing is spoken by itself/)).toBeTruthy();
    expect(tries).toHaveLength(0);
  });
});
