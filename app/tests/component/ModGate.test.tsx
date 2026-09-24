import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import '@/i18n';
import { ModRoutes } from '@/routes/mod/ModRoutes';
import { useSession, type IdentityView } from '@/store/session';
import { useSettings } from '@/store/settings';

const config = { pulse: 120, langs: ['en', 'de'], imprint: '', privacy: '', realtime: true, setupNeeded: false };
const who = (role: IdentityView['role'], claimed = true): IdentityView => ({ id: 'abc1234567', name: 'Mo', country: 'DE', lang: 'en', role, claimed, banned: false });

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/" element={<p>home page</p>} />
        <Route path="/mod/*" element={<ModRoutes />} />
      </Routes>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
  // The panels fetch; the gate is what is under test, so every call is refused.
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: 'forbidden' }), { status: 403 })));
});
afterEach(() => {
  vi.unstubAllGlobals();
  useSession.setState({ identity: null, config: null, apiDown: false });
});

describe('/mod gate', () => {
  it('sends listeners home', () => {
    useSession.setState({ identity: who('listener'), config });
    renderAt('/mod/library');
    expect(screen.getByText('home page')).toBeTruthy();
  });

  it('a moderator role without a passphrase does not count', () => {
    useSession.setState({ identity: who('moderator', false), config });
    renderAt('/mod');
    expect(screen.getByText('home page')).toBeTruthy();
  });

  it('moderators get the area; users and channels only for admins', () => {
    useSession.setState({ identity: who('moderator'), config });
    const { unmount } = renderAt('/mod');
    expect(screen.getByRole('link', { name: 'Library' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Users' })).toBeNull();
    unmount();
    useSession.setState({ identity: who('admin'), config });
    renderAt('/mod');
    expect(screen.getByRole('link', { name: 'Users' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Channels' })).toBeTruthy();
  });

  it('waits for the session before deciding', () => {
    useSession.setState({ identity: null, config: null, apiDown: false });
    renderAt('/mod');
    expect(screen.queryByText('home page')).toBeNull();
  });
});
