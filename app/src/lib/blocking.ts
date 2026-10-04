import type { ChatMessage, Voice, WallEntry } from '@arche/shared';
import type { BlockedUser } from '@/store/blocks';

/**
 * Filters for what a listener blocked or reported. Each returns the array it
 * was given when nothing is filtered out: a new array on every call would
 * make zustand selectors and memos loop (CLAUDE.md).
 */

/** The mark on someone's community voices: SHA-256 of their public id, as Presence::voiceTag. */
export async function voiceTag(sub: string): Promise<string> {
  const bytes = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(`by:${sub}`)));
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('').slice(0, 12);
}

function keep<T>(items: T[], drop: (item: T) => boolean): T[] {
  return items.some(drop) ? items.filter((i) => !drop(i)) : items;
}

export function visibleMessages(messages: ChatMessage[], blocked: readonly BlockedUser[]): ChatMessage[] {
  if (blocked.length === 0) return messages;
  const subs = new Set(blocked.map((u) => u.sub));
  return keep(messages, (m) => subs.has(m.sub));
}

/** Voices: by their author's mark (published ones) or their id (reported ones). */
export function visibleVoices(voices: Voice[], blocked: readonly BlockedUser[], hidden: readonly string[]): Voice[] {
  if (blocked.length === 0 && hidden.length === 0) return voices;
  const tags = new Set(blocked.map((u) => u.tag));
  const gone = new Set(hidden);
  return keep(voices, (v) => gone.has(v.id) || (v.by !== undefined && tags.has(v.by)));
}

export function visibleWall(wall: WallEntry[], hidden: readonly string[]): WallEntry[] {
  if (hidden.length === 0) return wall;
  const gone = new Set(hidden);
  return keep(wall, (e) => gone.has(e.id));
}
