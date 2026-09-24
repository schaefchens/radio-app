import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';
import { VIDEOS } from '../fake-youtube.mjs';

const SHIM = readFileSync(new URL('./youtube-shim.js', import.meta.url), 'utf8');

/** One entry of window.__yt.calls (see youtube-shim.js). */
export interface YtCall {
  fn: 'create' | 'state' | 'load' | 'cue' | 'play' | 'pause' | 'stop' | 'seek';
  at: number;
  videoId?: string;
  startSeconds?: number;
  seconds?: number;
  state?: number;
  host?: string;
}

export interface YtNow {
  videoId: string | null;
  time: number;
  state: number;
  at: number;
}

const GOOGLE = /^https:\/\/([a-z0-9-]+\.)*(youtube\.com|youtube-nocookie\.com|ytimg\.com|googlevideo\.com|google\.com|doubleclick\.net)\//;

/**
 * Serve the fake IFrame API and answer oEmbed from the fixture videos; every
 * other request to YouTube or Google is refused, so a run never leaves the
 * machine. Returns the list of Google URLs the page asked for, in order.
 */
export async function fakeYouTube(page: Page): Promise<string[]> {
  const requested: string[] = [];
  await page.route(GOOGLE, async (route) => {
    const url = new URL(route.request().url());
    requested.push(url.href);
    if (url.hostname === 'www.youtube.com' && url.pathname === '/iframe_api') {
      return route.fulfill({ status: 200, contentType: 'text/javascript', body: SHIM });
    }
    if (url.hostname === 'www.youtube.com' && url.pathname === '/oembed') {
      const id = /[?&]v=([A-Za-z0-9_-]{11})/.exec(url.searchParams.get('url') ?? '')?.[1];
      const v = VIDEOS.find((x) => x.id === id);
      const headers = { 'Access-Control-Allow-Origin': '*' };
      if (!v) return route.fulfill({ status: 404, headers, body: 'Not Found' });
      if (!v.embeddable) return route.fulfill({ status: 401, headers, body: 'Unauthorized' });
      return route.fulfill({
        status: 200,
        headers,
        contentType: 'application/json',
        body: JSON.stringify({ title: v.title, author_name: v.channel, thumbnail_url: `https://i.ytimg.com/vi/${v.id}/hqdefault.jpg`, type: 'video' }),
      });
    }
    return route.abort();
  });
  return requested;
}

export function ytCalls(page: Page): Promise<YtCall[]> {
  return page.evaluate(() => (window as unknown as { __yt?: { calls: YtCall[] } }).__yt?.calls ?? []);
}

/** What the (single) player is doing right now, stamped with the page's clock. */
export function ytNow(page: Page): Promise<YtNow | null> {
  return page.evaluate(() => {
    const p = (window as unknown as { __yt?: { players: { videoId: string | null; state: number; getCurrentTime(): number }[] } }).__yt?.players[0];
    return p ? { videoId: p.videoId, time: p.getCurrentTime(), state: p.state, at: Date.now() } : null;
  });
}

export const YT_PLAYING = 1;
