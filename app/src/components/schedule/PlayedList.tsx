import { useTranslation } from 'react-i18next';
import type { DayFile, Lang } from '@arche/shared';
import { localTime } from '@/lib/format';
import { CdnImg } from '@/components/common/CdnImg';
import { MicIcon, MusicIcon } from '@/components/common/icons';

/** What already played on a day — the plan itself never reveals what will. */
export function PlayedList({ day }: { day: DayFile }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const played = [...day.played].reverse();
  return (
    <section className="flex flex-col gap-2">
      <h2 className="px-1 text-lg font-semibold">{t('schedule.played')}</h2>
      {played.length === 0 && <p className="card px-4 py-3 text-sm text-ink-muted">{t('schedule.nothingPlayed')}</p>}
      <ul className="flex flex-col gap-2">
        {played.map((p) => (
          <li key={`${p.start}-${p.title}`} className="card flex items-center gap-3 px-3 py-2">
            <div className="flex h-11 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-night-deep text-ink-muted">
              {p.thumb ? <CdnImg src={p.thumb} className="h-full w-full object-cover" /> : p.type === 'song' ? <MusicIcon /> : <MicIcon />}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate font-medium">{p.title}</p>
              <p className="truncate text-xs text-ink-muted">
                {p.artist}
                {p.artist && day.programs[p.p] ? ' · ' : ''}
                {day.programs[p.p]?.title[lang] ?? ''}
              </p>
            </div>
            <span className="shrink-0 text-xs tabular-nums text-ink-muted">{localTime(p.start, lang)}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}
