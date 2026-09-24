import { useEffect, useState } from 'react';
import { serverNow } from '@/lib/clock';

/** Server time, re-rendered every `ms` (progress bars, "x min ago"). */
export function useServerNow(ms = 1000): number {
  const [now, setNow] = useState(serverNow);
  useEffect(() => {
    const id = setInterval(() => setNow(serverNow()), ms);
    return () => clearInterval(id);
  }, [ms]);
  return now;
}
