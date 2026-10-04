import { useCallback, useEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { closeFullStage } from '@/lib/fullStage';
import { Cover, SongReactions, TrackProgress, TrackTitle } from '@/components/home/NowPlaying';
import { useNowPlaying } from '@/components/home/useNowPlaying';
import { ShrinkIcon } from '@/components/common/icons';

/** How long the bar stays after the last tap, click, pointer move or key. */
const IDLE_MS = 3000;

/**
 * The big stage's bar, below the video and never on it: the song row made
 * small — what is playing, how far, the reactions — and the way out. After a
 * few quiet seconds a black veil fades over it and the screen is the stage;
 * a tap, a click, the pointer or a key brings it back, and so does the next
 * song, for a few seconds. The veil takes the first tap on a hidden bar (its
 * CSS keeps it a moment after it lifts): no one presses a reaction they
 * cannot see. It stays off while the pointer rests on the bar, the emoji
 * strip is open, or the listener must tap to go on.
 *
 * Pointer moves over the video belong to YouTube's frame and never reach us;
 * the bar and the black around the stage do.
 */
export function StageBar({ resume, exitRef }: { resume: ReactNode; exitRef: RefObject<HTMLButtonElement | null> }) {
  const { t } = useTranslation();
  const np = useNowPlaying();
  const [idle, setIdle] = useState(false);
  const [hovered, setHovered] = useState(false);
  const [picking, setPicking] = useState(false);
  const timer = useRef(0);

  const rest = useCallback(() => {
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setIdle(true), IDLE_MS);
  }, []);
  const wake = useCallback(() => {
    setIdle(false);
    rest();
  }, [rest]);

  useEffect(() => {
    document.addEventListener('pointermove', wake);
    document.addEventListener('pointerdown', wake);
    document.addEventListener('keydown', wake);
    return () => {
      document.removeEventListener('pointermove', wake);
      document.removeEventListener('pointerdown', wake);
      document.removeEventListener('keydown', wake);
      window.clearTimeout(timer.current);
    };
  }, [wake]);

  // A new song shows what it is, then the veil comes back.
  const title = np?.title ?? '';
  const [shownTitle, setShownTitle] = useState(title);
  if (shownTitle !== title) {
    setShownTitle(title);
    setIdle(false);
  }
  useEffect(() => rest(), [title, rest]);

  const veiled = idle && !hovered && !picking && !resume;

  return (
    <div
      className="stage-full-bar"
      data-idle={veiled || undefined}
      onPointerEnter={(e) => e.pointerType === 'mouse' && setHovered(true)}
      onPointerLeave={(e) => {
        if (e.pointerType !== 'mouse') return;
        setHovered(false);
        wake();
      }}
    >
      {resume}
      <div className="stage-full-controls">
        {np && (
          <>
            <Cover np={np} />
            <TrackTitle np={np} />
            <TrackProgress np={np} />
            <SongReactions
              np={np}
              onActivity={(open) => {
                setPicking(open);
                if (!open) wake();
              }}
            />
          </>
        )}
        <button ref={exitRef} type="button" className="stage-full-exit" aria-label={t('stage.exitFull')} title={t('stage.exitFull')} onClick={closeFullStage}>
          <ShrinkIcon />
        </button>
        <p className="stage-full-turn">{t('stage.turnPhone')}</p>
      </div>
      <div className="stage-full-veil" aria-hidden="true" />
    </div>
  );
}
