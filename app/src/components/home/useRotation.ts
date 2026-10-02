import { useEffect, useState, useSyncExternalStore, type RefObject } from 'react';

/** How long one message stays before the next takes its place. */
export const ROTATE_MS = 11_000;
/** A prayer request is longer, and read more slowly, than a chat line. */
export const WALL_ROTATE_MS = 15_000;

function subscribeVisibility(onChange: () => void): () => void {
  document.addEventListener('visibilitychange', onChange);
  return () => document.removeEventListener('visibilitychange', onChange);
}

const reducedMotion = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Steps a feed forward every `ms`: the body (`ref`, keyed by the
 * offset so the next one fades in by CSS) fades out first. It waits while
 * `paused` (a picker is open), while the tab is hidden, and starts over
 * whenever `nudge` changes (a reaction was just given).
 */
export function useRotation(ref: RefObject<HTMLElement | null>, length: number, paused: boolean, nudge = 0, ms = ROTATE_MS): number {
  const [offset, setOffset] = useState(0);
  const hidden = useSyncExternalStore(subscribeVisibility, () => document.hidden, () => false);

  useEffect(() => {
    if (paused || hidden || length < 2) return;
    let cancelled = false;
    let fade: Animation | undefined;
    const next = () => {
      if (!cancelled) setOffset((o) => (o + 1) % length);
    };
    const wait = window.setTimeout(() => {
      const el = ref.current;
      if (!el || reducedMotion() || typeof el.animate !== 'function') return next();
      fade = el.animate(
        [
          { opacity: 1, transform: 'none' },
          { opacity: 0, transform: 'translateY(-7px)' },
        ],
        { duration: 250, easing: 'ease-in', fill: 'forwards' },
      );
      fade.finished.then(next, () => {});
    }, ms);
    return () => {
      cancelled = true;
      window.clearTimeout(wait);
      fade?.cancel();
    };
  }, [paused, hidden, length, offset, nudge, ref, ms]);

  return length > 0 ? offset % length : 0;
}
