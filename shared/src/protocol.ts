import type { Lang, ReactionKind, Role } from './constants.ts';
import type { Region } from './regions.ts';

/**
 * The realtime system's three contracts:
 *
 *   1. client ↔ node   WebSocket frames (JSON), below as ClientMsg / ServerMsg
 *   2. PHP → node      a signed join token the client carries (RealtimeToken)
 *   3. node → PHP      the batched report every REPORT_INTERVAL_MS
 *
 * The node holds no durable state and no secret that could mint tokens: PHP
 * signs tokens with Ed25519 and the node only has the public key. Everything
 * worth keeping (trend deltas, highlight candidates, abuse reports) travels to
 * PHP in the report, where it is additive — two nodes' reports simply sum.
 */

// --- 2. join token ------------------------------------------------------------

/** Signed by PHP (sodium_crypto_sign_detached over the base64url payload
 *  string); wire form is `<base64url(json payload)>.<base64url(signature)>`. */
export interface RealtimeToken {
  v: 1;
  /** Public display id of the listener (never the internal row id). */
  sub: string;
  name: string;
  country: string;
  lang: Lang;
  role: Role;
  /** Channel the listener is tuned to; the room is picked within it. */
  ch: string;
  /** Unix seconds. */
  iat: number;
  exp: number;
}

// --- 1. client ↔ node -----------------------------------------------------------

export interface ChatMessage {
  id: string;
  sub: string;
  name: string;
  country: string;
  text: string;
  at: number;
  likes: number;
  /** Echo of the sender's client id so the sender can reconcile its
   *  optimistic copy. Only present on the sender's own messages. */
  cid?: string;
}

export interface RoomInfo {
  id: string;
  channel: string;
  lang: Lang;
  region: Region;
  count: number;
}

export type ClientMsg =
  /** Must be the first frame; the node closes the socket otherwise. The token
   *  travels in a frame, not the URL, so it never lands in an access log. */
  | { t: 'hello'; token: string }
  | { t: 'chat'; text: string; cid: string }
  | { t: 'like'; msg: string }
  | { t: 'react'; item: string; kind: ReactionKind }
  | { t: 'voiceReact'; voice: string; kind: ReactionKind }
  | { t: 'report'; msg: string; reason: string };

export type ErrorCode = 'auth' | 'expired' | 'banned' | 'rate' | 'too_long' | 'blocked' | 'full' | 'bad' | 'draining';

export type ServerMsg =
  | { t: 'welcome'; you: { sub: string; name: string }; room: RoomInfo; history: ChatMessage[] }
  | { t: 'msg'; msg: ChatMessage }
  | { t: 'likes'; msg: string; likes: number }
  | { t: 'removed'; msg: string }
  | { t: 'presence'; room: number; channel: number }
  | { t: 'error'; code: ErrorCode }
  /** The node is being scaled in; reconnect through the wake endpoint. */
  | { t: 'draining' };

// --- 3. node → PHP --------------------------------------------------------------

export const REPORT_INTERVAL_MS = 20_000;

/** Reactions to a program item since the previous report (deltas). */
export interface ItemReactionDelta {
  item: string;
  counts: Partial<Record<ReactionKind, number>>;
}

export interface VoiceReactionDelta {
  voice: string;
  counts: Partial<Record<ReactionKind, number>>;
}

/** A chat message that collected enough likes to be considered for the main
 *  screen / the host. PHP moderates it before it goes anywhere. */
export interface HighlightCandidate {
  id: string;
  channel: string;
  lang: Lang;
  sub: string;
  name: string;
  country: string;
  text: string;
  likes: number;
  at: number;
}

export interface ChatReport {
  msg: string;
  text: string;
  /** Author of the reported message. */
  sub: string;
  /** Reporter. */
  by: string;
  reason: string;
  at: number;
}

export interface NodeReport {
  v: 1;
  node: string;
  startedAt: number;
  at: number;
  connections: number;
  draining: boolean;
  rooms: RoomInfo[];
  /** Connected listeners per channel on this node. */
  presence: Record<string, number>;
  reactions: ItemReactionDelta[];
  voiceReactions: VoiceReactionDelta[];
  candidates: HighlightCandidate[];
  reports: ChatReport[];
}

export interface NodeConfig {
  maxMessageLength: number;
  roomCapacity: number;
  /** Minimum gap between two messages from one listener. */
  slowModeMs: number;
  /** Lower-case substrings that block a message outright. */
  blocklist: string[];
  /** Likes a message needs before it is sent up as a highlight candidate. */
  highlightLikes: number;
}

export interface NodeReportResponse {
  ok: true;
  /** Public ids that must be disconnected and refused. */
  bans: string[];
  /** Message ids moderators removed; the node drops them from history. */
  removed: string[];
  config: NodeConfig;
  /** Stop accepting new connections; existing ones are told to move. */
  drain: boolean;
}

/** Headers on the report request. The signature is
 *  hex(HMAC-SHA256(NODE_SECRET, `${timestamp}.${body}`)); PHP rejects a
 *  timestamp more than two minutes off, so a captured report cannot be
 *  replayed later. */
export const REPORT_HEADERS = {
  node: 'X-Arche-Node',
  timestamp: 'X-Arche-Timestamp',
  signature: 'X-Arche-Signature',
} as const;
