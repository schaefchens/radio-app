import { useEffect, useRef } from 'react';
import { useStage } from '@/store/stage';

/**
 * Android's back button closes what is open before it leaves the page: the
 * newest sheet, dialog or picker first. A stack of its own, apart from the
 * stage's overlay count: that count says whether the YouTube player may show,
 * and a picker beside the player can be closed but covers nothing.
 */

interface Entry {
  close: () => void;
}

const stack: Entry[] = [];

/** Registers something open; the returned function takes it off again. */
export function pushBack(close: () => void): () => void {
  const entry: Entry = { close };
  stack.push(entry);
  return () => {
    const i = stack.indexOf(entry);
    if (i >= 0) stack.splice(i, 1);
  };
}

/**
 * Closes the newest open thing; false when nothing is open. Taken off at once,
 * not when its owner's effect cleans up after the next render: a second press
 * before that must reach the next one down.
 */
export function closeTop(): boolean {
  const top = stack.pop();
  if (!top) return false;
  top.close();
  return true;
}

export function openCount(): number {
  return stack.length;
}

/** While `open`, back closes it with the newest `onClose`. */
export function useBackClose(open: boolean, onClose: () => void): void {
  // The caller's callback, current without re-registering: re-registering on
  // every render would move this entry to the top of the stack.
  const latest = useRef(onClose);
  useEffect(() => {
    latest.current = onClose;
  }, [onClose]);
  useEffect(() => {
    if (!open) return;
    return pushBack(() => latest.current());
  }, [open]);
}

/**
 * A modal over the page (sheet, dialog): back closes it, and the YouTube
 * player pauses under it (store/stage.ts). One call, so neither half can be
 * forgotten.
 */
export function useOverlay(open: boolean, onClose: () => void): void {
  useBackClose(open, onClose);
  useEffect(() => {
    if (!open) return;
    const { pushOverlay, popOverlay } = useStage.getState();
    pushOverlay();
    return () => popOverlay();
  }, [open]);
}

// --- Pages --------------------------------------------------------------------

/**
 * How many pages this document has gone forward. Counted per document: after a
 * reload, back must not lead to a page of the previous one.
 */
let depth = 0;

export function noteNavigation(type: 'PUSH' | 'POP' | 'REPLACE'): void {
  if (type === 'PUSH') depth++;
  else if (type === 'POP') depth = Math.max(0, depth - 1);
}

export type BackAction = 'close' | 'back' | 'home' | 'leave';

export function decideBack(s: { open: number; depth: number; path: string }): BackAction {
  if (s.open > 0) return 'close';
  if (s.depth > 0) return 'back';
  if (s.path !== '/') return 'home';
  return 'leave';
}

interface Router {
  back: () => void;
  home: () => void;
  path: () => string;
}

let router: Router | null = null;

/** AppShell hands in the router; the returned function takes it back. */
export function setRouter(r: Router): () => void {
  router = r;
  return () => {
    if (router === r) router = null;
  };
}

/** Back was pressed: close, go back, go Home, or leave (`leave`). */
export function handleBack(leave: () => void): void {
  const action = decideBack({ open: stack.length, depth, path: router ? router.path() : window.location.pathname });
  if (action === 'close') closeTop();
  else if (action === 'back') {
    if (router) router.back();
    else window.history.back();
  } else if (action === 'home' && router) router.home();
  else leave();
}

/** Tests only: start from an empty stack and no history. */
export function resetBackStack(): void {
  stack.length = 0;
  depth = 0;
  router = null;
}
