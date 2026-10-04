import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@/i18n';
import type { Recorder } from '@/components/submit/useRecorder';
import { VideoRequestSheet } from '@/components/submit/VideoRequestSheet';
import { RecordSheet } from '@/components/submit/RecordSheet';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { RULES_VERSION } from '@/content/rules';

// The microphone and the MP3 worker are the browser's: a recorder that has a recording.
vi.mock('@/components/submit/useRecorder', () => ({
  useRecorder: (): Recorder => ({
    recording: false, elapsed: 0, blob: new Blob(['webm'], { type: 'audio/webm' }), preview: null, micError: null,
    start: async () => undefined, stop: () => undefined, cleanup: () => undefined, reset: () => undefined,
  }),
}));
vi.mock('@/lib/recordingEncoder', () => ({ toMp3: async () => ({ mp3: new Blob(['mp3'], { type: 'audio/mpeg' }), ms: 20_000 }) }));

const ANONYMOUS = 'Stay anonymous: no name on air';

function sent() {
  const fetch = vi.fn(async () => new Response(JSON.stringify({ submission: { id: 'abc' } }), { status: 200 }));
  vi.stubGlobal('fetch', fetch);
  return () => fetch.mock.calls[0] as unknown as [string, RequestInit];
}

afterEach(() => vi.unstubAllGlobals());

// Whoever ticks "Stay anonymous" must not be named on air: the sheet sends neither name nor place.
describe('"Stay anonymous" on every submission form', () => {
  useSettings.setState({ lang: 'en', rules: RULES_VERSION });
  // The video sheet sends only a kind the minute file lists as open.
  useRadio.setState((s) => ({ engine: { ...s.engine, channel: 'main', submissions: { song: 'open', preaching: 'open' } } }));

  for (const kind of ['song', 'video'] as const) {
    it(`a ${kind}: Send waits for a first name or the box; ticked, neither name nor place goes up`, async () => {
      const call = sent();
      render(
        <MemoryRouter>
          <VideoRequestSheet kind={kind} open onClose={() => undefined} />
        </MemoryRouter>,
      );
      const dialog = screen.getByRole('dialog', { hidden: true });
      fireEvent.change(within(dialog).getByLabelText('YouTube link'), { target: { value: 'https://youtu.be/AbCdEfGhIjK' } });
      const send = within(dialog).getByRole('button', { name: 'Send' }) as HTMLButtonElement;
      expect(send.disabled).toBe(true);
      fireEvent.change(within(dialog).getByLabelText('Your first name'), { target: { value: 'Jonas' } });
      fireEvent.change(within(dialog).getByLabelText(/Where are you from/), { target: { value: 'Hamburg' } });
      expect(send.disabled).toBe(false);
      fireEvent.click(within(dialog).getByLabelText(ANONYMOUS));
      expect(within(dialog).queryByLabelText('Your first name')).toBeNull();
      fireEvent.click(send);
      await within(dialog).findByText(/Thank you!/);
      const [url, init] = call();
      expect(url).toBe(`/api/submissions/${kind}`);
      expect(JSON.parse(String(init.body))).toMatchObject({
        url: 'https://youtu.be/AbCdEfGhIjK',
        name: '',
        place: '',
        ...(kind === 'video' ? { type: 'preaching' } : {}),
      });
    });
  }

  it('a recording: named by default, anonymous when ticked — and the choice stays for the next one', async () => {
    const call = sent();
    const sheet = (open: boolean) => (
      <MemoryRouter>
        <RecordSheet open={open} onClose={() => undefined} />
      </MemoryRouter>
    );
    const { rerender } = render(sheet(true));
    const dialog = () => screen.getByRole('dialog', { hidden: true });
    expect(within(dialog()).getByText('Your first name and place are named on air with your recording.')).toBeTruthy();
    fireEvent.click(within(dialog()).getByLabelText('I agree that this is broadcast on Arche Radio.'));
    const send = within(dialog()).getByRole('button', { name: 'Send' }) as HTMLButtonElement;
    expect(send.disabled).toBe(true);
    fireEvent.click(within(dialog()).getByLabelText(ANONYMOUS));
    expect(send.disabled).toBe(false);
    fireEvent.click(send);
    const done = await within(dialog()).findByText(/Thank you!/);
    const form = call()[1].body as FormData;
    expect([form.get('type'), form.get('name'), form.get('place')]).toEqual(['story', '', '']);
    fireEvent.click(within(done.parentElement!).getByRole('button', { name: 'Close' }));
    rerender(sheet(false));
    rerender(sheet(true));
    expect((within(dialog()).getByLabelText(ANONYMOUS) as HTMLInputElement).checked).toBe(true);
  });
});
