import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import '@/i18n';
import type { GroupNotice, ProgramRef, TimelineItem } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';
import { StageVisual } from '@/components/stage/StageVisual';
import { useSettings } from '@/store/settings';

const sermons: ProgramRef = {
  id: 'predigt',
  title: { en: 'Sermons', de: 'Predigten' },
  subtitle: { en: '', de: '' },
  color: '#b8862b',
  stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } },
  allowed: ['preaching'],
  format: 'preaching',
  voicedBy: ['openai'],
};
const grace: GroupNotice = {
  name: 'Grace Chapel',
  text: { en: 'A church in Accra, Ghana.', de: 'Eine Gemeinde in Accra, Ghana.' },
  links: [
    { kind: 'youtube', url: 'https://www.youtube.com/@gracechapel' },
    { kind: 'website', url: 'https://gracechapel.example' },
    { kind: 'other', url: 'https://www.podcasts.example/grace' },
  ],
};

function hostWith(notice: GroupNotice | null): TimelineItem {
  return {
    id: 'h1', type: 'host', start: 0, dur: 18_000, p: 'predigt', kind: 'break', audio: { en: '/media/host/b.mp3' },
    text: { en: 'That was Grace Chapel, a church in Accra.' }, voices: [], prayers: [], notice, host: null,
  };
}

function engine(item: TimelineItem): EngineState {
  return { ...initialState('main'), joined: true, program: sermons, item, mode: 'host', hostText: 'That was Grace Chapel, a church in Accra.' };
}

// After a video of a group that wants it, the host speaks about them — and they fill the stage.
describe('a group on the stage', () => {
  useSettings.setState({ lang: 'en' });

  it('is the stage while the host speaks about them: their name big, a few words and links that open outside the app — another site by its name', () => {
    render(<StageVisual engine={engine(hostWith(grace))} />);
    expect(screen.getByText('More from')).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Grace Chapel' })).toBeTruthy();
    expect(screen.getByText('A church in Accra, Ghana.')).toBeTruthy();
    const links = screen.getAllByRole('link');
    expect(links.map((a) => [a.textContent, a.getAttribute('href')])).toEqual([
      ['YouTube', 'https://www.youtube.com/@gracechapel'],
      ['Website', 'https://gracechapel.example'],
      ['podcasts.example', 'https://www.podcasts.example/grace'],
    ]);
    for (const a of links) {
      expect(a.getAttribute('target')).toBe('_blank');
      expect(a.getAttribute('rel')).toBe('noopener noreferrer');
    }
    // The host, small, is still the one speaking; their words are about the group, which the stage shows instead.
    expect(screen.getByText(/ is speaking$/)).toBeTruthy();
    expect(screen.queryByText('That was Grace Chapel, a church in Accra.')).toBeNull();
  });

  it('the compact stage of other pages shows the host as always, no group', () => {
    render(<StageVisual engine={engine(hostWith(grace))} compact />);
    expect(screen.queryByRole('heading', { name: 'Grace Chapel' })).toBeNull();
    expect(screen.queryAllByRole('link')).toEqual([]);
    expect(screen.getByText('That was Grace Chapel, a church in Accra.')).toBeTruthy();
  });

  it('a host moment without a notice is the host as before', () => {
    render(<StageVisual engine={engine(hostWith(null))} />);
    expect(screen.queryByText('More from')).toBeNull();
    expect(screen.queryAllByRole('link')).toEqual([]);
    expect(screen.getByText('That was Grace Chapel, a church in Accra.')).toBeTruthy();
  });
});
