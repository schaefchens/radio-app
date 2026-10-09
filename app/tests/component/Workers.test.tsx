import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@/i18n';
import { HostsPanel } from '@/components/mod/HostsPanel';
import { StatusPanel } from '@/components/mod/StatusPanel';
import { WorkersSection } from '@/components/mod/WorkersSection';
import { configSnippet, joinCommand } from '@/components/mod/workerConfig';
import type { ModHost, ModWorker } from '@/components/mod/modApi';
import { useSettings } from '@/store/settings';

/**
 * Our own computers speak for hosts with Qwen: a host on them has no key, a
 * temperature instead of a speed, voices the computers offer, and a try that
 * waits for a computer; admins add computers (the key shown once), renew
 * their keys and remove them; the status says how many are online.
 */

type Handler = (init?: RequestInit) => Response | Promise<Response>;
const json = (data: unknown, status = 200): Response => new Response(JSON.stringify(data), { status });

/** fetch for /api, by "METHOD /path" (GET when no method is given). */
function serve(routes: Record<string, Handler>) {
  const calls: { method: string; path: string; body: unknown }[] = [];
  const fetch = vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace(/^\/api/, '');
    const method = init?.method ?? 'GET';
    calls.push({ method, path, body: init?.body ? JSON.parse(String(init.body)) : null });
    const handler = routes[`${method} ${path}`];
    return handler ? handler(init) : json({ error: 'not_found' }, 404);
  });
  vi.stubGlobal('fetch', fetch);
  return calls;
}

const joy: ModHost = {
  id: 9, name: 'Joy', avatar: null, color: '#0a9a8a', about_en: '', about_de: '', style: '', provider: 'worker', model: 'qwen3-tts-1.7b-customvoice',
  voices: { en: 'Ryan', de: 'Sohee' }, instructions: 'Warm and gentle.', settings: { temperature: 0.7 }, max_chars_day: 0, active: true, key_set: false,
  key_unreadable: false, station_key: false, resting_until: 0, last_error: '', speaks: false, today: { chars: 0, calls: 0 }, used_in: [], worker_online: false,
};

