import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import '@/i18n';
import { LibraryPanel } from '@/components/mod/LibraryPanel';
import type { LibraryDuplicate, LibraryItem, VideoLookup } from '@/components/mod/modApi';
import { useOverview } from '@/components/mod/overview';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';

/**
 * /mod › Library's housekeeping (server: Library::duplicates, ::delete):
 * another upload of a film once came in beside the one there — what is
 * likely the same is shown before adding and beside each item, and a
 * switched-off song or video can be deleted for good.
 */

const json = (data: unknown, status = 200): Response => new Response(JSON.stringify(data), { status });

interface Sent {
  method: string;
  path: string;
  body: unknown;
}

function serve(answer: (method: string, path: string) => Response | undefined): Sent[] {
  const sent: Sent[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^\/api/, '');
      const method = init?.method ?? 'GET';
      sent.push({ method, path, body: typeof init?.body === 'string' ? JSON.parse(init.body) : null });
      return answer(method, path) ?? json({ error: 'not_found' }, 404);
    }),
  );
  return sent;
}

const there: LibraryDuplicate = { id: 65, kind: 'film', yt_id: 'HimmlKino01', title: 'Vergeben - Forgiven - 2016', artist: 'Himmlisches Kino', duration_ms: 4_815_000, active: 1, thumb: null };

const lookup = (duplicates: LibraryDuplicate[]): VideoLookup => ({
  id: 'DzangoFilm1', title: 'Vergeben - Forgiven mit KEVIN SORBO', artist: 'Dzango', channel: 'Dzango - Filme für Männer', duration_ms: 4_815_000,
  embeddable: true, public: true, live: false, age_restricted: false, playable: true, existing: null, duplicates,
});

const item = (over: Partial<LibraryItem>): LibraryItem => ({
  id: 62, kind: 'film', yt_id: 'DzangoFilm1', audio: null, title: 'Vergeben - Forgiven mit KEVIN SORBO', artist: 'Dzango', thumb: null, duration_ms: 4_815_000,
  languages: [], themes: [], moods: [], program_ids: [], channel_ids: [], source: 'curated', active: 0, plays: 0, last_played: null, trend_score: 0,
  updated: 1, group_id: null, yt_channel: null, ...over,
});

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
  useSession.setState({ identity: { id: 'me0000001', role: 'moderator', claimed: true } as never });
  useOverview.setState({ data: { youtube: true } as never });
});
afterEach(() => {
  useSession.setState({ identity: null });
  useOverview.setState({ data: null });
  vi.unstubAllGlobals();
});

async function lookUp(kind: string): Promise<void> {
  fireEvent.change(screen.getByLabelText('Kind'), { target: { value: kind } });
  fireEvent.change(screen.getByLabelText('YouTube link'), { target: { value: 'https://youtu.be/DzangoFilm1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Look up' }));
  await screen.findByText('Dzango - Filme für Männer');
}

describe('/mod › Library: duplicates and deleting', () => {
  it('shows what is likely the same before adding, and adds it only with "Add anyway"', async () => {
    const sent = serve((method, path) => {
      if (path === '/mod/library/lookup') return json({ video: lookup([there]) });
      if (method === 'POST' && path === '/mod/library') return json({ item: item({ active: 1 }) });
      if (path.startsWith('/mod/library?')) return json({ items: [] });
      return undefined;
    });
    render(<LibraryPanel />);
    await lookUp('film');
    expect(sent.find((s) => s.path === '/mod/library/lookup')?.body).toEqual({ url: 'https://youtu.be/DzangoFilm1', kind: 'film' });
    expect(await screen.findByText(/Possibly in the library already/)).toBeTruthy();
    expect(screen.getByText(/Vergeben - Forgiven - 2016 · Himmlisches Kino · 80:15/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Add anyway' }));
    await vi.waitFor(() => expect(sent.some((s) => s.method === 'POST' && s.path === '/mod/library')).toBe(true));
    expect(sent.find((s) => s.method === 'POST' && s.path === '/mod/library')?.body).toMatchObject({ kind: 'film', allow_duplicate: true });
  });

  it('a duplicate the server finds for another kind than the lookup was for is shown, and the second tap adds it', async () => {
    let refused = false;
    const sent = serve((method, path) => {
      if (path === '/mod/library/lookup') return json({ video: lookup([]) });
      if (method === 'POST' && path === '/mod/library') {
        if (refused) return json({ item: item({ active: 1 }) });
        refused = true;
        return json({ error: 'possible_duplicate', duplicates: [there] }, 409);
      }
      if (path.startsWith('/mod/library?')) return json({ items: [] });
      return undefined;
    });
    render(<LibraryPanel />);
    await lookUp('song');
    fireEvent.change(screen.getByLabelText('Kind'), { target: { value: 'film' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add to library' }));
    expect(await screen.findByText(/Vergeben - Forgiven - 2016/)).toBeTruthy();
    expect(sent.filter((s) => s.method === 'POST' && s.path === '/mod/library').map((s) => (s.body as { allow_duplicate?: boolean }).allow_duplicate)).toEqual([undefined]);
    fireEvent.click(screen.getByRole('button', { name: 'Add anyway' }));
    await vi.waitFor(() => expect(sent.filter((s) => s.method === 'POST' && s.path === '/mod/library')).toHaveLength(2));
    expect((sent.filter((s) => s.method === 'POST' && s.path === '/mod/library')[1]!.body as { allow_duplicate?: boolean }).allow_duplicate).toBe(true);
  });

  it('names the other of a pair beside an item, filters for pairs, and deletes only a switched-off song or video — after a confirm', async () => {
    const items = [
      item({ duplicates: [there] }),
      item({ id: 65, yt_id: 'HimmlKino01', title: 'Vergeben - Forgiven - 2016', artist: 'Himmlisches Kino', active: 1 }),
      item({ id: 9, kind: 'bed', yt_id: null, audio: '/media/beds/stille.mp3', title: 'Stille', artist: '', active: 0 }),
    ];
    const sent = serve((method, path) => {
      if (method === 'DELETE' && path === '/mod/library/62') return json({ deleted: 62 });
      if (path.startsWith('/mod/library?')) return json({ items });
      return undefined;
    });
    render(<LibraryPanel />);
    expect(await screen.findByText('Same as “Vergeben - Forgiven - 2016”?')).toBeTruthy();
    expect(screen.getAllByRole('button', { name: 'Delete' })).toHaveLength(1);
    const row = screen.getByText('Vergeben - Forgiven mit KEVIN SORBO').closest('li')!;
    fireEvent.click(within(row).getByRole('button', { name: 'Delete' }));
    expect(within(row).getByText(/Delete it from the library for good\?/)).toBeTruthy();
    expect(sent.some((s) => s.method === 'DELETE')).toBe(false);
    fireEvent.click(within(row).getByRole('button', { name: 'Yes' }));
    await vi.waitFor(() => expect(sent.some((s) => s.method === 'DELETE' && s.path === '/mod/library/62')).toBe(true));

    fireEvent.click(screen.getByLabelText('Only possible duplicates'));
    await vi.waitFor(() => expect(sent.some((s) => s.path.startsWith('/mod/library?') && s.path.endsWith('&dupes=1'))).toBe(true));
  });
});
