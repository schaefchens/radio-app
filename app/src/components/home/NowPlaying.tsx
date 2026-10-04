import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useRadio } from '@/store/radio';
import { useSettings } from '@/store/settings';
import { joinRadio, leaveRadio, react } from '@/lib/radio';
import { clockDuration } from '@/lib/format';
import { tapHaptic } from '@/lib/haptics';
import type { NowPlaying } from './useNowPlaying';
import { Reactions } from './Reactions';
import { CdnImg } from '@/components/common/CdnImg';
import { LevelsIcon, PauseIcon, PlayIcon } from '@/components/common/icons';

/** The pieces of the song row, for the player card and the big stage's bar (useNowPlaying). */

export function Cover({ np }: { np: NowPlaying }) {
  return <div className="cover">{np.thumb ? <CdnImg src={np.thumb} className="h-full w-full object-cover" /> : np.icon}</div>;
}

/** The eyebrow, the title (to YouTube when it is a video there), and by whom. */
export function TrackTitle({ np }: { np: NowPlaying }) {
  const { t } = useTranslation();
  return (
    <div className="track">
      <span className="eyebrow">
        <LevelsIcon />
        <span>{np.eyebrow}</span>
      </span>
      <strong>
        {np.yt ? (
          <a href={`https://www.youtube.com/watch?v=${np.yt}`} target="_blank" rel="noopener noreferrer" title={t('nowPlaying.openYouTube', { title: np.title })}>
            {np.title}
          </a>
        ) : (
          np.title
        )}
      </strong>
      <small>
        {np.subtitle}
        {np.request && (
          <span className="track-request">
            {np.subtitle ? ' · ' : ''}
            {np.request}
          </span>
        )}
      </small>
    </div>
  );
}

/**
 * Play / pause, then how far — shown, not seekable: live radio has one
 * position for everyone — and at the end whatever the caller adds (the big
 * stage's button). Without a length (the prayer time's quiet) only the
 * buttons.
 */
export function TrackProgress({ np, end }: { np: NowPlaying; end?: ReactNode }) {
  const { t } = useTranslation();
  return (
    <div
      className="track-progress"
      role="group"
      aria-label={np.dur > 0 ? t('nowPlaying.position', { elapsed: clockDuration(np.pos), duration: clockDuration(np.dur) }) : undefined}
    >
      <PlayPause />
      {np.dur > 0 ? (
        <>
          <time aria-hidden="true">{clockDuration(np.pos)}</time>
          <div className="song-progress" aria-hidden="true">
            <span style={{ width: `${(np.pos / np.dur) * 100}%` }} />
          </div>
          <time aria-hidden="true">{clockDuration(np.dur)}</time>
        </>
      ) : (
        <span className="flex-1" />
      )}
      {end}
    </div>
  );
}

/** The song's reactions; nothing to react to between songs. */
export function SongReactions({ np, onActivity }: { np: NowPlaying; onActivity?: (pickerOpen: boolean) => void }) {
  const songId = np.songId;
  if (!songId) return null;
  return <Reactions key={songId} markId={`item:${songId}`} variant="song" onSend={(kind) => react(songId, kind)} onActivity={onActivity} />;
}

/**
 * Pause stops the radio as a reload would (nothing plays until play); play
 * joins at the live position — it is live radio, there is no "where I was".
 */
function PlayPause() {
  const { t } = useTranslation();
  const joined = useRadio((s) => s.engine.joined);
  const setConsent = useSettings((s) => s.setConsent);
  return joined ? (
    <button type="button" className="play-pause" aria-label={t('player.stop')} title={t('player.stop')} onClick={leaveRadio}>
      <PauseIcon />
    </button>
  ) : (
    <button
      type="button"
      className="play-pause"
      aria-label={t('player.play')}
      title={t('player.play')}
      onClick={() => {
        // The same tap as the stage's play button, whose note says what it loads.
        tapHaptic();
        setConsent(true);
        joinRadio();
      }}
    >
      <PlayIcon />
    </button>
  );
}
