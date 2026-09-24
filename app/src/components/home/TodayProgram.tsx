import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { DayFile, Lang } from '@arche/shared';
import { localTime } from '@/lib/format';
import { useServerNow } from './useServerNow';

/** The plan for one day, as blocks in the listener's local time. */
export function DayBlocks({ day, compact = false }: { day: DayFile | null; compact?: boolean }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const now = useServerNow(30_000);
  if (!day) return <p className="px-1 text-sm text-ink-muted">{t('common.loading')}</p>;
  return (
    <ol className="flex flex-col gap-2">
      {day.blocks.map((b) => {
        const p = day.programs[b.p];
        const current = now >= b.start && now < b.end;
        const past = now >= b.end;
        return (
          <li
            key={b.start}
            className={clsx(
              'flex gap-3 rounded-xl border px-3 py-2',
              current ? 'border-brand/60 bg-brand/15 shadow-glow' : 'border-night-line/20 bg-night-deep/40',
              past && 'opacity-60',
            )}
          >
            <div className="w-24 shrink-0 text-xs tabular-nums text-ink-muted">
              {localTime(b.start, lang)}–{localTime(b.end, lang)}
              {current && <span className="mt-1 block font-semibold text-live">● {t('today.now')}</span>}
            </div>
            <div className="min-w-0">
              <p className="truncate font-semibold" style={{ color: p?.color }}>
                {p?.title[lang] ?? b.p}
              </p>
              {!compact && p?.subtitle[lang] && <p className="text-sm text-ink-muted">{p.subtitle[lang]}</p>}
              {!compact && p?.description[lang] && <p className="mt-1 text-xs text-ink-faint">{p.description[lang]}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
