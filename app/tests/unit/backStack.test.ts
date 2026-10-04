import { beforeEach, describe, expect, it, vi } from 'vitest';
import { closeTop, decideBack, handleBack, noteNavigation, openCount, pushBack, resetBackStack, setRouter } from '@/lib/backStack';

beforeEach(() => resetBackStack());

describe("Android's back button", () => {
  it('closes the newest open thing first', () => {
    const closed: string[] = [];
    pushBack(() => closed.push('sheet'));
    const offPicker = pushBack(() => closed.push('picker'));
    pushBack(() => closed.push('dialog'));
    offPicker(); // the picker closed by itself
    expect(openCount()).toBe(2);
    expect(closeTop()).toBe(true);
    expect(closeTop()).toBe(true);
    expect(closed).toEqual(['dialog', 'sheet']);
  });

  it.each([
    [{ open: 1, depth: 3, path: '/chat' }, 'close'],
    [{ open: 0, depth: 2, path: '/chat' }, 'back'],
    // Opened on /chat (a link, a reload): there is no page behind it in this document.
    [{ open: 0, depth: 0, path: '/chat' }, 'home'],
    [{ open: 0, depth: 0, path: '/' }, 'leave'],
  ])('%o → %s', (state, expected) => {
    expect(decideBack(state)).toBe(expected);
  });

  it('walks back the pages this document opened, then Home, then out', () => {
    let path = '/';
    const router = { back: vi.fn(() => (path = '/')), home: vi.fn(() => (path = '/')), path: () => path };
    const leave = vi.fn();
    setRouter(router);
    noteNavigation('POP'); // the first page arrives as a POP
    noteNavigation('PUSH');
    path = '/schedule';

    const closeSheet = vi.fn();
    const off = pushBack(closeSheet);
    handleBack(leave);
    expect(closeSheet).toHaveBeenCalledTimes(1);
    expect(router.back).not.toHaveBeenCalled();
    off();

    handleBack(leave);
    expect(router.back).toHaveBeenCalledTimes(1);
    noteNavigation('POP');
    noteNavigation('POP'); // never below zero

    handleBack(leave);
    expect(leave).toHaveBeenCalledTimes(1);

    path = '/about'; // a page replaced in place leaves nothing behind
    noteNavigation('REPLACE');
    handleBack(leave);
    expect(router.home).toHaveBeenCalledTimes(1);
  });
});
