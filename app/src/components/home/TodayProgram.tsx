import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { DayFile, DayProgram, Lang } from '@arche/shared';
import { localTime } from '@/lib/format';
import { BellIcon } from '@/components/common/icons';
import { useServerNow } from './useServerNow';

interface Props {
  day: DayFile | null;
  compact?: boolean;
  /** A tap on a program opens its details (the schedule). */
  onProgram?: (p: DayProgram) => void;
  /** Programs with a reminder set (store apps): a bell next to the title. */
  reminded?: ReadonlySet<string>;
}

/** The plan for one day, as blocks in the listener's local time. */
export function DayBlocks({ day, compact = false, onProgram, reminded }: Props) {
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
        const content = (
          <>
            <p className="flex min-w-0 items-center gap-1.5 font-semibold" style={{ color: p?.color }}>
              <span className="truncate">{p?.title[lang] ?? b.p}</span>
              {reminded?.has(b.p) && (
                <span className="shrink-0 text-accent" title={t('reminders.on')}>
                  <BellIcon size={14} filled />
                  <span className="sr-only">{t('reminders.on')}</span>
                </span>
              )}
            </p>
            {!compact && p?.subtitle[lang] && <p className="text-sm text-ink-muted">{p.subtitle[lang]}</p>}
            {!compact && p?.description[lang] && <p className="mt-1 text-xs text-ink-faint">{p.description[lang]}</p>}
          </>
        );
        return (
          <li
            key={b.start}
            className={clsx(
              'flex gap-3 rounded-xl border px-3 py-2',
              current ? 'border-accent-fill/60 bg-accent-fill/15 shadow-glow' : 'border-line/20 bg-soft/40',
              past && 'opacity-60',
            )}
          >
            <div className="w-24 shrink-0 text-xs tabular-nums text-ink-muted">
              {localTime(b.start, lang)}–{localTime(b.end, lang)}
              {current && <span className="mt-1 block font-semibold text-live">● {t('today.now')}</span>}
            </div>
            {onProgram && p ? (
              <button type="button" className="min-w-0 flex-1 text-left" onClick={() => onProgram(p)}>
                {content}
              </button>
            ) : (
              <div className="min-w-0">{content}</div>
            )}
          </li>
        );
      })}
    </ol>
  );
}
