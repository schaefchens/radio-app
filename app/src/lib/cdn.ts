/**
 * BunnyCDN in front of /program and /media: a pull zone with this site as its
 * origin, so the edge holds exactly the files the site has, as long as their
 * Cache-Control allows. The session names the CDN; the name is remembered, so
 * a later start without the API still reads from the edge.
 *
 * The site stays the fallback for every read. A CDN that fails — network,
 * CORS, a timeout, an answer other than the file or the origin's own 404 (a
 * suspended zone answers 403) — is skipped for a few minutes: the radio must
 * keep playing. A 404 is passed through from the origin and is final: asking
 * the site again would double its load exactly when the generator is late.
 */

const KEY = 'arche.cdn';
const PAUSE_MS = 5 * 60_000;
const TIMEOUT_MS = 6000;

let base = remembered();
let pausedUntil = 0;

function normalize(v: string): string {
  try {
    const u = new URL(v);
    return u.protocol === 'https:' || u.protocol === 'http:' ? u.origin : '';
  } catch {
    return '';
  }
}

function remembered(): string {
  try {
    return normalize(localStorage.getItem(KEY) ?? '');
  } catch {
    return '';
  }
}

/** From the session config: the CDN's base URL, or '' for none. */
export function setCdnBase(v: string | null | undefined): void {
  base = normalize(v ?? '');
  pausedUntil = 0;
  try {
    if (base) localStorage.setItem(KEY, base);
    else localStorage.removeItem(KEY);
  } catch {
    /* private mode: this start still uses it */
  }
}

function usable(): boolean {
  return base !== '' && Date.now() >= pausedUntil;
}

/** The edge URL of one of our public files; anything else is returned as it is. */
export function cdnUrl(path: string): string {
  if (!usable() || !/^\/(program|media)\//.test(path)) return path;
  return base + path;
}

/** The edge failed: the site answers until the pause is over. */
export function cdnFailed(): void {
  if (base) pausedUntil = Date.now() + PAUSE_MS;
}

export interface StaticResponse {
  res: Response;
  /** The site's own answer: only then is its Date header server time (an edge copy can be hours old). */
  fromOrigin: boolean;
  /** When the request that answered started. */
  t0: number;
}

/** GET one of our static files, from the edge when there is one. Throws only if the site fails too. */
export async function fetchStatic(path: string, init: RequestInit = {}): Promise<StaticResponse> {
  const url = cdnUrl(path);
  if (url !== path) {
    const t0 = Date.now();
    try {
      const signal = typeof AbortSignal.timeout === 'function' ? AbortSignal.timeout(TIMEOUT_MS) : undefined;
      const res = await fetch(url, { ...init, signal });
      if (res.ok || res.status === 404) return { res, fromOrigin: false, t0 };
    } catch {
      /* network, CORS, timeout */
    }
    cdnFailed();
  }
  const t0 = Date.now();
  return { res: await fetch(path, init), fromOrigin: true, t0 };
}

/** Test seam. */
export function resetCdn(): void {
  base = '';
  pausedUntil = 0;
}
