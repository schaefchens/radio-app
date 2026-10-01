import { useMemo } from 'react';
import type { WallEntry } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { prayingFirst } from '@/lib/prayerWall';

/**
 * The wall, with the requests the host is praying for right now first —
 * `now` while one of them is on the wall ("Praying now").
 */
export function usePrayingWall(): { wall: WallEntry[]; praying: readonly string[]; now: boolean } {
  const wall = useRadio((s) => s.engine.wall);
  const praying = useRadio((s) => s.engine.praying);
  const ordered = useMemo(() => prayingFirst(wall, praying), [wall, praying]);
  return { wall: ordered, praying, now: ordered !== wall };
}
