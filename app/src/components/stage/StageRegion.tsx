import { useCallback, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { useRadio } from '@/store/radio';
import { useSettings } from '@/store/settings';
import { useSession } from '@/store/session';
import { useStage } from '@/store/stage';
import { joinRadio, resumeRadio } from '@/lib/radio';
import { StageVisual } from './StageVisual';
import { PlayIcon } from '@/components/common/icons';

/**
 * A stage slot: our own visuals, plus the rectangle the floating YouTube
 * player (PlayerLayer) covers while a song plays. 16:9 and never lower than
 * 200 px, so the player is never below YouTube's 200×200 minimum.
 */
export function StageRegion({ compact = false }: { compact?: boolean }) {
  const { t } = useTranslation();
  const engine = useRadio((s) => s.engine);
  const setConsent = useSettings((s) => s.setConsent);
  const privacy = useSession((s) => s.config?.privacy ?? '');
  const setSlot = useStage((s) => s.setSlot);
  const setInView = useStage((s) => s.setInView);
  const el = useRef<HTMLDivElement | null>(null);

  // Page changes unmount one stage and mount another in the same commit; only
  // clear the slot if it is still ours, or the new page's stage would vanish.
  const ref = useCallback(
    (node: HTMLDivElement | null) => {
      if (node) setSlot(node);
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
        className="relative aspect-video w-full overflow-hidden rounded-2xl border border-night-line/30 bg-night-deep shadow-card"
        style={{ minHeight: 200 }}
      >
        <StageVisual engine={engine} />
        {!engine.joined && (
          <div className="absolute inset-x-0 bottom-0 z-20 flex flex-col items-center gap-2 bg-gradient-to-t from-night-deep/95 via-night-deep/60 to-transparent px-4 pb-4 pt-16 text-center">
            <button type="button" onClick={join} className="btn-primary px-6 py-3 text-base shadow-glow">
              <PlayIcon size={18} />
              {t('join.button')}
            </button>
            <p className="max-w-md text-[0.7rem] leading-snug text-ink-muted">
              {t('join.consent')}{' '}
              {privacy && (
                <a href={privacy} target="_blank" rel="noreferrer" className="underline">
                  {t('join.privacy')}
                </a>
              )}
            </p>
          </div>
        )}
      </div>
      {engine.joined && engine.needsTap && (
        // Below the stage, never on top of the player.
        <button
          type="button"
          onClick={resumeRadio}
          className="mt-2 flex w-full items-center justify-center gap-2 rounded-xl border border-brand/30 bg-brand/10 px-3 py-2 text-sm text-brand-bright"
        >
          <PlayIcon size={14} />
          {engine.playerVisible ? t('stage.tapVideo') : t('stage.resume')}
        </button>
      )}
    </div>
  );
}
