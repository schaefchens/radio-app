import { afterEach, describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import '@/i18n';
import type { ProgramRef, TimelineItem, WallEntry } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';
import { StageVisual } from '@/components/stage/StageVisual';
import { useSettings } from '@/store/settings';
import { useSheets } from '@/store/sheets';
import { useReactions } from '@/store/reactions';

const prayerHour: ProgramRef = {
  id: 'prayer',
  title: { en: 'Prayer Hour', de: 'Gebetsstunde' },
  subtitle: { en: '', de: '' },
  color: '#2f7bff',
  stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } },
  allowed: ['prayer'],
  format: 'prayer',
};
const mother: WallEntry = { id: 'pa', text: 'Please pray for my mother.', at: 2 };
const peace: WallEntry = { id: 'pb', text: 'Pray for peace in our town.', at: 1 };
const bed: TimelineItem = {
  id: 'b1', type: 'bed', start: 0, dur: 60_000, p: 'prayer', audio: '/media/beds/pad.mp3', offset: 0,
  label: { en: 'What can we pray for?', de: 'Wofür dürfen wir beten?' },
};
const silence: TimelineItem = { id: 's1', type: 'silence', start: 0, dur: 60_000, p: 'prayer', label: { en: 'Silent prayer', de: 'Stilles Gebet' } };
const moment: TimelineItem = {
  id: 'h1', type: 'host', start: 0, dur: 30_000, p: 'prayer', kind: 'prayer', audio: { en: '/media/host/p.mp3' },
  text: { en: 'Lord, we bring before you a request on our prayer wall…' }, voices: [], prayers: ['pa'],
};

function stage(item: TimelineItem, mode: EngineState['mode'], extra: Partial<EngineState> = {}): EngineState {
  return { ...initialState('main'), joined: true, program: prayerHour, item, mode, wall: [mother, peace], ...extra };
}

afterEach(() => {
  useSheets.setState({ open: null });
  useReactions.setState({ marks: {} });
});

describe('the stage in a prayer hour', () => {
  useSettings.setState({ lang: 'en' });

  it('during the collection: what we pray for, a button to share a request, the newest request', () => {
    render(<StageVisual engine={stage(bed, 'bed')} />);
    expect(screen.getByText('What can we pray for?')).toBeTruthy();
    expect(screen.getByText(mother.text)).toBeTruthy();
    expect(screen.queryByText(peace.text)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Share a prayer request' }));
    expect(useSheets.getState().open).toBe('prayer');
  });

  it('in silent prayer: one request to pray along with, and 🙏 stays pressed', () => {
    render(<StageVisual engine={stage(silence, 'silence', { wall: [mother] })} />);
    expect(screen.getByText('Silent prayer')).toBeTruthy();
    expect(screen.getByText(mother.text)).toBeTruthy();
    const pray = screen.getByRole('button', { name: /I prayed/ });
    expect(pray.getAttribute('aria-pressed')).toBe('false');
    fireEvent.click(pray);
    expect(pray.getAttribute('aria-pressed')).toBe('true');
    expect(useReactions.getState().marks['voice:pa']?.pray).toBe(true);
  });

  it('with nothing on the wall, silent prayer invites a request', () => {
    render(<StageVisual engine={stage(silence, 'silence', { wall: [] })} />);
    expect(screen.getByRole('button', { name: 'Share a prayer request' })).toBeTruthy();
  });

  it('while a request on the wall is on air, that request instead of the host\'s long text', () => {
    render(<StageVisual engine={stage(moment, 'host', { praying: ['pa'], hostText: 'A prayer request: please pray for my mother, she is in hospital…' })} />);
    expect(screen.getByText('Prayer request')).toBeTruthy();
    expect(screen.getByText(mother.text)).toBeTruthy();
    expect(screen.queryByText(/A prayer request: please/)).toBeNull();
    // The host never prays: the stage never says it does.
    expect(screen.queryByText(/is praying/)).toBeNull();
  });

  it('a request taken off the wall since: the host as usual', () => {
    render(<StageVisual engine={stage(moment, 'host', { praying: ['px'], hostText: 'Take a moment to pray for this request.' })} />);
    expect(screen.getByText('Take a moment to pray for this request.')).toBeTruthy();
  });

  it('on the compact stage of the other pages: no sheet to open there, so no button', () => {
    render(<StageVisual engine={stage(bed, 'bed')} compact />);
    expect(screen.queryByRole('button', { name: 'Share a prayer request' })).toBeNull();
    expect(screen.getByText(mother.text)).toBeTruthy();
  });

  it('outside a prayer hour, a moment of silence looks as before', () => {
    render(<StageVisual engine={stage(silence, 'silence', { program: { ...prayerHour, format: 'music' } })} />);
    expect(screen.getByText('A moment of silence')).toBeTruthy();
    expect(screen.queryByText(mother.text)).toBeNull();
  });
});
