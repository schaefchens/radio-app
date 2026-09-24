import { randomBytes, type KeyObject } from 'node:crypto';
import {
  REACTION_KINDS,
  regionOf,
  type ChatMessage,
  type ErrorCode,
  type NodeConfig,
  type NodeReport,
  type NodeReportResponse,
  type ReactionKind,
  type RealtimeToken,
  type ServerMsg,
} from '@arche/shared';
import { Aggregator, type Deltas } from './aggregate.ts';
import { defaultNodeConfig } from './config.ts';
import { silentLogger, type Logger } from './log.ts';
import { RoomRegistry, WELCOME_HISTORY, type Room } from './rooms.ts';
import { verifyToken } from './token.ts';

/**
 * The node's whole behaviour, independent of sockets: server.ts adapts each
 * WebSocket to a Conn, and the tests drive the Hub with fakes. Everything that
 * is decided per listener is keyed by the token's `sub` (public id), not by the
 * connection — two tabs of one listener share one slow-mode clock and one like.
 */

export interface Conn {
  send(msg: ServerMsg): void;
  close(code: number, reason: string): void;
}

export interface Client {
  readonly id: number;
  readonly conn: Conn;
  token: RealtimeToken | null;
  room: Room<Client> | null;
}

/** Close codes. 4001/4003 are ours; 1012/1013 are the standard "restarting"
 *  and "try again later" the browser can act on. */
export const CLOSE = { auth: 4001, banned: 4003, restart: 1012, tryLater: 1013 } as const;

export const REACTIONS_PER_MINUTE = 20;
const ROOM_IDLE_MS = 10 * 60_000;
const MAX_CID_LENGTH = 64;
const MAX_REPORT_REASON = 200;
const MAX_REPORTED_PAIRS = 20_000;

type DrainSource = 'php' | 'lifetime';

export interface HubOptions {
  tokenKey: KeyObject | null;
  now?: () => number;
  config?: NodeConfig;
  maxConnections?: number;
  log?: Logger;
}

const isObj = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);
const isReactionKind = (v: unknown): v is ReactionKind => REACTION_KINDS.includes(v as ReactionKind);
const isShortId = (v: unknown): v is string => typeof v === 'string' && v.length > 0 && v.length <= 64;

/** Control and invisible formatting characters out (they are how people
 *  smuggle words past a blocklist), whitespace runs collapsed: chat is one
 *  line. Built from escapes so no invisible character lives in this file. */
const INVISIBLE = new RegExp(
  '[' + ['\\u0000-\\u001f', '\\u007f-\\u009f', '\\u200b-\\u200f', '\\u2028-\\u202e', '\\u2060-\\u206f', '\\ufeff'].join('') + ']',
  'g',
);

export function normalizeText(text: string): string {
  return text.replace(INVISIBLE, ' ').replace(/\s+/g, ' ').trim();
}

const newId = (prefix: string): string => `${prefix}${randomBytes(6).toString('base64url')}`;

export class Hub {
  config: NodeConfig;
  draining = false;
  readonly clients = new Set<Client>();
  readonly rooms = new RoomRegistry<Client>();
  readonly agg = new Aggregator();

  private drainSource: DrainSource | null = null;
  private bans = new Set<string>();
  private readonly lastChat = new Map<string, number>();
  private readonly reactLog = new Map<string, number[]>();
  private readonly reported = new Set<string>();
  private nextId = 1;
  private readonly tokenKey: KeyObject | null;
  private readonly now: () => number;
  private readonly maxConnections: number;
  private readonly log: Logger;

  constructor(opts: HubOptions) {
    this.tokenKey = opts.tokenKey;
    this.now = opts.now ?? Date.now;
    this.config = opts.config ?? defaultNodeConfig();
    this.maxConnections = opts.maxConnections ?? 5000;
    this.log = opts.log ?? silentLogger;
  }

  // --- connection lifecycle ---------------------------------------------------

  connect(conn: Conn): Client {
    const client: Client = { id: this.nextId++, conn, token: null, room: null };
    this.clients.add(client);
    return client;
  }

  disconnect(client: Client): void {
    if (!this.clients.delete(client)) return;
    const room = client.room;
    client.room = null;
    if (room) {
      this.rooms.leave(room, client, this.now());
      this.sendRoomPresence(room);
    }
  }

  /** Listeners past hello — the number that matters for idle detection. */
  get connectionCount(): number {
    let n = 0;
    for (const c of this.clients) if (c.token) n++;
    return n;
  }

