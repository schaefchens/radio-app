import {
  CHANNELS_PATH,
  MINUTE_MS,
  SLOT_WALKBACK_MINUTES,
  dayPath,
  floorMinute,
  livePath,
  parseChannelsFile,
  parseDayFile,
  parseEvergreenFile,
  parseLiveFile,
  parseSlotFile,
  slotPath,
  type ChannelsFile,
  type DayFile,
  type EvergreenFile,
  type LiveFile,
  type SlotFile,
} from '@arche/shared';
import { fetchStatic } from './cdn';
import { syncFromDateHeader } from './clock';

/**
 * Fetching the static program files — from the CDN when there is one, else
 * (or when it fails) from the site. They are the only thing the radio needs
 * to play; everything else (API, realtime) is optional.
 */

async function getJson(path: string, cache: RequestCache = 'default'): Promise<unknown | null> {
  let res: Response;
  try {
    const got = await fetchStatic('/' + path, { cache });
    res = got.res;
    if (got.fromOrigin) syncFromDateHeader(res.headers.get('Date'), got.t0, Date.now());
  } catch {
    return null;
  }
  if (!res.ok) return null;
  try {
    return await res.json();
  } catch {
    return null;
  }
}

export async function fetchSlot(channel: string, t: number): Promise<SlotFile | null> {
  return parseSlotFile(await getJson(slotPath(channel, t)));
}

/**
 * The file for the minute of `t`, or — when it is missing (generator late,
 * outage) — the newest earlier one: each file covers the next ten minutes,
 * so a file from a few minutes ago usually still describes now.
 */
export async function fetchSlotWalkingBack(channel: string, t: number): Promise<SlotFile | null> {
  const m = floorMinute(t);
  for (let back = 0; back <= SLOT_WALKBACK_MINUTES; back++) {
    const slot = await fetchSlot(channel, m - back * MINUTE_MS);
    if (slot) return slot;
  }
  return null;
}

export async function fetchLive(channel: string): Promise<LiveFile | null> {
  return parseLiveFile(await getJson(livePath(channel), 'no-cache'));
}

export async function fetchChannels(): Promise<ChannelsFile | null> {
  return parseChannelsFile(await getJson(CHANNELS_PATH, 'no-cache'));
}

export async function fetchDay(channel: string, date: string): Promise<DayFile | null> {
  return parseDayFile(await getJson(dayPath(channel, date), 'no-cache'));
}

export async function fetchEvergreen(url: string): Promise<EvergreenFile | null> {
  return parseEvergreenFile(await getJson(url.replace(/^\//, '')));
}
