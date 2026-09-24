import { MINUTE_MS } from './constants.ts';

/**
 * Where the generator publishes, relative to the site root. File names are UTC
 * on purpose: a station-local HHMM repeats in the autumn DST hour, and an
 * immutable file cannot mean two different minutes.
 */

const pad = (n: number, w = 2): string => String(n).padStart(w, '0');

export function floorMinute(t: number): number {
  return Math.floor(t / MINUTE_MS) * MINUTE_MS;
}

export function slotPath(channel: string, t: number): string {
  const d = new Date(floorMinute(t));
  const day = `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}`;
  const hm = `${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}`;
  return `program/${channel}/slots/${day}/${hm}.json`;
}

/** A station-local calendar date, `YYYY-MM-DD`. */
export function dayPath(channel: string, date: string): string {
  return `program/${channel}/days/${date}.json`;
}

export function livePath(channel: string): string {
  return `program/${channel}/live.json`;
}

export const CHANNELS_PATH = 'program/channels.json';
