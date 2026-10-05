import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { useServerNow } from './useServerNow';
import { speakingHost } from '@/lib/host';
import { BookIcon, MicIcon, MusicIcon, NavLiveIcon } from '@/components/common/icons';

/**
 * What is on air, as the song row of the player card and the big stage's bar
 * show it: the cover, the title and who it is by (and who asked for it), how
 * far it is, the reactions. One reading for both, so they never disagree.
 */
export interface NowPlaying {
  title: string;
  subtitle: string;
  /** "Requested by …" / "Suggested by …", when a listener's. */
  request: string | null;
  /** Over the title: "Now playing", the video's kind, or the fallback loop. */
  eyebrow: string;
  thumb: string | null;
  yt: string | null;
  icon: ReactNode;
  /** Where the bar is (ms) and how long it is; 0 shows no bar. */
  pos: number;
  dur: number;
  /** The song or video to react to. */
  songId: string | null;
}

export function useNowPlaying(): NowPlaying | null {
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
  // A video of a video program (a preaching, a testimony, a mission video, a
  // film) is named by its kind — never "Now playing" like a song.
  const video = item?.type === 'song' && item.kind !== 'song' ? item.kind : null;
  if (item?.type === 'song') {
    ({ title, artist: subtitle, thumb, yt, start, dur } = item);
    icon = video ? <BookIcon /> : <MusicIcon />;
  } else if (engine.evergreen) {
    ({ title, artist: subtitle, thumb, yt, start, dur } = engine.evergreen);
    icon = <MusicIcon />;
  } else if (item?.type === 'host') {
    // A listener's request or prayer, read out in the host's voice: theirs, not the host's words.
    const theirs = item.kind === 'reading' || item.kind === 'intercession';
    const host = speakingHost(item, engine.lastHost, channel);
    title = theirs ? t(item.kind === 'reading' ? 'stage.prayingNow' : 'stage.listenerPrayer') : t('nowPlaying.host', { name: host.name });
    subtitle = theirs ? t('nowPlaying.readBy', { name: host.name }) : t('host.aiNote');
    // ElevenLabs asks for a credit where its voice is heard.
    if (host.voice === 'elevenlabs') subtitle += ` · ${t('host.voiceBy', { by: 'elevenlabs.io' })}`;
    ({ start, dur } = item);
    icon = <MicIcon />;
  } else if (item?.type === 'contrib') {
    // Without a name the sender stays anonymous: no " shares", and no place on its own.
    title = item.name ? t('nowPlaying.contrib', { name: item.name }) : t('nowPlaying.contribAnon');
    subtitle = item.name ? item.place : '';
    ({ start, dur } = item);
    icon = <MicIcon />;
  } else if (item?.type === 'bed') {
    title = t('nowPlaying.bed');
    subtitle = item.label[lang];
    ({ start, dur } = item);
    icon = <MusicIcon />;
  } else if (item) {
    title = item.type === 'silence' || item.type === 'stage' ? item.label[lang] : t('nowPlaying.jingle');
    ({ start, dur } = item);
    // Silent prayer is planned in 60 s pieces: a bar starting over every
    // minute would read as many short items.
    if (item.type === 'silence' && engine.program?.format === 'prayer') dur = 0;
  }
  if (!title) return null;

  const asked = item?.type === 'song' ? item.request : null;
  const request = !asked
    ? null
    : video
      ? asked.place
        ? t('nowPlaying.suggestedByFrom', { name: asked.name, place: asked.place })
        : t('nowPlaying.suggestedBy', { name: asked.name })
      : asked.place
        ? t('nowPlaying.requestedByFrom', { name: asked.name, place: asked.place })
        : t('nowPlaying.requestedBy', { name: asked.name });
  return {
    title,
    subtitle,
    request,
    // The fallback loop says so: this is not the live program.
    eyebrow: engine.mode === 'evergreen' ? t('stage.evergreen') : video ? t(`nowPlaying.${video}`) : t('nowPlaying.eyebrow'),
    thumb,
    yt,
    icon,
    pos: dur > 0 ? Math.min(dur, Math.max(0, now - start)) : 0,
    dur,
    songId: item?.type === 'song' ? item.id : null,
  };
}
