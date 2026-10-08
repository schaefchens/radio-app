import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import '@/i18n';
import { StatusPanel } from '@/components/mod/StatusPanel';
import { setupFileName } from '@/components/mod/setupFile';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';

/**
 * Admins download the station's setup from Status, to try the program on a
 * local stack (`npm run setup:import`): a file named by its day, made from
 * what the server answers; moderators never see the card (the server
 * refuses them too).
 */

const json = (data: unknown, status = 200): Response => new Response(JSON.stringify(data), { status });

const status = {
  now: Date.now(), lastTick: null, channels: [], jobs: {}, hostBreaks: [], usage: [], spentTodayUsd: 0, budgetUsd: 5,
  ai: { text: 'stub', hostModel: '', moderationModel: '', voice: 'stub' }, realtime: { driver: 'static', slots: [], nodes: [] }, audit: [],
};
/** 2026-10-08 09:30 UTC */
const setup = {
  format: 'arche-station-setup', version: 1, schema: 15, site: 'https://radio.schaefchens.de', exported: 1791451800,
  tables: { channels: [{}], programs: [{}, {}, {}], library_items: [{}, {}], hosts: [{}] }, media: [],
};

function serve(answers: Record<string, () => Response>): string[] {
  const asked: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      const path = url.replace(/^\/api/, '');
      asked.push(path);
      return answers[path]?.() ?? json({ error: 'not_found' }, 404);
    }),
  );
  return asked;
}

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
});
afterEach(() => {
  useSession.setState({ identity: null });
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('/mod › Status: the station setup', () => {
  it('an admin downloads it as a file named by its day', async () => {
    useSession.setState({ identity: { id: 'me0000001', role: 'admin', claimed: true } as never });
    const asked = serve({ '/mod/status': () => json(status), '/mod/export': () => json(setup) });
    const blobs: Blob[] = [];
    const create = vi.fn((b: Blob) => {
      blobs.push(b);
      return 'blob:setup';
    });
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() }));
    const clicked: { href: string; download: string }[] = [];
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicked.push({ href: this.getAttribute('href') ?? '', download: this.download });
    });

    render(<StatusPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Download' }));
    expect(await screen.findByText('Downloaded arche-setup-20261008.json — programs: 3, library items: 2, hosts: 1.')).toBeTruthy();
    expect(asked).toContain('/mod/export');
    expect(clicked).toEqual([{ href: 'blob:setup', download: 'arche-setup-20261008.json' }]);
    expect(blobs[0]?.type).toBe('application/json');
    expect(JSON.parse(await (blobs[0] as Blob).text())).toEqual(setup);
    // The link does not stay in the page.
    expect(document.querySelector('a[download]')).toBeNull();
  });

  it('says what went wrong', async () => {
    useSession.setState({ identity: { id: 'me0000001', role: 'admin', claimed: true } as never });
    serve({ '/mod/status': () => json(status), '/mod/export': () => json({ error: 'forbidden' }, 403) });
    render(<StatusPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Download' }));
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Download' })).toHaveProperty('disabled', false);
  });

  it('moderators do not see it', async () => {
    useSession.setState({ identity: { id: 'me0000002', role: 'moderator', claimed: true } as never });
    serve({ '/mod/status': () => json(status) });
    render(<StatusPanel />);
    expect(await screen.findByText('Last tick')).toBeTruthy();
    expect(screen.queryByText('Station setup')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Download' })).toBeNull();
  });

  it('names the file by the UTC day it was made', () => {
    expect(setupFileName(1791451800)).toBe('arche-setup-20261008.json');
    expect(setupFileName(Date.UTC(2026, 11, 31, 23, 59) / 1000)).toBe('arche-setup-20261231.json');
  });
});
