import { useMemo } from 'react';
import type { WallEntry } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useBlocks } from '@/store/blocks';
import { prayingFirst } from '@/lib/prayerWall';
import { visibleWall } from '@/lib/blocking';

/**
 * The wall, with the requests the host is praying for right now first —
 * `now` while one of them is on the wall ("Praying now").
 */
export function usePrayingWall(): { wall: WallEntry[]; praying: readonly string[]; now: boolean } {
  const all = useRadio((s) => s.engine.wall);
  const praying = useRadio((s) => s.engine.praying);
  // Requests this device reported are gone for it at once.
  const hidden = useBlocks((s) => s.hidden);
  const wall = useMemo(() => visibleWall(all, hidden), [all, hidden]);
  const ordered = useMemo(() => prayingFirst(wall, praying), [wall, praying]);
  return { wall: ordered, praying, now: ordered !== wall };
}
