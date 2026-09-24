import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { DayProgram, Lang } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { addDays, localDate, stationDate } from '@/lib/format';
import { DayBlocks } from '@/components/home/TodayProgram';
import { useServerNow } from '@/components/home/useServerNow';
import { useDays } from '@/components/schedule/useDays';
import { PlayedList } from '@/components/schedule/PlayedList';
import { ProgramSheet } from '@/components/schedule/ProgramSheet';
import { WeekGrid } from '@/components/schedule/WeekGrid';

const listenerTz = (): string => Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC';

/**
 * The plan, not the playlist: which programs run when (in the listener's own
 * time), and — for today and yesterday — what already played.
 */
export function SchedulePage() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const channelId = useRadio((s) => s.engine.channel);
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === channelId));
  const now = useServerNow(60_000);
  const [tab, setTab] = useState<'day' | 'week'>('day');
  const [offset, setOffset] = useState(0);
  const [details, setDetails] = useState<DayProgram | null>(null);

  const tz = channel?.tz ?? 'Europe/Berlin';
  const today = stationDate(now, tz);
  const chips = [-1, 0, 1, 2, 3, 4, 5, 6];
  const dates = chips.map((d) => addDays(today, d));
  const days = useDays(channelId, dates);
  const selected = addDays(today, offset);
  const day = days[selected];

  const chipLabel = (d: number, date: string): string => {
    if (d === -1) return t('schedule.yesterday');
    if (d === 0) return t('schedule.today');
    if (d === 1) return t('schedule.tomorrow');
    return localDate(Date.parse(`${date}T12:00:00Z`), lang);
  };

  return (
    <div className="flex flex-col gap-4 pt-2">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('schedule.title')}</h1>
        <div className="flex rounded-xl border border-night-line/30 bg-night-deep/50 p-1 text-sm" role="tablist">
          {(['day', 'week'] as const).map((k) => (
            <button
              key={k}
              type="button"
              role="tab"
              aria-selected={tab === k}
              onClick={() => setTab(k)}
              className={clsx('rounded-lg px-4 py-1.5', tab === k ? 'bg-brand text-white' : 'text-ink-muted hover:text-ink')}
            >
              {t(`schedule.${k}`)}
            </button>
          ))}
        </div>
      </div>

      <p className="text-xs text-ink-muted">
        {t('schedule.localTime', { tz: listenerTz() })} {t('schedule.surprise')}
      </p>

      {tab === 'day' ? (
        <>
          <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
            {chips.map((d, i) => (
              <button
                key={d}
                type="button"
                onClick={() => setOffset(d)}
                className={clsx(
                  'shrink-0 rounded-full border px-4 py-1.5 text-sm',
                  offset === d ? 'border-brand bg-brand/20 text-ink' : 'border-night-line/30 text-ink-muted hover:text-ink',
                )}
              >
                {chipLabel(d, dates[i]!)}
              </button>
            ))}
          </div>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
            <div>{day === null ? <p className="card px-4 py-3 text-sm text-ink-muted">{t('schedule.noDay')}</p> : <DayBlocks day={day ?? null} />}</div>
            {day && offset <= 0 && <PlayedList day={day} />}
          </div>
        </>
      ) : (
        <WeekGrid days={dates.slice(1).map((d) => days[d])} onProgram={setDetails} />
      )}

      <ProgramSheet program={details} onClose={() => setDetails(null)} />
    </div>
  );
}
