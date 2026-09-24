import { useEffect, useState } from 'react';
import type { DayFile } from '@arche/shared';
import { fetchDay } from '@/lib/programFiles';

/**
 * Day files for a channel, fetched once per (channel, date) and kept for the
 * page's lifetime. `null` = not there (yet); undefined = still loading.
 */
export function useDays(channel: string, dates: string[]): Record<string, DayFile | null | undefined> {
  const [days, setDays] = useState<Record<string, DayFile | null>>({});
  const key = `${channel}|${dates.join(',')}`;
  useEffect(() => {
    if (!channel) return;
    let live = true;
    for (const date of dates) {
      void fetchDay(channel, date).then((d) => {
        if (live) setDays((prev) => ({ ...prev, [`${channel}|${date}`]: d }));
      });
    }
    return () => {
      live = false;
    };
    // `key` covers channel and dates; the array identity changes every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  const out: Record<string, DayFile | null | undefined> = {};
  for (const date of dates) out[date] = days[`${channel}|${date}`];
  return out;
}
