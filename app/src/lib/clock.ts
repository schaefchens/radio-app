/**
 * The shared clock. Everything the radio does is a function of server time,
 * so the offset between this device and the server is estimated once at
 * start (several /api/time samples, keeping the one with the shortest round
 * trip — its midpoint is the best guess), refreshed by every pulse answer, and
 * re-estimated when the app returns to the foreground.
 *
 * If the API is down, the Date header of any program file still gives the
 * server time to the second — worse, but the radio keeps playing.
 */

let offset = 0;
let quality = Number.POSITIVE_INFINITY; // RTT of the sample the offset came from

export function serverNow(): number {
  return Date.now() + offset;
}

export function clockOffset(): number {
  return offset;
}

export interface TimeSample {
  t0: number;
  t1: number;
  server: number;
}

/** Offset from samples: the midpoint of the fastest round trip wins. */
export function offsetFrom(samples: TimeSample[]): { offset: number; rtt: number } | null {
  let best: TimeSample | null = null;
  for (const s of samples) if (!best || s.t1 - s.t0 < best.t1 - best.t0) best = s;
  if (!best) return null;
  return { offset: best.server - (best.t0 + best.t1) / 2, rtt: best.t1 - best.t0 };
}

/** Accept a sample only if it is at least as precise as what we have (or ours is stale). */
export function accept(sample: TimeSample, force = false): void {
  const r = offsetFrom([sample]);
  if (!r) return;
  if (force || r.rtt <= quality * 1.5) {
    offset = r.offset;
    quality = Math.max(r.rtt, 1);
  }
}

export async function syncClock(fetchTime: () => Promise<number>, samples = 4): Promise<boolean> {
  const got: TimeSample[] = [];
  for (let i = 0; i < samples; i++) {
    const t0 = Date.now();
    try {
      const server = await fetchTime();
      got.push({ t0, t1: Date.now(), server });
    } catch {
      /* one lost sample is fine */
    }
  }
  const r = offsetFrom(got);
  if (!r) return false;
  offset = r.offset;
  quality = Math.max(r.rtt, 1);
  return true;
}

/** Fallback when /api is unreachable: the HTTP Date header (1 s resolution). */
export function syncFromDateHeader(date: string | null, t0: number, t1: number): void {
  if (!date || quality < 1500) return;
  const server = Date.parse(date);
  if (Number.isNaN(server)) return;
  // The header is truncated to the second; +500 ms is its expected value.
  accept({ t0, t1, server: server + 500 }, quality === Number.POSITIVE_INFINITY);
}

/** Test seam. */
export function setClockOffset(ms: number): void {
  offset = ms;
  quality = 1;
}
