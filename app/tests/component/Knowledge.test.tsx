import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import '@/i18n';
import { KnowledgeEditor, KnowledgePill, KnowledgeSettingsSection } from '@/components/mod/KnowledgePanel';
import type { KnowledgeRecord, LibraryItem } from '@/components/mod/modApi';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';

/**
 * /mod's view of what each song and video was looked up to be (server:
 * Library\Knowledge): moderators read and correct it per item; only admins
 * set whether the checks and the host use it, the budget and the standard.
 */

const json = (data: unknown, status = 200): Response => new Response(JSON.stringify(data), { status });

interface Sent {
  method: string;
  path: string;
  body: unknown;
}

function serve(answers: Record<string, () => Response>): Sent[] {
  const sent: Sent[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^\/api/, '');
      const method = init?.method ?? 'GET';
      sent.push({ method, path, body: init?.body ? JSON.parse(String(init.body)) : null });
      return answers[`${method} ${path}`]?.() ?? json({ error: 'not_found' }, 404);
    }),
  );
  return sent;
}

const item = {
  id: 7, kind: 'song', yt_id: 'SegneMaria1', audio: null, title: 'Segne Du, Maria (alle 3 Strophen)', artist: 'Lila', thumb: null, duration_ms: 197_000,
  languages: ['de'], themes: [], moods: [], program_ids: [], channel_ids: [], source: 'curated', active: 1, plays: 3, last_played: null, trend_score: 0,
  updated: 1, group_id: null, yt_channel: null,
} as LibraryItem;

const record: KnowledgeRecord = {
  yt_id: 'SegneMaria1', kind: 'song', state: 'ready', yt_title: 'Segne Du, Maria (alle 3 Strophen)', yt_artist: 'Lila', title: 'Segne du, Maria', artist: 'Lila',
  research: {
    identity: { identified: true, original: '', writers: [{ name: 'Cordula Wöhler', role: 'lyrics', died: '1916' }], year: '1870', artist_background: 'A singer from Munich.', christian_artist: 'unclear' },
    facts: [{ en: 'Cordula Wöhler wrote the words in 1870.', de: 'Cordula Wöhler schrieb den Text 1870.', source: 'https://de.wikipedia.org/wiki/Segne_du,_Maria' }],
    public_domain: { is: true, why: 'Wöhler died in 1916.', url: 'https://de.wikipedia.org/wiki/Segne_du,_Maria' },
  },
  analysis: {
    heard: true, message_en: 'A plea to Mary for her blessing.', message_de: 'Eine Bitte an Maria um ihren Segen.', summary_en: 'A Marian hymn sung to piano.',
    addressed_to: 'Mary', christian: 'yes', biblical: 'no', explicit: false, age: 'all', energy: 'calm', fits: [],
    quotes: [{ text: 'Segne du Maria, segne mich dein Kind', at: '0:22' }],
    concerns: [{ what: 'A prayer to Mary', why: 'Prayer to Mary is not biblical by the standard', quote: 'Segne du Maria' }],
  },
  facts_told: {}, text: '', text_source: '', cost_micros: 90_000, error: '', edited_by: '', researched: 1_791_451_800,
};

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
});
afterEach(() => {
  useSession.setState({ identity: null });
  vi.unstubAllGlobals();
});

describe('/mod › Library: what was looked up', () => {
  it('a pill names the worst first: not biblical before anything else', () => {
    render(<KnowledgePill k={{ state: 'ready', christian: 'yes', biblical: 'no', explicit: false, concern: true }} />);
    expect(screen.getByText('Not biblical')).toBeTruthy();
  });

  it('moderators read the concerns with their quotes, correct a fact and take the names found', async () => {
    const sent = serve({
      'GET /mod/library/7/knowledge': () => json({ item, knowledge: record }),
      'PATCH /mod/library/7/knowledge': () => json({ knowledge: record }),
      'POST /mod/library/7/knowledge/names': () => json({ item: { ...item, title: 'Segne du, Maria' } }),
    });
    const changed = vi.fn();
    render(<KnowledgeEditor item={item} onChanged={changed} />);
    expect(await screen.findByText('A prayer to Mary')).toBeTruthy();
    expect(screen.getByText('„Segne du Maria, segne mich dein Kind“ (0:22)')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Fact (English)'), { target: { value: 'Cordula Wöhler wrote the words in 1870, at 33.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await vi.waitFor(() => expect(sent.some((s) => s.method === 'PATCH')).toBe(true));
    const patch = sent.find((s) => s.method === 'PATCH')!.body as { facts: { en: string; source: string }[]; text?: string };
    expect(patch.facts).toEqual([{ en: 'Cordula Wöhler wrote the words in 1870, at 33.', de: 'Cordula Wöhler schrieb den Text 1870.', source: 'https://de.wikipedia.org/wiki/Segne_du,_Maria' }]);
    expect(patch.text).toBe('');
    fireEvent.click(screen.getByRole('button', { name: 'Use these names' }));
    await vi.waitFor(() => expect(sent.some((s) => s.path === '/mod/library/7/knowledge/names')).toBe(true));
  });

  it('admins set the switches, the budget and the standard; moderators see none of it', async () => {
    useSession.setState({ identity: { id: 'me0000001', role: 'moderator', claimed: true } as never });
    const sent = serve({});
    const { unmount } = render(<KnowledgeSettingsSection />);
    expect(screen.queryByText('Song and video knowledge')).toBeNull();
    expect(sent).toEqual([]);
    unmount();

    useSession.setState({ identity: { id: 'me0000001', role: 'admin', claimed: true } as never });
    const asked = serve({
      'GET /mod/knowledge': () =>
        json({ settings: { checks: false, air: false, budget_usd: 5, standard: 'Scripture is the measure.' }, standardDefault: 'Scripture is the measure.', spentMicros: 420_000,
          configured: { research: true, listen: true }, counts: [{ state: 'ready', n: 40 }, { state: 'failed', n: 2 }] }),
      'PUT /mod/knowledge': () => json({ settings: { checks: true, air: false, budget_usd: 8, standard: 'Scripture is the measure.' } }),
    });
    render(<KnowledgeSettingsSection />);
    expect(await screen.findByText(/40 looked up, 0 under way, 2 failed/)).toBeTruthy();
    fireEvent.click(screen.getByLabelText('Use in checks'));
    fireEvent.change(screen.getByLabelText('Daily budget for look-ups (USD)'), { target: { value: '8' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await vi.waitFor(() => expect(asked.some((s) => s.method === 'PUT')).toBe(true));
    // The default unchanged is sent as empty: the server keeps the default, also when it changes later.
    expect(asked.find((s) => s.method === 'PUT')!.body).toEqual({ checks: true, air: false, budget_usd: 8, standard: '' });
  });
});