  handleRaw(client: Client, raw: string): void {
    let data: unknown;
    try {
      data = JSON.parse(raw);
    } catch {
      return this.fail(client, 'bad');
    }
    if (!isObj(data) || typeof data.t !== 'string') return this.fail(client, 'bad');

    if (client.token === null) {
      // Anything before a valid hello ends the connection: an unauthenticated
      // socket gets exactly one chance.
      if (data.t !== 'hello') return this.reject(client, 'auth', CLOSE.auth);
      return this.hello(client, data.token);
    }

    switch (data.t) {
      case 'hello':
        return;
      case 'chat':
        return this.chat(client, data.text, data.cid);
      case 'like':
        return this.like(client, data.msg);
      case 'react':
        return this.react(client, data.item, data.kind, 'item');
      case 'voiceReact':
        return this.react(client, data.voice, data.kind, 'voice');
      case 'report':
        return this.report(client, data.msg, data.reason);
      default:
        return this.fail(client, 'bad');
    }
  }

  // --- messages -----------------------------------------------------------------

  private hello(client: Client, wire: unknown): void {
    if (this.draining) return this.reject(client, 'draining', CLOSE.tryLater);
    const check = verifyToken(wire, this.tokenKey, Math.floor(this.now() / 1000));
    if (!check.ok) return this.reject(client, check.code, CLOSE.auth);
    const token = check.token;
    if (this.bans.has(token.sub)) return this.reject(client, 'banned', CLOSE.banned);
    if (this.connectionCount >= this.maxConnections) return this.reject(client, 'full', CLOSE.tryLater);

    const now = this.now();
    const room = this.rooms.assign(token.ch, token.lang, regionOf(token.country), this.config.roomCapacity, now);
    client.token = token;
    client.room = room;
    this.rooms.join(room, client, now);
    client.conn.send({
      t: 'welcome',
      you: { sub: token.sub, name: token.name },
      room: this.rooms.info(room),
      history: room.history.slice(-WELCOME_HISTORY).map((e) => e.msg),
    });
    this.sendRoomPresence(room);
  }

  private chat(client: Client, text: unknown, cid: unknown): void {
    const token = client.token;
    const room = client.room;
    if (!token || !room) return;
    if (this.bans.has(token.sub)) return this.reject(client, 'banned', CLOSE.banned);
    if (typeof text !== 'string') return this.fail(client, 'bad');
    const clean = normalizeText(text);
    if (clean === '') return this.fail(client, 'bad');
    if ([...clean].length > this.config.maxMessageLength) return this.fail(client, 'too_long');
    const lower = clean.toLowerCase();
    if (this.config.blocklist.some((w) => lower.includes(w))) return this.fail(client, 'blocked');

    const now = this.now();
    const last = this.lastChat.get(token.sub);
    if (last !== undefined && now - last < this.config.slowModeMs) return this.fail(client, 'rate');
    this.lastChat.set(token.sub, now);

    const msg: ChatMessage = {
      id: newId('m'),
      sub: token.sub,
      name: token.name,
      country: token.country,
      text: clean,
      at: now,
      likes: 0,
    };
    this.rooms.pushMessage(room, msg, now);
    const echo = typeof cid === 'string' && cid.length > 0 && cid.length <= MAX_CID_LENGTH ? cid : undefined;
    for (const member of room.members) {
      member.conn.send({ t: 'msg', msg: member === client && echo !== undefined ? { ...msg, cid: echo } : msg });
    }
  }

  private like(client: Client, msgId: unknown): void {
    const token = client.token;
    if (!token || !client.room) return;
    if (!isShortId(msgId)) return this.fail(client, 'bad');
    const found = this.rooms.findMessage(msgId);
    // Old or foreign messages are ignored quietly: nothing the listener can fix.
    if (!found || found.room !== client.room) return;
    const { entry, room } = found;
    if (entry.msg.sub === token.sub || entry.likers.has(token.sub)) return;
    entry.likers.add(token.sub);
    entry.msg.likes = entry.likers.size;
    for (const member of room.members) member.conn.send({ t: 'likes', msg: entry.msg.id, likes: entry.msg.likes });

    if (!entry.candidate && entry.msg.likes >= this.config.highlightLikes) {
      entry.candidate = true;
      this.agg.addCandidate({
        id: entry.msg.id,
        channel: room.channel,
        lang: room.lang,
        sub: entry.msg.sub,
        name: entry.msg.name,
        country: entry.msg.country,
        text: entry.msg.text,
        likes: entry.msg.likes,
        at: entry.msg.at,
      });
    }
  }

  private react(client: Client, target: unknown, kind: unknown, what: 'item' | 'voice'): void {
    const token = client.token;
    if (!token) return;
    if (!isShortId(target) || !isReactionKind(kind)) return this.fail(client, 'bad');
    if (!this.allowReaction(token.sub)) return this.fail(client, 'rate');
    if (what === 'item') this.agg.addItemReaction(target, kind);
    else this.agg.addVoiceReaction(target, kind);
  }

  /** Sliding one-minute window per listener. Reactions only nudge a trend, so
   *  a flood is dropped rather than queued. */
  private allowReaction(sub: string): boolean {
    const now = this.now();
    const recent = (this.reactLog.get(sub) ?? []).filter((t) => now - t < 60_000);
    if (recent.length >= REACTIONS_PER_MINUTE) {
      this.reactLog.set(sub, recent);
      return false;
    }
    recent.push(now);
    this.reactLog.set(sub, recent);
    return true;
  }

