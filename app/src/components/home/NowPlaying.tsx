import { useTranslation } from 'react-i18next';
import { useState } from 'react';
import clsx from 'clsx';
import type { Lang, ReactionKind } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { react } from '@/lib/radio';
import { clockDuration } from '@/lib/format';
import { useServerNow } from './useServerNow';
import { CdnImg } from '@/components/common/CdnImg';
import { HeartIcon, MicIcon, MusicIcon, PrayIcon, RadioIcon } from '@/components/common/icons';

export function NowPlaying() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const engine = useRadio((s) => s.engine);
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === engine.channel));
  const now = useServerNow(500);
  const [reacted, setReacted] = useState<Record<string, ReactionKind>>({});
  const item = engine.item;

  let title = '';
  let subtitle = '';
  let thumb: string | null = null;
  let start = 0;
  let dur = 0;
  let icon = <RadioIcon size={28} />;
  if (item?.type === 'song') {
    title = item.title;
    subtitle = item.artist;
    thumb = item.thumb;
    ({ start, dur } = item);
    icon = <MusicIcon size={28} />;
  } else if (engine.evergreen) {
    ({ title, artist: subtitle, thumb, start, dur } = engine.evergreen);
    icon = <MusicIcon size={28} />;
  } else if (item?.type === 'host') {
    title = t('nowPlaying.host', { name: channel?.host.name ?? 'Hope' });
    subtitle = t('host.aiNote');
    ({ start, dur } = item);
    icon = <MicIcon size={28} />;
  } else if (item?.type === 'contrib') {
    title = t('nowPlaying.contrib', { name: item.name });
    subtitle = item.place;
    ({ start, dur } = item);
    icon = <MicIcon size={28} />;
  } else if (item) {
    title = item.type === 'silence' || item.type === 'stage' ? item.label[lang] : t('nowPlaying.jingle');
    ({ start, dur } = item);
  }
  const request = item?.type === 'song' ? item.request : null;
  const pos = dur > 0 ? Math.min(dur, Math.max(0, now - start)) : 0;
  const songId = item?.type === 'song' ? item.id : null;

  const tap = (kind: ReactionKind): void => {
    if (!songId) return;
    react(songId, kind);
    setReacted((r) => ({ ...r, [songId]: kind }));
  };

  if (!title) return null;
  return (
    <div className="card flex items-center gap-4 p-3">
      <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-night-deep text-ink-muted sm:h-20 sm:w-28">
        {thumb ? <CdnImg src={thumb} className="h-full w-full object-cover" /> : icon}
      </div>
      <div className="min-w-0 flex-1">
        {/* The fallback loop says so: this is not the live program. */}
        <p className="eyebrow">{engine.mode === 'evergreen' ? t('stage.evergreen') : t('nowPlaying.eyebrow')}</p>
        <p className="truncate text-lg font-semibold leading-tight">{title}</p>
        <p className="truncate text-sm text-ink-muted">
          {subtitle}
          {request && (
            <span className="text-brand-bright">
              {' · '}
              {request.place ? t('nowPlaying.requestedByFrom', { name: request.name, place: request.place }) : t('nowPlaying.requestedBy', { name: request.name })}
            </span>
          )}
        </p>
        {dur > 0 && (
          <div className="mt-2 flex items-center gap-3 text-xs tabular-nums text-ink-muted">
            <span>{clockDuration(pos)}</span>
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-night-line/30">
              <div className="h-full rounded-full bg-brand transition-[width] duration-500" style={{ width: `${(pos / dur) * 100}%` }} />
            </div>
            <span>{clockDuration(dur)}</span>
          </div>
        )}
      </div>
      {songId && (
        <div className="flex shrink-0 flex-col gap-2" aria-label={t('nowPlaying.react')}>
          <button
            type="button"
            onClick={() => tap('heart')}
            aria-label={t('reactions.heart')}
            className={clsx('rounded-xl border p-2 transition-colors', reacted[songId] === 'heart' ? 'border-heart/50 bg-heart/20 text-heart' : 'border-night-line/30 text-ink-muted hover:text-ink')}
          >
            <HeartIcon size={18} filled={reacted[songId] === 'heart'} />
          </button>
          <button
            type="button"
            onClick={() => tap('pray')}
            aria-label={t('reactions.pray')}
            className={clsx('rounded-xl border p-2 transition-colors', reacted[songId] === 'pray' ? 'border-brand/60 bg-brand/25 text-brand-bright' : 'border-night-line/30 text-ink-muted hover:text-ink')}
          >
            <PrayIcon size={18} filled={reacted[songId] === 'pray'} />
          </button>
        </div>
      )}
    </div>
  );
}
