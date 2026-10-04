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
    text: { en: 'More from Grace Chapel: the links are in the app now.' }, voices: [], prayers: [], notice,
  };
}

function engine(item: TimelineItem): EngineState {
  return { ...initialState('main'), joined: true, program: sermons, item, mode: 'host', hostText: 'More from Grace Chapel: the links are in the app now.' };
}

// After a video of a group that wants it, the host's word comes with who it was from and where to find more.
describe('a group\'s notice on the stage', () => {
  useSettings.setState({ lang: 'en' });

  it('shows who it was from, a few words and links that open outside the app — another site by its name', () => {
    render(<StageVisual engine={engine(hostWith(grace))} />);
    expect(screen.getByText('More from Grace Chapel')).toBeTruthy();
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
    // The host's words still show — on a phone the stylesheet hides them for the links.
    expect(screen.getByText('More from Grace Chapel: the links are in the app now.')).toBeTruthy();
  });

  it('the compact stage of other pages shows no notice', () => {
    render(<StageVisual engine={engine(hostWith(grace))} compact />);
    expect(screen.queryByText('More from Grace Chapel')).toBeNull();
    expect(screen.queryAllByRole('link')).toEqual([]);
  });

  it('a host moment without a notice is the host as before', () => {
    render(<StageVisual engine={engine(hostWith(null))} />);
    expect(screen.queryByText('More from Grace Chapel')).toBeNull();
    expect(screen.queryAllByRole('link')).toEqual([]);
    expect(screen.getByText('More from Grace Chapel: the links are in the app now.')).toBeTruthy();
  });
});
