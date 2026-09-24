import type { ChatMessage, Lang, Region, RoomInfo } from '@arche/shared';

/**
 * Rooms live in memory only. A node is disposable — it is deleted after ten
 * idle minutes — so nothing here is worth persisting; what matters (highlights,
 * reports, reaction trends) leaves the node in the report.
 */

export const HISTORY_LIMIT = 100;
export const WELCOME_HISTORY = 50;

export interface HistoryEntry {
  msg: ChatMessage;
  likers: Set<string>;
  /** Already sent up as a highlight candidate; never twice. */
  candidate: boolean;
}

export interface Room<M> {
  id: string;
  channel: string;
  lang: Lang;
  region: Region;
  members: Set<M>;
  history: HistoryEntry[];
  lastActive: number;
}

export class RoomRegistry<M> {
  readonly rooms = new Map<string, Room<M>>();
  private readonly byMessage = new Map<string, Room<M>>();

  /**
   * The fullest room with space for this (channel, language, region), so rooms
   * fill up and feel alive instead of spreading ten people over ten rooms. Ties
   * go to the older room. Only when every matching room is full does a new one
   * open, numbered with the smallest free index.
   */
  assign(channel: string, lang: Lang, region: Region, capacity: number, now: number): Room<M> {
    let best: Room<M> | null = null;
    for (const room of this.rooms.values()) {
      if (room.channel !== channel || room.lang !== lang || room.region !== region) continue;
      if (room.members.size >= capacity) continue;
      if (best === null || room.members.size > best.members.size) best = room;
    }
    if (best !== null) return best;
    let n = 1;
    while (this.rooms.has(`${channel}-${lang}-${region}-${n}`)) n++;
    const room: Room<M> = {
      id: `${channel}-${lang}-${region}-${n}`,
      channel,
      lang,
      region,
      members: new Set(),
      history: [],
      lastActive: now,
    };
    this.rooms.set(room.id, room);
    return room;
  }

  join(room: Room<M>, member: M, now: number): void {
    room.members.add(member);
    room.lastActive = now;
  }

  leave(room: Room<M>, member: M, now: number): void {
    room.members.delete(member);
    room.lastActive = now;
  }

  /** Drop rooms nobody has been in for `idleMs`; returns how many went. The
   *  grace period keeps history for someone whose connection blipped. */
  sweep(now: number, idleMs: number): number {
    let removed = 0;
    for (const [id, room] of this.rooms) {
      if (room.members.size > 0 || now - room.lastActive <= idleMs) continue;
      for (const entry of room.history) this.byMessage.delete(entry.msg.id);
      this.rooms.delete(id);
      removed++;
    }
    return removed;
  }

  pushMessage(room: Room<M>, msg: ChatMessage, now: number): HistoryEntry {
    const entry: HistoryEntry = { msg, likers: new Set(), candidate: false };
    room.history.push(entry);
    this.byMessage.set(msg.id, room);
    while (room.history.length > HISTORY_LIMIT) {
      const old = room.history.shift();
      if (old) this.byMessage.delete(old.msg.id);
    }
    room.lastActive = now;
    return entry;
  }

  findMessage(id: string): { room: Room<M>; entry: HistoryEntry } | null {
    const room = this.byMessage.get(id);
    const entry = room?.history.find((e) => e.msg.id === id);
    return room && entry ? { room, entry } : null;
  }

  /** Remove a message everywhere; returns the room it was in, if any. */
  removeMessage(id: string): Room<M> | null {
    const room = this.byMessage.get(id);
    if (!room) return null;
    room.history = room.history.filter((e) => e.msg.id !== id);
    this.byMessage.delete(id);
    return room;
  }

  channelCounts(): Map<string, number> {
    const counts = new Map<string, number>();
    for (const room of this.rooms.values()) {
      counts.set(room.channel, (counts.get(room.channel) ?? 0) + room.members.size);
    }
    return counts;
  }

  info(room: Room<M>): RoomInfo {
    return { id: room.id, channel: room.channel, lang: room.lang, region: room.region, count: room.members.size };
  }
}
