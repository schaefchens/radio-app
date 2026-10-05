import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import type { ProgramRef } from '@arche/shared';
import { PrayerSheet } from '@/components/submit/PrayerSheet';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { RULES_VERSION } from '@/content/rules';

const WALL = 'Also show my request on the prayer wall, so others can pray with me.';
const AFTER = 'Keep my request on the prayer wall after the prayer hour too, so others can go on praying with me.';
const ANONYMOUS = 'Stay anonymous: no name, on air or on the prayer wall';
const prayerHour: ProgramRef = {
  id: 'prayer',
  title: { en: 'Prayer Hour', de: 'Gebetsstunde' },
  subtitle: { en: '', de: '' },
  color: '#2f7bff',
  stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } },
  allowed: ['prayer', 'intercession'],
  format: 'prayer',
  voicedBy: ['openai'],
};

afterEach(() => {
  vi.unstubAllGlobals();
  useRadio.setState((s) => ({ engine: { ...s.engine, program: null } }));
});

describe('the prayer request sheet', () => {
  it('asks again for the next request: the wall box never starts ticked', async () => {
    useSettings.setState({ lang: 'en', rules: RULES_VERSION });
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
    fireEvent.change(within(dialog()).getByLabelText('Your first name'), { target: { value: 'Ruth' } });
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

  it('named by default — first name and place with what happens to them; "Stay anonymous" hides both and sends neither, a first name is needed otherwise', async () => {
    useSettings.setState({ lang: 'en', rules: RULES_VERSION });
    const fetch = vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    render(
      <MemoryRouter>
        <PrayerSheet open onClose={() => undefined} onRecord={() => undefined} />
      </MemoryRouter>,
    );
    const dialog = screen.getByRole('dialog', { hidden: true });
    expect(within(dialog).queryByText(/In this prayer hour/)).toBeNull();
    const anonymous = within(dialog).getByLabelText(ANONYMOUS) as HTMLInputElement;
    expect(anonymous.checked).toBe(false);
    expect(within(dialog).getByText('Your first name and place are read out with your request and shown with it on the prayer wall.')).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText('Your prayer request'), { target: { value: 'Please pray for my sister.' } });
    const send = within(dialog).getByRole('button', { name: 'Send' }) as HTMLButtonElement;
    expect(send.disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText('Your first name'), { target: { value: 'Ruth' } });
    fireEvent.change(within(dialog).getByLabelText(/Where are you from/), { target: { value: 'Lagos' } });
    expect(send.disabled).toBe(false);
    fireEvent.click(anonymous);
    expect(within(dialog).queryByLabelText('Your first name')).toBeNull();
    expect(within(dialog).queryByText(/Your first name and place are read out/)).toBeNull();
    fireEvent.click(send);
    await within(dialog).findByText(/Thank you!/);
    const [, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
    expect(JSON.parse(String(init.body))).toMatchObject({ text: 'Please pray for my sister.', name: '', place: '' });
  });

  it('in a prayer hour: every request goes on the hour\'s wall once read out — the box is for the wall after the hour, never ticked in advance', async () => {
    useSettings.setState({ lang: 'en', rules: RULES_VERSION });
    useRadio.setState((s) => ({ engine: { ...s.engine, channel: 'main', program: prayerHour } }));
    const fetch = vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    render(
      <MemoryRouter>
        <PrayerSheet open onClose={() => undefined} onRecord={() => undefined} />
      </MemoryRouter>,
    );
    const dialog = screen.getByRole('dialog', { hidden: true });
    expect(within(dialog).getByText(/In this prayer hour every request is shown on the prayer wall from when it is read out/)).toBeTruthy();
    expect(within(dialog).queryByLabelText(WALL)).toBeNull();
    const after = within(dialog).getByLabelText(AFTER) as HTMLInputElement;
    expect(after.checked).toBe(false);
    fireEvent.change(within(dialog).getByLabelText('Your prayer request'), { target: { value: 'Please pray for my sister.' } });
    fireEvent.change(within(dialog).getByLabelText('Your first name'), { target: { value: 'Ruth' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send' }));
    await within(dialog).findByText(/Thank you!/);
    const [, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
    expect(JSON.parse(String(init.body))).toMatchObject({ channel: 'main', name: 'Ruth', consent_air: false });
  });

  it('the first post asks for the community rules: Send waits for the tick, the next post does not ask', async () => {
    useSettings.setState({ lang: 'en', rules: 0 });
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 })));
    render(
      <MemoryRouter>
        <PrayerSheet open onClose={() => undefined} onRecord={() => undefined} />
      </MemoryRouter>,
    );
    const dialog = screen.getByRole('dialog', { hidden: true });
    fireEvent.change(within(dialog).getByLabelText('Your prayer request'), { target: { value: 'Please pray for my sister.' } });
    fireEvent.click(within(dialog).getByLabelText(ANONYMOUS));
    const send = within(dialog).getByRole('button', { name: 'Send' }) as HTMLButtonElement;
    expect(send.disabled).toBe(true);
    fireEvent.click(within(dialog).getByLabelText(/I accept the community rules/));
    expect(send.disabled).toBe(false);
    fireEvent.click(send);
    await within(dialog).findByText(/Thank you!/);
    expect(useSettings.getState().rules).toBe(RULES_VERSION);
  });
});
