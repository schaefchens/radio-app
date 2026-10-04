import { useCallback, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { Link } from 'react-router-dom';
import { useRadio } from '@/store/radio';
import { useSettings } from '@/store/settings';
import { useStage } from '@/store/stage';
import { joinRadio, resumeRadio } from '@/lib/radio';
import { closeFullStage } from '@/lib/fullStage';
import { tapHaptic } from '@/lib/haptics';
import { StageBar } from './StageBar';
import { StageVisual } from './StageVisual';
import { PlayIcon } from '@/components/common/icons';

/**
 * The width our visuals are laid out for. The big stage draws them at this
 * size and scales them up, so a television shows the stage of Home, larger —
 * not a few words lost in the middle of the screen.
 */
const STAGE_BASE = 800;

/**
 * A stage slot: our own visuals, plus the rectangle the floating YouTube
 * player (PlayerLayer) covers while a song plays. 16:9 and never lower than
 * 200 px, so the player is never below YouTube's 200×200 minimum.
 *
 * On the big stage (lib/fullStage.ts) the slot moves into a layer over the
 * whole page, and the player follows it there; the page keeps its place.
 */
export function StageRegion({ compact = false, flush = false }: { compact?: boolean; flush?: boolean }) {
  const { t } = useTranslation();
  const engine = useRadio((s) => s.engine);
  const leftInBackground = useRadio((s) => s.leftInBackground);
  const setConsent = useSettings((s) => s.setConsent);
  const setSlot = useStage((s) => s.setSlot);
  const setInView = useStage((s) => s.setInView);
  const full = useStage((s) => s.full);
  const el = useRef<HTMLDivElement | null>(null);
  const exit = useRef<HTMLButtonElement | null>(null);

  // Page changes unmount one stage and mount another in the same commit; only
  // clear the slot if it is still ours, or the new page's stage would vanish.
  // The new slot says at once whether it can be seen: waiting for its
  // IntersectionObserver left a frame without a visible stage, and the song
  // paused and restarted on every switch between Live and the other pages.
  const ref = useCallback(
    (node: HTMLDivElement | null) => {
      if (node) setSlot(node, visibleShare(node.getBoundingClientRect()) >= 0.5);
      else if (useStage.getState().slot === el.current) setSlot(null);
      el.current = node;
    },
    [setSlot],
  );

  // Again for the big stage and back, each another node: still watching the
  // old one, the stage scrolled away would leave the video playing.
  useEffect(() => {
    const node = el.current;
    if (!node || typeof IntersectionObserver === 'undefined') {
      setInView(true);
      return;
    }
    const io = new IntersectionObserver(
      (entries) => {
        // The newest entry: one call can carry several crossings, and the
        // first of them could leave the stage "out of view" while it was back.
        const e = entries[entries.length - 1];
        if (useStage.getState().slot === node) setInView((e?.intersectionRatio ?? 0) >= 0.5);
      },
      { threshold: [0, 0.5, 1] },
    );
    io.observe(node);
    return () => io.disconnect();
  }, [setInView, full]);

  useEffect(() => {
    const node = el.current;
    if (!full || !node) return;
    exit.current?.focus();
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(() => node.style.setProperty('--stage-scale', String(Math.max(1, node.clientWidth / STAGE_BASE))));
    ro.observe(node);
    return () => ro.disconnect();
  }, [full]);

  // The page under the big stage is left (an iPhone's swipe back, where the
  // big stage is only our layer): the screen must not stay covered and empty.
  useEffect(
    () => () => {
      if (useStage.getState().full) closeFullStage();
    },
    [],
  );

  const join = (): void => {
    tapHaptic();
    setConsent(true);
    joinRadio();
  };

  const slot = (
    <div
      ref={ref}
      data-stage-slot=""
      // The stage is a dark room in every theme: our own visuals under the
      // video take the dark tokens, whatever the page around them shows.
      data-theme="dark"
      // flush: inside the player card, edge to edge between its rows.
      className={clsx(
        'player-stage relative aspect-video overflow-hidden',
        !full && 'w-full',
        !flush && !full && 'rounded-2xl border border-line/30 shadow-card',
      )}
      style={{ minHeight: 200 }}
    >
      <StageVisual engine={engine} compact={compact} />
      {!engine.joined && (
        <div className="join-overlay">
          <button type="button" onClick={join} className="play-button" aria-label={t('join.button')} title={t('join.button')}>
            <PlayIcon />
          </button>
          {leftInBackground ? (
            // The store app went to the background and the radio left: why it is quiet now.
            <p className="stage-subtitle">{t('join.noVideo')}</p>
          ) : (
            <p className="stage-subtitle">
              {t('join.consent')}{' '}
              <Link to="/datenschutz" className="underline">
                {t('join.privacy')}
              </Link>
            </p>
          )}
        </div>
      )}
    </div>
  );

  const resume = engine.joined && engine.needsTap && (
    // Below the stage, never on top of the player.
    <button
      type="button"
      onClick={resumeRadio}
      className={clsx(
        'flex items-center justify-center gap-2 border-accent/30 bg-accent/10 px-3 py-2 text-sm text-accent',
        full ? 'rounded-xl border' : flush ? 'w-full border-y' : 'mt-2 w-full rounded-xl border',
      )}
    >
      <PlayIcon size={14} />
      {engine.playerVisible ? t('stage.tapVideo') : t('stage.resume')}
    </button>
  );

  if (full) {
    return (
      <>
        {/* The stage's place on the page, so the page behind is as it was when the big stage closes. */}
        <div className={clsx('w-full', compact && 'mx-auto max-w-[640px]')}>
          <div className="aspect-video w-full" style={{ minHeight: 200 }} />
        </div>
        {createPortal(
          // Under the player and over the page; the controls sit below the stage, never on the video.
          <section className="stage-full" data-theme="dark" aria-label={t('stage.full')}>
            <div className="stage-full-area">{slot}</div>
            <StageBar resume={resume} exitRef={exit} />
          </section>,
          document.body,
        )}
      </>
    );
  }

  return (
    <div className={clsx('w-full', compact && 'mx-auto max-w-[640px]')}>
      {slot}
      {resume}
    </div>
  );
}

/** How much of a box lies inside the viewport (0–1), as IntersectionObserver measures it. */
function visibleShare(r: DOMRect): number {
  if (r.width <= 0 || r.height <= 0) return 0;
  const w = Math.min(r.right, window.innerWidth) - Math.max(r.left, 0);
  const h = Math.min(r.bottom, window.innerHeight) - Math.max(r.top, 0);
  return w > 0 && h > 0 ? (w * h) / (r.width * r.height) : 0;
}
