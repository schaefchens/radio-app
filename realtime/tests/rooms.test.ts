import { describe, expect, it } from 'vitest';
import { HISTORY_LIMIT, RoomRegistry } from '../src/rooms.ts';

const msg = (id: string) => ({ id, sub: 's', name: 'n', country: 'DE', text: id, at: 0, likes: 0 });

describe('RoomRegistry.assign', () => {
  it('fills the fullest room with space before opening a new one', () => {
    const reg = new RoomRegistry<string>();
    const a = reg.assign('main', 'de', 'dach', 3, 0);
    reg.join(a, 'x', 0);
    const b = reg.assign('main', 'de', 'dach', 3, 0);
    expect(b).toBe(a);
    reg.join(a, 'y', 0);
    reg.join(a, 'z', 0);
    const c = reg.assign('main', 'de', 'dach', 3, 0);
    expect(c).not.toBe(a);
    expect(c.id).toBe('main-de-dach-2');
    reg.join(c, 'w', 0);
    // a frees a seat: it is fuller than c, so it wins.
    reg.leave(a, 'z', 0);
    expect(reg.assign('main', 'de', 'dach', 3, 0)).toBe(a);
  });

  it('keeps channels, languages and regions apart', () => {
    const reg = new RoomRegistry<string>();
    const ids = [
      reg.assign('main', 'de', 'dach', 10, 0).id,
      reg.assign('main', 'en', 'dach', 10, 0).id,
      reg.assign('main', 'de', 'europe', 10, 0).id,
      reg.assign('worship', 'de', 'dach', 10, 0).id,
    ];
    expect(new Set(ids).size).toBe(4);
  });

  it('reuses the smallest free index once an empty room is swept', () => {
    const reg = new RoomRegistry<string>();
    const first = reg.assign('main', 'en', 'world', 1, 0);
    reg.join(first, 'a', 0);
    const second = reg.assign('main', 'en', 'world', 1, 0);
    reg.join(second, 'b', 0);
    reg.leave(first, 'a', 1000);
    expect(reg.sweep(1000 + 60_000, 30_000)).toBe(1);
    expect(reg.assign('main', 'en', 'world', 1, 0).id).toBe('main-en-world-1');
  });
});

describe('RoomRegistry history', () => {
  it('evicts beyond the limit and keeps the message index in step', () => {
    const reg = new RoomRegistry<string>();
    const room = reg.assign('main', 'en', 'world', 10, 0);
    for (let i = 0; i < HISTORY_LIMIT + 5; i++) reg.pushMessage(room, msg(`m${i}`), i);
    expect(room.history).toHaveLength(HISTORY_LIMIT);
    expect(reg.findMessage('m0')).toBeNull();
    expect(reg.findMessage(`m${HISTORY_LIMIT + 4}`)?.room).toBe(room);
    expect(reg.removeMessage('m50')).toBe(room);
    expect(reg.findMessage('m50')).toBeNull();
    expect(room.history.some((e) => e.msg.id === 'm50')).toBe(false);
    expect(reg.removeMessage('m50')).toBeNull();
  });
});
