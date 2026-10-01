import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import { PrayerSheet } from '@/components/submit/PrayerSheet';
import { useSettings } from '@/store/settings';

const WALL = 'Also show my request on the prayer wall, without my name, so others can pray with me.';

afterEach(() => vi.unstubAllGlobals());

describe('the prayer request sheet', () => {
  it('asks again for the next request: the wall box never starts ticked', async () => {
    useSettings.setState({ lang: 'en' });
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 })));
    const sheet = (open: boolean) => (
      <MemoryRouter>
        <PrayerSheet open={open} onClose={() => undefined} onRecord={() => undefined} />
      </MemoryRouter>
    );
    const { rerender } = render(sheet(true));
    const dialog = () => screen.getByRole('dialog', { hidden: true });
    expect((within(dialog()).getByLabelText(WALL) as HTMLInputElement).checked).toBe(false);

    fireEvent.change(within(dialog()).getByLabelText('Your prayer request'), { target: { value: 'Please pray for my sister.' } });
    fireEvent.click(within(dialog()).getByLabelText(WALL));
    fireEvent.click(within(dialog()).getByRole('button', { name: 'Send' }));
    // The sheet stays mounted when it closes (it is only moved away).
    const done = await within(dialog()).findByText(/Thank you!/);
    fireEvent.click(within(done.parentElement!).getByRole('button', { name: 'Close' }));
    rerender(sheet(false));
    rerender(sheet(true));

    expect((within(dialog()).getByLabelText(WALL) as HTMLInputElement).checked).toBe(false);
    expect((within(dialog()).getByLabelText('Your prayer request') as HTMLTextAreaElement).value).toBe('');
  });
});
