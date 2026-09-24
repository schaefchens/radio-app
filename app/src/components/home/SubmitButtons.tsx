import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import type { SubmissionState, SubmissionType } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { SongRequestSheet } from '@/components/submit/SongRequestSheet';
import { PrayerSheet } from '@/components/submit/PrayerSheet';
import { RecordSheet } from '@/components/submit/RecordSheet';
import { ChevronIcon, MicIcon, MusicIcon, PrayIcon, RoomIcon } from '@/components/common/icons';
import type { SubmitSheetsState } from './useSubmitSheets';

type Tile = 'song' | 'story' | 'prayer' | 'room';

export function SubmitSheets({ state }: { state: SubmitSheetsState }) {
  return (
    <>
      <SongRequestSheet open={state.open === 'song'} onClose={state.close} />
      <PrayerSheet open={state.open === 'prayer'} onClose={state.close} onRecord={() => state.show('record', 'prayer')} />
      <RecordSheet open={state.open === 'record'} onClose={state.close} initialKind={state.recordKind} />
    </>
  );
}

/** The four tiles from the mockup; each follows what the program on air accepts. */
export function SubmitButtons({ variant = 'wide', onOpen }: { variant?: 'wide' | 'grid'; onOpen: SubmitSheetsState['show'] }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const submissions = useRadio((s) => s.engine.submissions);
  const realtime = useSession((s) => s.config?.realtime ?? true);

  const stateOf = (types: SubmissionType[]): SubmissionState | 'off' => {
    const states = types.map((ty) => submissions[ty]).filter((s): s is SubmissionState => !!s);
    if (states.includes('open')) return 'open';
    if (states.includes('closing')) return 'closing';
    return states.length ? 'closed' : 'off';
  };
  const tiles: { id: Tile; state: SubmissionState | 'off'; icon: React.ReactNode; gradient: string; onClick: () => void }[] = [
    { id: 'song', state: stateOf(['song']), icon: <MusicIcon size={variant === 'wide' ? 36 : 28} />, gradient: 'from-song-from to-song-to', onClick: () => onOpen('song') },
    { id: 'story', state: stateOf(['story', 'testimony', 'greeting']), icon: <MicIcon size={variant === 'wide' ? 36 : 28} />, gradient: 'from-story-from to-story-to', onClick: () => onOpen('record', 'story') },
    { id: 'prayer', state: stateOf(['prayer']), icon: <PrayIcon size={variant === 'wide' ? 36 : 28} />, gradient: 'from-prayer-from to-prayer-to', onClick: () => onOpen('prayer') },
    { id: 'room', state: realtime ? 'open' : 'off', icon: <RoomIcon size={variant === 'wide' ? 36 : 28} />, gradient: 'from-room-from to-room-to', onClick: () => navigate('/chat') },
  ];

  return (
    <div className={clsx('grid gap-3', variant === 'wide' ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-4')}>
        {tiles.map((tile) => {
          const usable = tile.state === 'open' || tile.state === 'closing';
          return (
            <button
              key={tile.id}
              type="button"
              disabled={!usable}
              onClick={tile.onClick}
              className={clsx(
                'group relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br text-left text-white shadow-card transition-transform enabled:hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-45',
                tile.gradient,
                variant === 'wide' ? 'flex items-center gap-4 px-4 py-4' : 'flex flex-col items-center gap-1 px-2 py-3 text-center',
              )}
            >
              <span className="shrink-0 drop-shadow">{tile.icon}</span>
              <span className="min-w-0 flex-1">
                <span className={clsx('block font-semibold leading-tight', variant === 'wide' ? 'text-lg' : 'text-xs')}>{t(`submit.${tile.id}.title`)}</span>
                {variant === 'wide' && (
                  <span className="block truncate text-sm text-white/80">
                    {tile.state === 'closed' ? t('submit.closed') : tile.state === 'off' ? t('submit.notNow') : t(`submit.${tile.id}.subtitle`)}
                  </span>
                )}
              </span>
              {tile.state === 'closing' && (
                <span className="absolute right-2 top-2 rounded-full bg-black/35 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide">
                  {t('submit.closing')}
                </span>
              )}
              {variant === 'wide' && <ChevronIcon dir="right" size={22} className="shrink-0 opacity-80" />}
            </button>
          );
        })}
    </div>
  );
}
