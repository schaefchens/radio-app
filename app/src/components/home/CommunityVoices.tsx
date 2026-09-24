import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import type { Lang, ReactionKind, Voice } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { reactVoice } from '@/lib/radio';
import { ago, countryName } from '@/lib/format';
import { useServerNow } from './useServerNow';
import { ArrowRightIcon, HeartIcon, PrayIcon, SmileIcon } from '@/components/common/icons';

const MORE: ReactionKind[] = ['smile', 'raise', 'peace', 'fire'];
const EMOJI: Record<ReactionKind, string> = { heart: '❤️', pray: '🙏', smile: '😊', raise: '🙌', peace: '🕊️', fire: '🔥' };
const TINTS = ['from-pink-300 to-rose-200', 'from-sky-300 to-indigo-200', 'from-violet-300 to-fuchsia-200', 'from-amber-200 to-orange-200', 'from-emerald-200 to-teal-200'];

/** Voices from the community: react once and the voice makes room for the next. */
export function CommunityVoices({ voices: extra }: { voices?: Voice[] }) {
  const { t } = useTranslation();
  const engine = useRadio((s) => s.engine);
  const answered = useRadio((s) => s.answered);
  const [leaving, setLeaving] = useState<Record<string, true>>({});
  const all = [...(extra ?? []), ...engine.voices];
  const seen = new Set<string>();
  const visible = all.filter((v) => !answered[v.id] && !seen.has(v.id) && seen.add(v.id)).slice(0, 3);

  const answer = (v: Voice, kind: ReactionKind): void => {
    setLeaving((l) => ({ ...l, [v.id]: true }));
    window.setTimeout(() => reactVoice(v.id, kind), 420);
  };

  return (
    <section className="flex flex-col gap-2">
      <div className="flex items-center justify-between px-1">
        <h2 className="text-lg font-semibold">{t('voices.title')}</h2>
        <Link to="/chat" className="inline-flex items-center gap-1 text-sm text-brand-bright hover:underline">
          {t('voices.seeMore')} <ArrowRightIcon size={16} />
        </Link>
      </div>
      {visible.length === 0 && <p className="card px-4 py-3 text-sm text-ink-muted">{t('voices.empty')}</p>}
      {visible.map((v, i) => (
        <VoiceCard key={v.id} voice={v} tint={TINTS[i % TINTS.length]!} leaving={!!leaving[v.id]} onAnswer={(k) => answer(v, k)} />
      ))}
    </section>
  );
}

function VoiceCard({ voice, tint, leaving, onAnswer }: { voice: Voice; tint: string; leaving: boolean; onAnswer: (k: ReactionKind) => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const now = useServerNow(30_000);
  const [more, setMore] = useState(false);
  const initials = voice.name.split(/\s+/).map((p) => p[0] ?? '').join('').slice(0, 2).toUpperCase() || '?';
  return (
    <div className={clsx('card relative flex items-center gap-3 px-3 py-2.5', leaving ? 'animate-fade-out' : 'animate-fly-in')}>
      <div className={clsx('flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-sm font-bold text-night-deep', tint)}>
        {initials}
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-xs text-ink-muted">
          <span className="font-semibold text-brand-bright">{voice.name}</span>
          {voice.country && ` · ${countryName(voice.country, lang)}`}
          {voice.at > 0 && ` · ${ago(voice.at, now, lang)}`}
        </p>
        <p className="line-clamp-2 text-sm text-ink">{voice.text}</p>
      </div>
      <div className="flex shrink-0 items-center gap-1.5">
        <button type="button" aria-label={t('reactions.heart')} onClick={() => onAnswer('heart')} className="rounded-xl border border-night-line/30 p-2.5 text-ink-muted transition-colors hover:border-heart/50 hover:bg-heart/15 hover:text-heart">
          <HeartIcon size={20} />
        </button>
        <button type="button" aria-label={t('reactions.pray')} onClick={() => onAnswer('pray')} className="rounded-xl border border-night-line/30 p-2.5 text-ink-muted transition-colors hover:border-brand/60 hover:bg-brand/20 hover:text-brand-bright">
          <PrayIcon size={20} />
        </button>
        <button type="button" aria-label={t('voices.more')} aria-expanded={more} onClick={() => setMore((m) => !m)} className="rounded-full border border-night-line/30 p-1.5 text-ink-muted hover:text-ink">
          <SmileIcon size={16} />
        </button>
      </div>
      {more && (
        <div className="absolute -top-11 right-2 z-10 flex gap-1 rounded-2xl border border-night-line/40 bg-night-deep/95 p-1.5 shadow-card">
          {MORE.map((k) => (
            <button key={k} type="button" aria-label={t(`reactions.${k}`)} onClick={() => onAnswer(k)} className="rounded-xl px-2 py-1 text-lg hover:bg-night-raised">
              {EMOJI[k]}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
