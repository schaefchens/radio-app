import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import i18n, { refusalText } from '@/i18n';
import { PrayerSheet } from '@/components/submit/PrayerSheet';
import { localTime } from '@/lib/format';
import { useSettings } from '@/store/settings';
import { RULES_VERSION } from '@/content/rules';

/**
 * A send over a listener's limits (Submissions::limit) says which limit and
 * what frees it — not "too many attempts, please wait a little", which sent
 * listeners into waiting for nothing or for a day.
 */

afterEach(() => {
  vi.unstubAllGlobals();
  useSettings.setState({ lang: 'en' });
});

describe('a refused send', () => {
  it('in the form: so many are waiting already, and what frees a place', async () => {
    useSettings.setState({ lang: 'en', rules: RULES_VERSION });
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: 'rate_limited', limit: 'waiting', kind: 'prayer', max: 4 }), { status: 429 })));
    render(
      <MemoryRouter>
        <PrayerSheet open onClose={() => undefined} onRecord={() => undefined} />
      </MemoryRouter>,
    );
    const dialog = screen.getByRole('dialog', { hidden: true });
    fireEvent.change(within(dialog).getByLabelText('Your prayer request'), { target: { value: 'Please pray for my sister.' } });
    fireEvent.change(within(dialog).getByLabelText('Your first name'), { target: { value: 'Ruth' } });
    fireEvent.change(within(dialog).getByLabelText(/Where are you from/), { target: { value: 'Lagos' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send' }));
    expect(await within(dialog).findByText('You already have 4 waiting — no more prayer requests at once. As soon as one has aired or been declined, you can send the next.')).toBeTruthy();
  });

  it('one waiting at once, in German; the day\'s limit with the time it frees; an older server\'s answer as before', async () => {
    await i18n.changeLanguage('de');
    expect(refusalText('rate_limited', { limit: 'waiting', kind: 'video', max: 1 }, 0)).toBe(
      'Dein Beitrag wartet noch – mehr Videovorschläge gehen nicht auf einmal. Sobald er gesendet oder abgelehnt ist, kannst du den nächsten schicken.',
    );
    await i18n.changeLanguage('en');
    const now = new Date(2026, 9, 12, 20, 0).getTime();
    const until = new Date(2026, 9, 13, 19, 42).getTime();
    expect(refusalText('rate_limited', { limit: 'day', kind: 'song', max: 20, until }, now)).toBe(
      `You’ve sent 20 song requests in the last 24 hours — that’s the most for one day. Possible again tomorrow from ${localTime(until, 'en')}.`,
    );
    expect(refusalText('rate_limited', {}, now)).toBe('Too many attempts — please wait a little.');
    expect(refusalText('rate_limited', { limit: 'waiting', kind: 'chat', max: 2 }, now)).toBe('Too many attempts — please wait a little.');
    expect(refusalText('closed', {}, now)).toBe('Submissions are closed for this program.');
  });
});
