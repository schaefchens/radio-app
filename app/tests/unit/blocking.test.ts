import { describe, expect, it } from 'vitest';
import type { ChatMessage, Voice, WallEntry } from '@arche/shared';
import { visibleMessages, visibleVoices, visibleWall, voiceTag } from '@/lib/blocking';
import type { BlockedUser } from '@/store/blocks';

/** What a listener blocked or reported stays out of sight — and nothing else moves. */

const msg = (id: string, sub: string): ChatMessage => ({ id, sub, name: sub, country: '', text: 'hi', at: 1, likes: 0 }) as ChatMessage;
const voice = (id: string, by?: string): Voice => ({ id, name: 'x', country: '', text: 'amen', at: 1, ...(by ? { by } : {}) });
const entry = (id: string): WallEntry => ({ id, text: 'pray', at: 1 });

describe('the author mark', () => {
  // The same vector as server/tests/cases/reports.php (Presence::voiceTag).
  it('is the server’s', async () => {
    expect(await voiceTag('k3v9q2m7x4')).toBe('6e59a2360912');
  });
});

describe('filters', () => {
  const ben: BlockedUser = { sub: 'ben', name: 'Ben', tag: 'tagben', at: 1 };

  it('hand back the very same array when nothing is blocked (zustand selectors would loop otherwise)', () => {
    const messages = [msg('m1', 'ann'), msg('m2', 'ben')];
    const voices = [voice('v1', 'tagann')];
    const wall = [entry('p1')];
    expect(visibleMessages(messages, [])).toBe(messages);
    expect(visibleMessages(messages, [{ ...ben, sub: 'carl' }])).toBe(messages);
    expect(visibleVoices(voices, [ben], [])).toBe(voices);
    expect(visibleWall(wall, ['p9'])).toBe(wall);
  });

  it('drop a blocked person’s messages and voices, and what this device reported', () => {
    expect(visibleMessages([msg('m1', 'ann'), msg('m2', 'ben')], [ben]).map((m) => m.id)).toEqual(['m1']);
    expect(visibleVoices([voice('v1', 'tagann'), voice('v2', 'tagben'), voice('v3'), voice('v4', 'tagann')], [ben], ['v4']).map((v) => v.id)).toEqual(['v1', 'v3']);
    expect(visibleWall([entry('p1'), entry('p2')], ['p2']).map((e) => e.id)).toEqual(['p1']);
  });
});
