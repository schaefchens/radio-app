import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import '@/i18n';
import type { Cite, ProgramRef, TimelineItem } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';
import { StageVisual } from '@/components/stage/StageVisual';
import { useSettings } from '@/store/settings';

const live: ProgramRef = {
  id: 'live',
  title: { en: 'ARCHE Live', de: 'ARCHE Live' },
  subtitle: { en: '', de: '' },
  color: '#2f7bff',
  stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } },
  allowed: ['song'],
  format: 'music',
  voicedBy: ['worker'],
};
const words = 'Paul Gerhardt wrote the next hymn in 1653.';

function engine(cite: Cite | null): EngineState {
  const item: TimelineItem = {
    id: 'h1', type: 'host', start: 0, dur: 12_000, p: 'live', kind: 'break', audio: { en: '/media/host/b.mp3' },
    text: { en: words }, voices: [], prayers: [], notice: null, cite, host: null,
  };
  return { ...initialState('main'), joined: true, program: live, item, mode: 'host', hostText: words };
}

// A fact the host tells comes from the web: shown with its words, it is shown with its page.
describe('a fact on the stage', () => {
  useSettings.setState({ lang: 'en' });

  it('links its source under the host\'s words, opening outside the app', () => {
    render(<StageVisual engine={engine({ title: 'wikipedia.org', url: 'https://en.wikipedia.org/wiki/Befiehl_du_deine_Wege' })} />);
    expect(screen.getByText(words)).toBeTruthy();
    const link = screen.getByRole('link', { name: 'Source: wikipedia.org' });
    expect(link.getAttribute('href')).toBe('https://en.wikipedia.org/wiki/Befiehl_du_deine_Wege');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('names a site without a title by its address, and shows no link for a moment that told no fact', () => {
    const { unmount } = render(<StageVisual engine={engine({ title: '', url: 'https://www.gerth.de/person/langner-timo.html' })} />);
    expect(screen.getByRole('link', { name: 'Source: gerth.de' })).toBeTruthy();
    unmount();
    render(<StageVisual engine={engine(null)} />);
    expect(screen.queryAllByRole('link')).toEqual([]);
  });
});
