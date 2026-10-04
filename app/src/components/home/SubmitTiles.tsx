import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { VIDEO_SUBMISSION_TYPES, type SubmissionState, type SubmissionType } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSheets } from '@/store/sheets';
import { VideoRequestSheet } from '@/components/submit/VideoRequestSheet';
import { PrayerSheet } from '@/components/submit/PrayerSheet';
import { RecordSheet } from '@/components/submit/RecordSheet';
import { PraySheet } from '@/components/submit/PraySheet';
import { BookIcon, MicIcon, MusicIcon, PrayIcon } from '@/components/common/icons';

type Tile = 'song' | 'story' | 'prayer' | 'video';

/** The submission sheets, mounted once, on Home (AppShell); a prayer hour's Pray sheet opens from the stage. */
export function SubmitSheets() {
  const open = useSheets((s) => s.open);
  const recordKind = useSheets((s) => s.recordKind);
  const show = useSheets((s) => s.show);
  const close = useSheets((s) => s.close);
  return (
    <>
      <VideoRequestSheet kind="song" open={open === 'song'} onClose={close} />
      <VideoRequestSheet kind="video" open={open === 'video'} onClose={close} />
      <PrayerSheet open={open === 'prayer'} onClose={close} onRecord={() => show('record', 'prayer')} />
      <RecordSheet open={open === 'record'} onClose={close} initialKind={recordKind} />
      <PraySheet open={open === 'pray'} onClose={close} />
    </>
  );
}

/**
 * The four tiles of the design; each follows what the program on air
 * accepts (the fourth, a video — a preaching, a testimony, a mission video or
 * a film —, only a program of such a format; the chat is in the menu).
 * `beforeOpen` lets the phone dock turn a first tap on a folded tile into
 * "unfold" (it returns true when it took the tap).
 */
export function SubmitTiles({ beforeOpen }: { beforeOpen?: () => boolean }) {
  const { t } = useTranslation();
  const submissions = useRadio((s) => s.engine.submissions);
  const show = useSheets((s) => s.show);

  const stateOf = (types: readonly SubmissionType[]): SubmissionState | 'off' => {
    const states = types.map((ty) => submissions[ty]).filter((s): s is SubmissionState => !!s);
    if (states.includes('open')) return 'open';
    if (states.includes('closing')) return 'closing';
    return states.length ? 'closed' : 'off';
  };
  const tiles: { id: Tile; state: SubmissionState | 'off'; icon: React.ReactNode; tone: string; open: () => void }[] = [
    { id: 'song', state: stateOf(['song']), icon: <MusicIcon />, tone: 'purple', open: () => show('song') },
    { id: 'story', state: stateOf(['story', 'testimony', 'greeting']), icon: <MicIcon />, tone: 'green', open: () => show('record', 'story') },
    { id: 'prayer', state: stateOf(['prayer']), icon: <PrayIcon />, tone: 'blue', open: () => show('prayer') },
    // Open when any kind of video is: the sheet's picker offers the ones that are.
    { id: 'video', state: stateOf(VIDEO_SUBMISSION_TYPES), icon: <BookIcon />, tone: 'gold', open: () => show('video') },
  ];

  return (
    <div className="actions" role="group" aria-label={t('submit.label')}>
      {tiles.map((tile) => {
        const usable = tile.state === 'open' || tile.state === 'closing';
        const hint = tile.state === 'closed' ? t('submit.closed') : tile.state === 'off' ? t('submit.notNow') : t(`submit.${tile.id}.subtitle`);
        return (
          <button
            key={tile.id}
            type="button"
            className={clsx('action', tile.tone)}
            aria-disabled={!usable}
            aria-label={usable ? t(`submit.${tile.id}.title`) : `${t(`submit.${tile.id}.title`)} · ${hint}`}
            onClick={() => {
              if (beforeOpen?.()) return;
              if (usable) tile.open();
            }}
          >
            {tile.icon}
            <span className="action-copy">
              <strong>{t(`submit.${tile.id}.title`)}</strong>
              <small>{hint}</small>
            </span>
            {tile.state === 'closing' && <span className="action-badge">{t('submit.closing')}</span>}
          </button>
        );
      })}
    </div>
  );
}
