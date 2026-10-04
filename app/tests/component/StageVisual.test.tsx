import { afterEach, describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import '@/i18n';
import type { ProgramRef, TimelineItem, WallEntry } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';
import { StageVisual } from '@/components/stage/StageVisual';
import { useSettings } from '@/store/settings';
import { useSheets } from '@/store/sheets';

const prayerHour: ProgramRef = {
  id: 'prayer',
  title: { en: 'Prayer Hour', de: 'Gebetsstunde' },
  subtitle: { en: '', de: '' },
  color: '#2f7bff',
  stage: { mode: 'ambient', image: null, tagline: { en: '', de: '' } },
  allowed: ['prayer', 'intercession'],
  format: 'prayer',
};
const mother: WallEntry = { id: 'pa', text: 'Please pray for my mother.', at: 4 };
const peace: WallEntry = { id: 'pb', text: 'Pray for peace in our town.', at: 3 };
const exams: WallEntry = { id: 'pc', text: 'Pray for my exams.', at: 2 };
const job: WallEntry = { id: 'pd', text: 'Pray for my new job.', at: 1 };
const openDoors: WallEntry = {
  id: 'po', text: 'Beten wir für die Christen in Nigeria.', at: 5, source: 'Open Doors · Nigeria', texts: { en: 'Let us pray for the Christians in Nigeria.' },
};
const bed: TimelineItem = {
  id: 'b1', type: 'bed', start: 0, dur: 60_000, p: 'prayer', audio: '/media/beds/pad.mp3', offset: 0,
  label: { en: 'What can we pray for?', de: 'Wofür dürfen wir beten?' },
};
const silence: TimelineItem = { id: 's1', type: 'silence', start: 0, dur: 60_000, p: 'prayer', label: { en: 'Prayer time', de: 'Gebetszeit' } };
const reading: TimelineItem = {
  id: 'h1', type: 'host', start: 0, dur: 12_000, p: 'prayer', kind: 'reading', audio: { en: '/media/host/r.mp3' },
  text: { en: 'A prayer request: Please pray for my mother.' }, voices: [], prayers: ['pa'],
};
const written: TimelineItem = {
  id: 'h2', type: 'host', start: 0, dur: 12_000, p: 'prayer', kind: 'intercession', audio: { de: '/media/host/i.mp3' },
  text: { de: 'Tom aus Berlin betet: Herr, sei bei Maria.' }, voices: [], prayers: [],
};

function stage(item: TimelineItem, mode: EngineState['mode'], extra: Partial<EngineState> = {}): EngineState {
  return { ...initialState('main'), joined: true, program: prayerHour, item, mode, wall: [mother, peace], ...extra };
}

afterEach(() => {
  useSheets.setState({ open: null });
});

describe('the stage in a prayer hour', () => {
  useSettings.setState({ lang: 'en' });

  it('during the collection: what we pray for, how many came in — not what they say — and a button to share a request', () => {
    render(<StageVisual engine={stage(bed, 'bed', { wall: [], collected: 3, submissions: { prayer: 'open', intercession: 'closed' } })} />);
    expect(screen.getByText('What can we pray for?')).toBeTruthy();
    expect(screen.getByText('3 prayer requests so far')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Pray$/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Share a prayer request' }));
    expect(useSheets.getState().open).toBe('prayer');
  });

  it('in the prayer time: several requests at once — what there is to pray for — and "Pray" opens the recorder', () => {
    render(<StageVisual engine={stage(silence, 'silence', { wall: [mother, peace, exams, job], submissions: { prayer: 'open', intercession: 'open' } })} />);
    expect(screen.getByText('Prayer time')).toBeTruthy();
    const shown = [mother, peace, exams, job].filter((e) => screen.queryByText(e.text) !== null);
    expect(shown.length).toBeGreaterThanOrEqual(2);
    expect(shown.length).toBeLessThanOrEqual(3);
    // No request picked out with a button of its own: 🙏 stays on the wall card.
    expect(screen.queryByRole('button', { name: /I prayed/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Pray$/ }));
    expect(useSheets.getState().open).toBe('pray');
    expect(screen.getByRole('button', { name: 'Share a prayer request' })).toBeTruthy();
  });

  it('the buttons follow the minute file: closed for requests and prayers, neither shows', () => {
    render(<StageVisual engine={stage(silence, 'silence', { submissions: { prayer: 'closed', intercession: 'closed' } })} />);
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('whose a request is: the first name and place its sender gave — nothing for one who stayed anonymous', () => {
    const ruth: WallEntry = { id: 'pr', text: 'Please pray for my son.', at: 6, name: 'Ruth', place: 'Lagos' };
    const { unmount } = render(<StageVisual engine={stage(silence, 'silence', { wall: [ruth, exams], submissions: {} })} />);
    expect(screen.getByText('Ruth · Lagos')).toBeTruthy();
    expect(screen.getByText(exams.text).previousElementSibling).toBeNull();
    unmount();
    // Read out now: the same line above it.
    render(<StageVisual engine={stage(reading, 'host', { wall: [ruth], praying: ['pr'], hostText: 'Ruth from Lagos asks for prayer: Please pray for my son.' })} />);
    expect(screen.getByText('Ruth · Lagos')).toBeTruthy();
  });

  it('Open Doors\' request says whose it is, translated for English listeners', () => {
    render(<StageVisual engine={stage(silence, 'silence', { wall: [openDoors], submissions: {} })} />);
    expect(screen.getByText('Open Doors · Nigeria')).toBeTruthy();
    expect(screen.getByText('Let us pray for the Christians in Nigeria.')).toBeTruthy();
  });

  it('while a request on the wall is read out, that request — never "is praying"', () => {
    render(<StageVisual engine={stage(reading, 'host', { praying: ['pa'], hostText: 'A prayer request: Please pray for my mother.' })} />);
    expect(screen.getByText('Prayer request')).toBeTruthy();
    expect(screen.getByText(mother.text)).toBeTruthy();
    expect(screen.queryByText(/is praying/)).toBeNull();
  });

  it('a listener\'s written prayer shows as theirs, not the host\'s', () => {
    render(<StageVisual engine={stage(written, 'host', { hostText: 'Tom aus Berlin betet: Herr, sei bei Maria.' })} />);
    expect(screen.getByText('Prayer from a listener')).toBeTruthy();
    expect(screen.getByText('Tom aus Berlin betet: Herr, sei bei Maria.')).toBeTruthy();
    expect(screen.queryByText(/is speaking/)).toBeNull();
  });

  it('a request taken off the wall since: the host as usual', () => {
    render(<StageVisual engine={stage(reading, 'host', { praying: ['px'], hostText: 'Take a moment to pray for this request.' })} />);
    expect(screen.getByText('Take a moment to pray for this request.')).toBeTruthy();
  });

  it('on the compact stage of the other pages: no sheet to open there, so no button; one request', () => {
    render(<StageVisual engine={stage(silence, 'silence', { submissions: { prayer: 'open', intercession: 'open' } })} compact />);
    expect(screen.queryByRole('button')).toBeNull();
    expect([mother, peace].filter((e) => screen.queryByText(e.text) !== null)).toHaveLength(1);
  });

  it('outside a prayer hour, a moment of silence looks as before', () => {
    render(<StageVisual engine={stage(silence, 'silence', { program: { ...prayerHour, format: 'music' } })} />);
    expect(screen.getByText('A moment of silence')).toBeTruthy();
    expect(screen.queryByText(mother.text)).toBeNull();
  });
});
