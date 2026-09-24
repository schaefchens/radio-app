import type { EvergreenFile, EvergreenTrack } from '@arche/shared';

/**
 * Where the fallback loop is at time t: (t − epoch) mod total. Every client
 * holding the same file lands on the same second without asking anyone —
 * which is the point: this plays when the generator or the network is down.
 */
export function evergreenAt(file: EvergreenFile, t: number): { track: EvergreenTrack; index: number; start: number; offset: number } | null {
  if (file.total <= 0 || file.items.length === 0) return null;
  let pos = (t - file.epoch) % file.total;
  if (pos < 0) pos += file.total;
  for (let i = 0; i < file.items.length; i++) {
    const track = file.items[i]!;
    if (pos < track.dur) return { track, index: i, start: t - pos, offset: pos };
    pos -= track.dur;
  }
  return null;
}