  private report(client: Client, msgId: unknown, reason: unknown): void {
    const token = client.token;
    if (!token) return;
    if (!isShortId(msgId)) return this.fail(client, 'bad');
    const found = this.rooms.findMessage(msgId);
    if (!found) return;
    const key = `${token.sub}|${msgId}`;
    if (this.reported.has(key)) return;
    if (this.reported.size >= MAX_REPORTED_PAIRS) this.reported.clear();
    this.reported.add(key);
    this.agg.addReport({
      msg: msgId,
      text: found.entry.msg.text,
      sub: found.entry.msg.sub,
      by: token.sub,
      reason: typeof reason === 'string' ? normalizeText(reason).slice(0, MAX_REPORT_REASON) : '',
      at: this.now(),
    });
  }

  // --- periodic work -------------------------------------------------------------

  /** Every few seconds: each listener hears how many share the room and the
   *  channel with them on this node. */
  presenceTick(): void {
    const counts = this.rooms.channelCounts();
    for (const client of this.clients) {
      if (!client.token || !client.room) continue;
      client.conn.send({
        t: 'presence',
        room: client.room.members.size,
        channel: counts.get(client.room.channel) ?? 0,
      });
    }
  }

  sweep(): void {
    const now = this.now();
    this.rooms.sweep(now, ROOM_IDLE_MS);
    for (const [sub, at] of this.lastChat) if (now - at > 3_600_000) this.lastChat.delete(sub);
    for (const [sub, times] of this.reactLog) if (times.every((t) => now - t >= 60_000)) this.reactLog.delete(sub);
  }

  // --- reports ---------------------------------------------------------------------

  /** The body of the next report plus the deltas it carries, so a failed POST
   *  can hand them back with `agg.restore(deltas)`. */
  buildReport(node: string, startedAt: number): { body: NodeReport; deltas: Deltas } {
    const deltas = this.agg.take();
    const presence: Record<string, number> = {};
    for (const [channel, n] of this.rooms.channelCounts()) if (n > 0) presence[channel] = n;
    const rooms = [...this.rooms.rooms.values()].filter((r) => r.members.size > 0).map((r) => this.rooms.info(r));
    return {
      body: {
        v: 1,
        node,
        startedAt,
        at: this.now(),
        connections: this.connectionCount,
        draining: this.draining,
        rooms,
        presence,
        ...deltas,
      },
      deltas,
    };
  }

  /** PHP is authoritative for bans, removals, config and scale-in. */
  applyResponse(resp: NodeReportResponse): void {
    this.config = resp.config;

    this.bans = new Set(resp.bans);
    for (const client of [...this.clients]) {
      if (client.token && this.bans.has(client.token.sub)) this.reject(client, 'banned', CLOSE.banned);
    }

    for (const id of resp.removed) {
      const room = this.rooms.removeMessage(id);
      if (room) for (const member of room.members) member.conn.send({ t: 'removed', msg: id });
    }

    if (resp.drain) this.startDrain('php');
    else if (this.drainSource === 'php') {
      // PHP changed its mind (load came back before the node was deleted).
      this.draining = false;
      this.drainSource = null;
      this.log.info('drain cancelled');
    }
  }

  /** Stop taking new listeners and tell everyone to move. The lifetime cap
   *  wins over PHP: once a node is too old it never un-drains. */
  startDrain(source: DrainSource): void {
    if (this.draining && (this.drainSource === 'lifetime' || source === this.drainSource)) return;
    this.draining = true;
    this.drainSource = source;
    this.log.info('draining', { source });
    for (const client of this.clients) if (client.token) client.conn.send({ t: 'draining' });
  }

  health(node: string, startedAt: number): Record<string, unknown> {
    let rooms = 0;
    for (const r of this.rooms.rooms.values()) if (r.members.size > 0) rooms++;
    return { ok: true, node, connections: this.connectionCount, rooms, draining: this.draining, startedAt };
  }

  closeAll(code: number, reason: string): void {
    for (const client of [...this.clients]) {
      client.conn.close(code, reason);
      this.disconnect(client);
    }
  }

  // --- helpers -----------------------------------------------------------------

  private sendRoomPresence(room: Room<Client>): void {
    const channel = this.rooms.channelCounts().get(room.channel) ?? 0;
    for (const member of room.members) member.conn.send({ t: 'presence', room: room.members.size, channel });
  }

  private fail(client: Client, code: ErrorCode): void {
    client.conn.send({ t: 'error', code });
  }

  private reject(client: Client, code: ErrorCode, closeCode: number): void {
    client.conn.send({ t: 'error', code });
    client.conn.close(closeCode, code);
    this.disconnect(client);
  }
}
