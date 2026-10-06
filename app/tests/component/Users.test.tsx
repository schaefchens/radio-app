import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@/i18n';
import { UsersPanel } from '@/components/mod/UsersPanel';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';

/**
 * /mod › Users: a role only for an account with a passphrase (the server
 * refuses any other, Identities::setRole) — the list says so, instead of a
 * dropdown whose choices cannot be picked.
 */

const json = (data: unknown, status = 200): Response => new Response(JSON.stringify(data), { status });
const user = (id: string, name: string, claimed: boolean, role = 'listener') => ({ id, name, country: 'DE', lang: 'de', role, claimed, banned: false, lastSeen: Date.now(), devices: 1 });

beforeEach(() => {
  useSettings.setState({ lang: 'en' });
  useSession.setState({ identity: { id: 'me0000001', role: 'admin' } as never });
});
afterEach(() => {
  vi.unstubAllGlobals();
});

describe('/mod: users and their roles', () => {
  it('a role for an account with a passphrase; the others are told what it takes', async () => {
    const patched: unknown[] = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (init?.method === 'PATCH') {
        patched.push({ url, body: JSON.parse(String(init.body)) });
        return json({ user: null });
      }
      return json({ users: [user('anna00001', 'Anna', true), user('ben000001', 'Ben', false)] });
    }));
    render(<UsersPanel />);
    expect(await screen.findByText(/only for an account with a passphrase/)).toBeTruthy();
    const [anna, ben] = screen.getAllByRole('listitem');
    const benRole = within(ben!).getByRole('combobox', { name: 'Role' }) as HTMLSelectElement;
    expect(benRole.disabled).toBe(true);
    expect(within(ben!).getByText('A role needs their passphrase first (Profile › Create a passphrase).')).toBeTruthy();
    const annaRole = within(anna!).getByRole('combobox', { name: 'Role' }) as HTMLSelectElement;
    expect(annaRole.disabled).toBe(false);
    expect(within(anna!).queryByText(/needs their passphrase/)).toBeNull();
    fireEvent.change(annaRole, { target: { value: 'moderator' } });
    await waitFor(() => expect(patched).toEqual([{ url: '/api/mod/users/anna00001', body: { role: 'moderator' } }]));
  });
});
