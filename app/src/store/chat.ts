import { create } from 'zustand';
import type { ChatMessage, RoomInfo } from '@arche/shared';

/**
 * The listener's chat room. Filled by lib/realtime/client.ts; read by the chat
 * page and — while connected — by Community Voices on Home.
 */
export type ChatStatus = 'idle' | 'waking' | 'connecting' | 'connected' | 'unavailable' | 'error';

/** Messages kept per room; older ones scroll away. */
export const MESSAGE_CAP = 150;

export interface ChatState {
  status: ChatStatus;
  /** Last problem worth showing (a ServerMsg error code or a local one). */
  error: string | null;
  room: RoomInfo | null;
  me: { sub: string; name: string } | null;
  messages: ChatMessage[];
  /** Client ids of this device's messages that the node has not echoed yet. */
  pending: string[];
  liked: Record<string, true>;
  reported: Record<string, true>;
  channelCount: number;
}

export const initialChat: ChatState = {
  status: 'idle',
  error: null,
  room: null,
  me: null,
  messages: [],
  pending: [],
  liked: {},
  reported: {},
  channelCount: 0,
};

export const useChat = create<ChatState>(() => ({ ...initialChat }));

// --- pure reducers (unit-tested; the client applies them) -----------------------------

/** Append or reconcile a message: the echo of an optimistic send replaces it. */
export function withMessage(state: ChatState, msg: ChatMessage): Partial<ChatState> {
  let messages = state.messages;
  let pending = state.pending;
  if (msg.cid && pending.includes(msg.cid)) {
    messages = messages.filter((m) => m.id !== `local:${msg.cid}`);
    pending = pending.filter((c) => c !== msg.cid);
  }
  if (messages.some((m) => m.id === msg.id)) return { messages, pending };
  return { messages: [...messages, msg].slice(-MESSAGE_CAP), pending };
}

export function withOptimistic(state: ChatState, cid: string, text: string, at: number): Partial<ChatState> {
  const me = state.me;
  if (!me) return {};
  const msg: ChatMessage = { id: `local:${cid}`, sub: me.sub, name: me.name, country: '', text, at, likes: 0, cid };
  return { messages: [...state.messages, msg].slice(-MESSAGE_CAP), pending: [...state.pending, cid] };
}

/** The node refused the last send: drop the newest optimistic copy. */
export function withoutLastPending(state: ChatState): Partial<ChatState> {
  const cid = state.pending[state.pending.length - 1];
  if (!cid) return {};
  return { messages: state.messages.filter((m) => m.id !== `local:${cid}`), pending: state.pending.slice(0, -1) };
}

export function withLikes(state: ChatState, msg: string, likes: number): Partial<ChatState> {
  return { messages: state.messages.map((m) => (m.id === msg ? { ...m, likes } : m)) };
}

export function withoutMessage(state: ChatState, msg: string): Partial<ChatState> {
  return { messages: state.messages.filter((m) => m.id !== msg) };
}
