import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ChannelInfo, Lang } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { switchChannel } from '@/lib/radio';
import { followTurns, openFullStage } from '@/lib/fullStage';
import { Cover, SongReactions, TrackProgress, TrackTitle } from './NowPlaying';
import { useNowPlaying } from './useNowPlaying';
import { StageRegion } from '@/components/stage/StageRegion';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { ChevronIcon, ExpandIcon, UsersIcon } from '@/components/common/icons';

// A stable empty list: a selector that returns a fresh [] on every call makes
// the store subscription see a change each render and React bails out.
const NONE: ChannelInfo[] = [];

/**
 * The design's player card: LIVE, the channel and the program on air, the
 * listeners; the stage; what is playing, how far, and the reactions.
 */
export function PlayerCard() {
  const { t } = useTranslation();
  // A phone turned sideways on Home while the radio plays: the big stage.
  useEffect(() => followTurns(), []);
  return (
    <section className="card player-card" aria-label={t('player.label')}>
      <StationRow />
      <StageRegion flush />
      <TrackRow />
    </section>
  );
}

function StationRow() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const engine = useRadio((s) => s.engine);
  const channels = useSession((s) => s.channels?.channels ?? NONE);
  const [choosing, setChoosing] = useState(false);
  const active = channels.find((c) => c.id === engine.channel);
  const name = active?.name[lang] ?? t('brand.name');
  const formatter = new Intl.NumberFormat(lang);
  const several = channels.length > 1;

  return (
    <div className="station-row">
      <span className="live">{t('live.badge')}</span>
      <div className="station-name">
        {/* The list opens as a sheet: a dropdown here would hang over the video. */}
        {several ? (
          <button type="button" className="channel-button" aria-haspopup="dialog" aria-label={`${t('channel.label')}: ${name}`} onClick={() => setChoosing(true)}>
            <strong>{name}</strong>
            <ChevronIcon size={16} />
          </button>
        ) : (
          <strong>{name}</strong>
        )}
        <small>{engine.program?.title[lang] ?? engine.program?.subtitle[lang] ?? ''}</small>
      </div>
      <div className="listeners">
        <UsersIcon />
        <div>
          <strong>{formatter.format(engine.listeners)}</strong>
          <span>{t('live.listeningNow')}</span>
        </div>
      </div>
      {several && (
        <BottomSheet open={choosing} onClose={() => setChoosing(false)} title={t('channel.choose')}>
          <BottomSheetBody>
            <ul className="flex flex-col gap-1" role="listbox" aria-label={t('channel.choose')}>
              {channels.map((c) => (
                <li key={c.id}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={c.id === engine.channel}
                    onClick={() => {
                      setChoosing(false);
                      if (c.id !== engine.channel) void switchChannel(c.id);
                    }}
                    className="flex w-full items-center gap-3 rounded-xl border border-transparent px-3 py-3 text-left hover:bg-soft aria-selected:border-accent aria-selected:bg-accent/10"
                  >
                    <span className="h-3 w-3 shrink-0 rounded-full" style={{ background: c.color }} />
                    <span className="font-semibold">{c.name[lang]}</span>
                  </button>
                </li>
              ))}
            </ul>
          </BottomSheetBody>
        </BottomSheet>
      )}
    </div>
  );
}

function TrackRow() {
  const { t } = useTranslation();
  const np = useNowPlaying();
  if (!np) return null;
  return (
    <div className="track-row">
      <Cover np={np} />
      <TrackTitle np={np} />
      <TrackProgress
        np={np}
        end={
          // Hidden in store apps that stay upright (home.css): there an
          // upright phone's stage is as wide as the screen already.
          <button type="button" className="full-button" aria-label={t('stage.full')} title={t('stage.full')} onClick={openFullStage}>
            <ExpandIcon />
          </button>
        }
      />
      <SongReactions np={np} />
    </div>
  );
}
