import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/** Someone this listener blocked in a room. */
export interface BlockedUser {
  /** Their public id in the rooms (stable across sessions and devices). */
  sub: string;
  name: string;
  /** The mark on their community voices (lib/blocking.ts voiceTag). */
  tag: string;
  at: number;
}

const KEEP_USERS = 200;
const KEEP_HIDDEN = 300;

interface BlocksState {
  users: BlockedUser[];
  /** Wall requests and voices this device reported: hidden here at once. */
  hidden: string[];
  block: (user: BlockedUser) => void;
  unblock: (sub: string) => void;
  hide: (id: string) => void;
}

/**
 * Blocking and reporting from the listener's side (the app stores require
 * both): on this device only — the server learns of a block as a report.
 * "Delete data on this device" clears it (arche.blocks).
 */
export const useBlocks = create<BlocksState>()(
  persist(
    (set) => ({
      users: [],
      hidden: [],
      block: (user) => set((s) => (s.users.some((u) => u.sub === user.sub) ? s : { users: [user, ...s.users].slice(0, KEEP_USERS) })),
      unblock: (sub) => set((s) => ({ users: s.users.filter((u) => u.sub !== sub) })),
      hide: (id) => set((s) => (s.hidden.includes(id) ? s : { hidden: [id, ...s.hidden].slice(0, KEEP_HIDDEN) })),
    }),
    { name: 'arche.blocks', version: 1 },
  ),
);