const mac: ModWorker = {
  id: 3, name: 'MacBook Chris', active: true, online: true, last_seen: Math.floor(Date.now() / 1000) - 5, version: 'arche-worker 1.0.0',
  engine: { model: 'qwen3-tts-1.7b-customvoice' }, voices: [{ id: 'Sohee', label: 'Sohee' }, { id: 'Ryan', label: 'Ryan' }], languages: ['en', 'de'],
  key_hint: 'c0de', tasks: { done_today: 12, failed_today: 1, queued: 2 },
};

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('/mod: a host on our own computers', () => {
  it('has no key, a temperature, the voices the computers offer — and says when no computer is online', async () => {
    const calls = serve({
      'GET /mod/hosts': () => json({ hosts: [joy] }),
      'GET /mod/workers': () => json({ workers: [] }),
      'POST /mod/hosts/catalog': () =>
        json({ voices: [{ id: 'Sohee', name: 'Sohee', category: 'qwen', labels: '', languages: ['de', 'en'] }, { id: 'Ryan', name: 'Ryan', category: 'qwen', labels: '', languages: ['en'] }], models: [{ id: 'qwen3-tts-1.7b-customvoice', name: 'Qwen3-TTS 1.7B', languages: ['en', 'de'], cost: 0 }], account: null, errors: [], workers_online: 0 }),
    });
    render(<HostsPanel />);
    expect(await screen.findByText('No computer online')).toBeTruthy();
    expect(screen.getByText('Own computer (Qwen) · qwen3-tts-1.7b-customvoice · 0 characters today', { exact: false })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    expect(screen.getByRole('radio', { name: 'Own computer (Qwen)' })).toHaveProperty('checked', true);
    expect(screen.queryByLabelText('API key')).toBeNull();
    expect(screen.getByText('Temperature: 0.70')).toBeTruthy();
    expect(screen.queryByText(/^Speed/)).toBeNull();
    // The computers are asked as the editor opens: no click, no key.
    expect(await screen.findByText(/No computer is connected right now/)).toBeTruthy();
    expect(calls.find((c) => c.path === '/mod/hosts/catalog')?.body).toMatchObject({ provider: 'worker', host_id: 9 });
    // Joy has a voice of her own for German: the first picker is English's.
    const [voice, voiceDe] = screen.getAllByRole('combobox', { name: /^Voice/ }) as HTMLSelectElement[];
    expect(voiceDe?.value).toBe('Sohee');
    // Qwen's presets are there although no computer reported them.
    expect([...(voice?.options ?? [])].map((o) => o.value)).toEqual(['Ryan', 'Aiden', 'Vivian', 'Serena', 'Uncle_Fu', 'Dylan', 'Eric', 'Ono_Anna', 'Sohee', '__other']);
    // Each with a few words in the list, and the chosen one described under it.
    expect(screen.getAllByRole('option', { name: 'Serena · female, warm, gentle' })).toHaveLength(2);
    expect(screen.getByText('A dynamic male voice with a strong rhythmic drive. Native language: English.')).toBeTruthy();
    expect(screen.getByText('A warm Korean female voice with rich emotion. Native language: Korean. Sounds good in German.')).toBeTruthy();
    expect(voice?.value).toBe('Ryan');
    const model = screen.getByRole('combobox', { name: /^Model/ }) as HTMLSelectElement;
    expect([...model.options].map((o) => o.textContent)).toEqual(['Qwen3-TTS 1.7B (qwen3-tts-1.7b-customvoice)', 'Another model id']);
    expect(model.value).toBe('qwen3-tts-1.7b-customvoice');
    fireEvent.click(screen.getByRole('button', { name: 'Ask our computers again' }));
    await waitFor(() => expect(calls.filter((c) => c.path === '/mod/hosts/catalog')).toHaveLength(2));
  });

  it('a try waits for a computer, then plays what it spoke', async () => {
    let polls = 0;
    serve({
      'GET /mod/hosts': () => json({ hosts: [{ ...joy, worker_online: true, speaks: true }] }),
      'GET /mod/workers': () => json({ workers: [mac] }),
      'POST /mod/hosts/try': () => json({ task: 5, provider: 'worker', voice: 'Sohee', model: 'qwen3-tts-1.7b-customvoice' }, 202),
      'GET /mod/hosts/try/5': () => json(++polls === 1 ? { state: 'leased' } : { state: 'done', audio: 'AAAA', ms: 1800 }),
    });
    const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
    render(<HostsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    fireEvent.click(screen.getByRole('button', { name: 'Play' }));
    expect(await screen.findByText('Being spoken on the computer …')).toBeTruthy();
    expect(await screen.findByText(/^1\.8 s of speech by Sohee \(qwen3-tts-1\.7b-customvoice\) for \d+ characters\.$/, {}, { timeout: 4000 })).toBeTruthy();
    expect(play).toHaveBeenCalled();
    expect(screen.getByText('Uses the unsaved changes. Spoken on one of our computers; costs nothing.')).toBeTruthy();
  });

  it('with no computer online the try says so', async () => {
    serve({
      'GET /mod/hosts': () => json({ hosts: [joy] }),
      'GET /mod/workers': () => json({ workers: [] }),
      'POST /mod/hosts/try': () => json({ error: 'no_worker' }, 409),
    });
    render(<HostsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    fireEvent.click(screen.getByRole('button', { name: 'Play' }));
    expect(await screen.findByText(/No computer is online with this voice right now/)).toBeTruthy();
  });
});

describe('/mod: our computers and lent ones', () => {
  it('an invite asks whose computer it is, shows its code once with the command to join, and can be withdrawn', async () => {
    let invited = false;
    const expires = Math.floor(Date.now() / 1000) + 72 * 3600;
    const invite = { id: 4, name: "Peter's PC", trust: 'lender', expires, created_by: 'Chris' };
    const calls = serve({
      'GET /mod/workers': () => json({ workers: [], invites: invited ? [invite] : [] }),
      'POST /mod/workers/invites': () => {
        invited = true;
        return json({ invite, code: 'AB12-CD34-EF56-GH78-JK90-MN12-PQ' });
      },
      'DELETE /mod/workers/invites/4': () => json({ ok: true }),
    });
    render(<WorkersSection />);
    expect(await screen.findByText(/No computer yet/)).toBeTruthy();
    const create = screen.getByRole('button', { name: 'Create invite' });
    fireEvent.change(screen.getByLabelText('Name of the computer'), { target: { value: "Peter's PC" } });
    expect(create).toHaveProperty('disabled', true);
    fireEvent.click(screen.getByRole('radio', { name: "Lent by someone we trust: only work without listeners' words." }));
    fireEvent.click(create);
    expect(await screen.findByText("Invite for Peter's PC")).toBeTruthy();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/mod/workers/invites')?.body).toEqual({ name: "Peter's PC", trust: 'lender' });
    expect(screen.getByLabelText('Invite code').textContent).toBe('AB12-CD34-EF56-GH78-JK90-MN12-PQ');
    expect(screen.getByLabelText('Command to join').textContent).toBe(`herde join ${window.location.origin}/api/worker/v2 AB12-CD34-EF56-GH78-JK90-MN12-PQ`);
    expect(await screen.findByText('Open invites')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Done, I have it' }));
    expect(screen.queryByText("Invite for Peter's PC")).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Withdraw' }));
    expect(await screen.findByText('Invite withdrawn.')).toBeTruthy();
    expect(calls.some((c) => c.method === 'DELETE' && c.path === '/mod/workers/invites/4')).toBe(true);
  });

  it('a lent computer is marked as lent, says when it is paused, and speaks on air only when an admin switches it on', async () => {
    const now = Math.floor(Date.now() / 1000);
    const lent: ModWorker = {
      ...mac, id: 5, name: "Peter's PC", trust: 'lender', live: false, state: 'paused', resume_at: now + 3600, online: false, protocol: 2,
      platform: { os: 'linux', arch: 'x86_64', accelerator: 'NVIDIA GeForce RTX 4070' }, version: 'herde/0.2.0',
      engines: [{ kind: 'tts', model: 'qwen3-tts-1.7b', location: 'local', voices: 2 }, { kind: 'text', model: 'gemma4-31b', location: 'local', voices: 0 }],
    };
    const tired: ModWorker = { ...mac, id: 6, name: 'Old Mac', trust: 'own', resting_until: now + 600, protocol: 1 };
    const calls = serve({
      'GET /mod/workers': () => json({ workers: [lent, tired], invites: [] }),
      'PATCH /mod/workers/5': () => json({ worker: { ...lent, live: true } }),
    });
    render(<WorkersSection />);
    const row = (await screen.findByText("Peter's PC")).closest('li') as HTMLElement;
    expect(within(row).getByText('Lent')).toBeTruthy();
    expect(within(row).getByText(/^Paused until /)).toBeTruthy();
    expect(within(row).getByText(/NVIDIA GeForce RTX 4070 · speech: qwen3-tts-1\.7b, text: gemma4-31b/)).toBeTruthy();
    const live = within(row).getByRole('checkbox', { name: 'May speak on air' });
    expect(live).toHaveProperty('checked', false);
    fireEvent.click(live);
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ live: true }));
    const old = screen.getByText('Old Mac').closest('li') as HTMLElement;
    expect(within(old).getByText('Ours')).toBeTruthy();
    expect(within(old).getByText(/^Resting until /)).toBeTruthy();
    expect(within(old).getByText(/old voice worker/)).toBeTruthy();
    expect(within(old).queryByRole('checkbox', { name: 'May speak on air' })).toBeNull();
  });

  it('the old voice worker is still added with a key shown once, with the lines for its config', async () => {
    let added = false;
    const calls = serve({
      'GET /mod/workers': () => json({ workers: added ? [{ ...mac, online: false, last_seen: 0, version: '', voices: [], tasks: { done_today: 0, failed_today: 0, queued: 0 } }] : [] }),
      'POST /mod/workers': () => {
        added = true;
        return json({ worker: { ...mac, online: false, last_seen: 0 }, key: 'k'.repeat(64) });
      },
    });
    render(<WorkersSection />);
    expect(await screen.findByText(/No computer yet/)).toBeTruthy();
    fireEvent.click(screen.getByText('Old voice worker (arche-worker): add with a key'));
    fireEvent.change(screen.getByLabelText('Name of the Mac'), { target: { value: 'MacBook Chris' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add computer' }));
    expect(await screen.findByText('Key for MacBook Chris')).toBeTruthy();
    const snippet = screen.getByLabelText("Lines for the computer's config").textContent ?? '';
    expect(snippet).toContain(`url = "${window.location.origin}"`);
    expect(snippet).toContain(`key = "${'k'.repeat(64)}"`);
    expect(calls.find((c) => c.method === 'POST' && c.path === '/mod/workers')?.body).toEqual({ name: 'MacBook Chris' });
    expect(await screen.findByText('not seen yet')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Done, I have it' }));
    expect(screen.queryByText('Key for MacBook Chris')).toBeNull();
  });

  it('shows what a computer offers and did; a new key and removing it ask first', async () => {
    const calls = serve({
      'GET /mod/workers': () => json({ workers: [mac] }),
      'PATCH /mod/workers/3': () => json({ worker: mac, key: 'n'.repeat(64) }),
      'DELETE /mod/workers/3': () => json({ ok: true }),
    });
    render(<WorkersSection />);
    expect(await screen.findByText('MacBook Chris')).toBeTruthy();
    expect(screen.getByText('Online')).toBeTruthy();
    expect(screen.getByText(/voices: Sohee, Ryan/)).toBeTruthy();
    expect(screen.getByText(/Today 12 spoken, 1 failed · 2 waiting/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'New key' }));
    expect(screen.getByText('Give MacBook Chris a new key? The old one stops working at once.')).toBeTruthy();
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Yes' }));
    expect(await screen.findByText('Key for MacBook Chris')).toBeTruthy();
    expect(screen.getByLabelText("Lines for the computer's config").textContent).toContain('n'.repeat(64));
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ rotate: true });
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    fireEvent.click(screen.getByRole('button', { name: 'Yes' }));
    expect(await screen.findByText('Computer removed.')).toBeTruthy();
    expect(calls.some((c) => c.method === 'DELETE' && c.path === '/mod/workers/3')).toBe(true);
  });

  it('the config lines name a local station as dev; a computer joins at the station\'s protocol 2 address', () => {
    expect(configSnippet('abc', 'http://localhost:8080')).toBe('[[stations]]\nname = "ARCHE dev"\nurl = "http://localhost:8080"\nkey = "abc"\n');
    expect(configSnippet('abc', 'https://radio.schaefchens.de')).toContain('name = "ARCHE"');
    expect(joinCommand('AB12-CD34', 'https://radio.schaefchens.de')).toBe('herde join https://radio.schaefchens.de/api/worker/v2 AB12-CD34');
  });
});

describe('/mod: the status counts our computers', () => {
  it('how many are online and how many jobs wait', async () => {
    serve({
      'GET /mod/status': () =>
        json({
          now: Date.now(), lastTick: null, channels: [], jobs: {}, hostBreaks: [], usage: [], spentTodayUsd: 0, budgetUsd: 5,
          ai: { text: 'stub', hostModel: '', moderationModel: '', voice: 'worker', voices: [{ id: 9, name: 'Joy', provider: 'worker', model: 'qwen3-tts-1.7b-customvoice', active: true, speaks: true, restingUntil: 0, charsToday: 0, cap: 0, lastError: '' }] },
          realtime: { driver: 'static', slots: [], nodes: [] }, audit: [], workers: { online: 1, total: 2, queued: 3 },
        }),
    });
    render(<StatusPanel />);
    expect(await screen.findByText(/Computers: 1 of 2 online, 3 jobs waiting/)).toBeTruthy();
    const joyRow = screen.getByText('Joy').closest('li') as HTMLElement;
    expect(within(joyRow).getByText(/Own computer \(Qwen\) · qwen3-tts-1\.7b-customvoice/)).toBeTruthy();
  });
});
