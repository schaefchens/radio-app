import { useCallback, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { Link } from 'react-router-dom';
import { useRadio } from '@/store/radio';
import { useSettings } from '@/store/settings';
import { useStage } from '@/store/stage';
import { joinRadio, resumeRadio } from '@/lib/radio';
import { StageVisual } from './StageVisual';
import { PlayIcon } from '@/components/common/icons';

/**
 * A stage slot: our own visuals, plus the rectangle the floating YouTube
 * player (PlayerLayer) covers while a song plays. 16:9 and never lower than
 * 200 px, so the player is never below YouTube's 200×200 minimum.
 */
export function StageRegion({ compact = false, flush = false }: { compact?: boolean; flush?: boolean }) {
  const { t } = useTranslation();
  const engine = useRadio((s) => s.engine);
  const setConsent = useSettings((s) => s.setConsent);
  const setSlot = useStage((s) => s.setSlot);
  const setInView = useStage((s) => s.setInView);
  const el = useRef<HTMLDivElement | null>(null);

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

  useEffect(() => {
    const node = el.current;
    if (!node || typeof IntersectionObserver === 'undefined') {
      setInView(true);
      return;
    }
    const io = new IntersectionObserver(
      ([e]) => {
        if (useStage.getState().slot === node) setInView((e?.intersectionRatio ?? 0) >= 0.5);
      },
      { threshold: [0, 0.5, 1] },
    );
    io.observe(node);
    return () => io.disconnect();
  }, [setInView]);

  const join = (): void => {
    setConsent(true);
    joinRadio();
  };

  return (
    <div className={clsx('w-full', compact && 'mx-auto max-w-[640px]')}>
      <div
        ref={ref}
        data-stage-slot=""
        // The stage is a dark room in every theme: our own visuals under the
        // video take the dark tokens, whatever the page around them shows.
        data-theme="dark"
        // flush: inside the player card, edge to edge between its rows.
        className={clsx('player-stage relative aspect-video w-full overflow-hidden', !flush && 'rounded-2xl border border-line/30 shadow-card')}
        style={{ minHeight: 200 }}
      >
        <StageVisual engine={engine} compact={compact} />
        {!engine.joined && (
          <div className="join-overlay">
            <button type="button" onClick={join} className="play-button" aria-label={t('join.button')} title={t('join.button')}>
              <PlayIcon />
            </button>
            <p className="stage-subtitle">
              {t('join.consent')}{' '}
              <Link to="/datenschutz" className="underline">
                {t('join.privacy')}
              </Link>
            </p>
          </div>
        )}
      </div>
      {engine.joined && engine.needsTap && (
        // Below the stage, never on top of the player.
        <button
          type="button"
          onClick={resumeRadio}
          className={clsx('flex w-full items-center justify-center gap-2 border-accent/30 bg-accent/10 px-3 py-2 text-sm text-accent', flush ? 'border-y' : 'mt-2 rounded-xl border')}
        >
          <PlayIcon size={14} />
          {engine.playerVisible ? t('stage.tapVideo') : t('stage.resume')}
        </button>
      )}
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
