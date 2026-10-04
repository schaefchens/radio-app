import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import type { Recorder } from '@/components/submit/useRecorder';
import { PraySheet } from '@/components/submit/PraySheet';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { RULES_VERSION } from '@/content/rules';

// The microphone, MediaRecorder and the MP3 worker are the browser's: a recorder that has a recording, or not.
const recorder: { current: Partial<Recorder> } = { current: {} };
vi.mock('@/components/submit/useRecorder', () => ({
  useRecorder: (): Recorder => ({
    recording: false, elapsed: 0, blob: null, preview: null, micError: null,
    start: async () => undefined, stop: () => undefined, cleanup: () => undefined, reset: () => undefined,
    ...recorder.current,
  }),
}));
vi.mock('@/lib/recordingEncoder', () => ({ toMp3: async () => ({ mp3: new Blob(['mp3'], { type: 'audio/mpeg' }), ms: 20_000 }) }));

const CONSENT = 'I agree that my prayer is aired on Arche Radio, word for word, with my first name and place if I give them.';

function sheet(open = true) {
  return (
    <MemoryRouter>
      <PraySheet open={open} onClose={() => undefined} />
    </MemoryRouter>
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
  recorder.current = {};
});

describe('the Pray sheet', () => {
  useSettings.setState({ lang: 'en', rules: RULES_VERSION });
  useRadio.setState((s) => ({ engine: { ...s.engine, channel: 'main' } }));

  it('opens on the recorder; written instead, the prayer goes to be read out word for word — only with the yes to air it', async () => {
    const fetch = vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    render(sheet());
    const dialog = () => screen.getByRole('dialog', { hidden: true });
    expect(within(dialog()).getByRole('button', { name: 'Start recording' })).toBeTruthy();
    expect(within(dialog()).getByText(/played on air as you recorded it/)).toBeTruthy();
    fireEvent.click(within(dialog()).getByRole('button', { name: 'Type it instead' }));
    fireEvent.change(within(dialog()).getByLabelText('Your prayer'), { target: { value: 'Lord, be with Maria and her mother.' } });
    const send = within(dialog()).getByRole('button', { name: 'Send' }) as HTMLButtonElement;
    expect(send.disabled).toBe(true);
    const consent = within(dialog()).getByLabelText(CONSENT) as HTMLInputElement;
    expect(consent.checked).toBe(false);
    fireEvent.click(consent);
    fireEvent.click(send);
    await within(dialog()).findByText(/Thank you!/);
    const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/api/submissions/intercession');
    expect(JSON.parse(String(init.body))).toMatchObject({ channel: 'main', text: 'Lord, be with Maria and her mother.', lang: 'en', consent_air: true });
  });

  it('a spoken prayer goes up as an MP3 of the prayer time; the next one must be agreed to again', async () => {
    recorder.current = { blob: new Blob(['webm'], { type: 'audio/webm' }), preview: 'blob:x' };
    const fetch = vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    const { rerender } = render(sheet());
    const dialog = () => screen.getByRole('dialog', { hidden: true });
    fireEvent.click(within(dialog()).getByLabelText(CONSENT));
    fireEvent.click(within(dialog()).getByRole('button', { name: 'Send' }));
    const done = await within(dialog()).findByText(/Thank you!/);
    const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
    const form = init.body as FormData;
    expect([url, form.get('type'), form.get('consent_air'), form.get('channel')]).toEqual(['/api/submissions/audio', 'intercession', '1', 'main']);
    expect(form.get('audio')).toBeInstanceOf(Blob);
    fireEvent.click(within(done.parentElement!).getByRole('button', { name: 'Close' }));
    rerender(sheet(false));
    rerender(sheet(true));
    expect((within(dialog()).getByLabelText(CONSENT) as HTMLInputElement).checked).toBe(false);
  });

  it('the first post asks for the community rules', () => {
    useSettings.setState({ rules: 0 });
    recorder.current = { blob: new Blob(['webm'], { type: 'audio/webm' }) };
    render(sheet());
    const dialog = screen.getByRole('dialog', { hidden: true });
    fireEvent.click(within(dialog).getByLabelText(CONSENT));
    const send = within(dialog).getByRole('button', { name: 'Send' }) as HTMLButtonElement;
    expect(send.disabled).toBe(true);
    fireEvent.click(within(dialog).getByLabelText(/I accept the community rules/));
    expect(send.disabled).toBe(false);
    useSettings.setState({ rules: RULES_VERSION });
  });
});
