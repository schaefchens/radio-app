import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ChannelInfo, Lang } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { react, switchChannel } from '@/lib/radio';
import { clockDuration } from '@/lib/format';
import { useServerNow } from './useServerNow';
import { Reactions } from './Reactions';
import { StageRegion } from '@/components/stage/StageRegion';
import { CdnImg } from '@/components/common/CdnImg';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { ChevronIcon, LevelsIcon, MicIcon, MusicIcon, NavLiveIcon, UsersIcon } from '@/components/common/icons';

// A stable empty list: a selector that returns a fresh [] on every call makes
// the store subscription see a change each render and React bails out.
const NONE: ChannelInfo[] = [];

/**
 * The design's player card: LIVE, the channel and the program on air, the
 * listeners; the stage; what is playing, how far, and the reactions.
 */
export function PlayerCard() {
  const { t } = useTranslation();
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
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const engine = useRadio((s) => s.engine);
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === engine.channel));
  const now = useServerNow(500);
  const item = engine.item;

  let title = '';
  let subtitle = '';
  let thumb: string | null = null;
  let yt: string | null = null;
  let start = 0;
  let dur = 0;
  let icon = <NavLiveIcon />;
  if (item?.type === 'song') {
    ({ title, artist: subtitle, thumb, yt, start, dur } = item);
    icon = <MusicIcon />;
  } else if (engine.evergreen) {
    ({ title, artist: subtitle, thumb, yt, start, dur } = engine.evergreen);
    icon = <MusicIcon />;
  } else if (item?.type === 'host') {
    title = t('nowPlaying.host', { name: channel?.host.name ?? 'Hope' });
    subtitle = t('host.aiNote');
    ({ start, dur } = item);
    icon = <MicIcon />;
  } else if (item?.type === 'contrib') {
    title = t('nowPlaying.contrib', { name: item.name });
    subtitle = item.place;
    ({ start, dur } = item);
    icon = <MicIcon />;
  } else if (item) {
    title = item.type === 'silence' || item.type === 'stage' ? item.label[lang] : t('nowPlaying.jingle');
    ({ start, dur } = item);
  }
  const request = item?.type === 'song' ? item.request : null;
  const pos = dur > 0 ? Math.min(dur, Math.max(0, now - start)) : 0;
  const songId = item?.type === 'song' ? item.id : null;

  if (!title) return null;
  return (
    <div className="track-row">
      <div className="cover">{thumb ? <CdnImg src={thumb} className="h-full w-full object-cover" /> : icon}</div>
      <div className="track">
        {/* The fallback loop says so: this is not the live program. */}
        <span className="eyebrow">
          <LevelsIcon />
          <span>{engine.mode === 'evergreen' ? t('stage.evergreen') : t('nowPlaying.eyebrow')}</span>
        </span>
        <strong>
          {yt ? (
            <a href={`https://www.youtube.com/watch?v=${yt}`} target="_blank" rel="noopener noreferrer" title={t('nowPlaying.openYouTube', { title })}>
              {title}
            </a>
          ) : (
            title
          )}
        </strong>
        <small>
          {subtitle}
          {request && (
            <span className="track-request">
              {' · '}
              {request.place ? t('nowPlaying.requestedByFrom', { name: request.name, place: request.place }) : t('nowPlaying.requestedBy', { name: request.name })}
            </span>
          )}
        </small>
      </div>
      {dur > 0 && (
        // Live radio has one position for everyone: shown, not seekable.
        <div className="track-progress" role="group" aria-label={t('nowPlaying.position', { elapsed: clockDuration(pos), duration: clockDuration(dur) })}>
          <time aria-hidden="true">{clockDuration(pos)}</time>
          <div className="song-progress" aria-hidden="true">
            <span style={{ width: `${(pos / dur) * 100}%` }} />
          </div>
          <time aria-hidden="true">{clockDuration(dur)}</time>
        </div>
      )}
      {songId && <Reactions key={songId} markId={`item:${songId}`} variant="song" onSend={(kind) => react(songId, kind)} />}
    </div>
  );
}
