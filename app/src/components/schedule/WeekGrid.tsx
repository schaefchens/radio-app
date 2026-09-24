import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { DayFile, DayProgram, Lang } from '@arche/shared';
import { localDate, localTime } from '@/lib/format';
import { useServerNow } from '@/components/home/useServerNow';

/** Seven station days side by side; bar height follows each block's length. */
export function WeekGrid({ days, onProgram }: { days: (DayFile | null | undefined)[]; onProgram: (p: DayProgram) => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const now = useServerNow(60_000);
  return (
    <div className="flex flex-col gap-2">
      <p className="px-1 text-xs text-ink-muted">{t('schedule.weekHint')}</p>
      <div className="grid grid-cols-7 gap-1.5 sm:gap-2">
        {days.map((day, i) => {
          if (!day || day.blocks.length === 0) {
            return <div key={i} className="card-inset h-[480px] animate-pulse-soft" aria-hidden />;
          }
          const start = day.blocks[0]!.start;
          const total = day.blocks[day.blocks.length - 1]!.end - start;
          return (
            <div key={day.date} className="flex min-w-0 flex-col gap-1">
              <p className="truncate text-center text-[0.7rem] font-semibold text-ink-muted sm:text-xs">
                {localDate(start + 12 * 3_600_000, lang, { weekday: 'short', day: 'numeric' })}
              </p>
              <div className="relative flex h-[480px] flex-col overflow-hidden rounded-xl border border-night-line/20 bg-night-deep/40">
                {day.blocks.map((b) => {
                  const p = day.programs[b.p];
                  const h = ((b.end - b.start) / total) * 100;
                  const current = now >= b.start && now < b.end;
                  return (
                    <button
                      key={b.start}
                      type="button"
                      onClick={() => p && onProgram(p)}
                      title={`${localTime(b.start, lang)}–${localTime(b.end, lang)} ${p?.title[lang] ?? ''}`}
                      className={clsx('relative min-h-[2px] w-full overflow-hidden border-b border-night/60 text-left transition-opacity hover:opacity-90', current && 'ring-2 ring-inset ring-live')}
                      style={{ height: `${h}%`, background: `${p?.color ?? '#2f7bff'}55` }}
                    >
                      {h > 6 && (
                        <span className="block truncate px-1 pt-0.5 text-[0.6rem] font-semibold leading-tight text-ink sm:text-[0.7rem]">
                          {p?.title[lang] ?? b.p}
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
