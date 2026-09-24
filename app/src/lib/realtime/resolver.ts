import { ApiError } from '@/lib/api';

/**
 * Wake a realtime node and wait until it is ready (walk-in-the-spirit's
 * serverResolve pattern). The first listener after a quiet spell waits while a
 * Hetzner server boots (~30–40 s); everyone else gets `ready` at once.
 *
 * Polling slows down after the first half minute so a full wait stays inside
 * the endpoint's rate limit (40 per 5 minutes per listener).
 */
export interface WakeResponse {
  status: 'ready' | 'starting' | 'unavailable' | string;
  url?: string;
  token?: string;
  retryMs?: number;
}

export interface ResolverDeps {
  wake: (channel: string, signal: AbortSignal) => Promise<WakeResponse>;
  sleep: (ms: number, signal: AbortSignal) => Promise<void>;
  now: () => number;
}

export const GIVE_UP_MS = 3 * 60_000;
const FAST_PHASE_MS = 30_000;

export class WakeTimeout extends Error {
  constructor() {
    super('realtime_timeout');
  }
}

export class WakeUnavailable extends Error {
  constructor() {
    super('realtime_unavailable');
  }
}

export async function resolveNode(
  channel: string,
  deps: ResolverDeps,
  signal: AbortSignal,
  onWaiting: () => void = () => {},
): Promise<{ url: string; token: string }> {
  const started = deps.now();
  let waited = false;
  while (deps.now() - started < GIVE_UP_MS) {
    if (signal.aborted) throw new DOMException('aborted', 'AbortError');
    let r: WakeResponse | null = null;
    try {
      r = await deps.wake(channel, signal);
    } catch (e) {
      if ((e as Error).name === 'AbortError') throw e;
      // Rate limited or a hiccup: wait longer and ask again.
      if (e instanceof ApiError && e.status !== 429 && e.status !== 0 && e.status < 500) throw e;
    }
    if (r?.status === 'ready' && r.url && r.token) return { url: r.url, token: r.token };
    if (r?.status === 'unavailable') throw new WakeUnavailable();
    if (!waited) {
      waited = true;
      onWaiting();
    }
    const elapsed = deps.now() - started;
    const base = Math.max(3000, r?.retryMs ?? 3000);
    await deps.sleep(elapsed < FAST_PHASE_MS ? base : Math.max(base, 6000), signal);
  }
  throw new WakeTimeout();
}
