import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { ReactionKind } from '@arche/shared';

/** What this device pressed on one song, voice or prayer. */
export interface Mark {
  heart: boolean;
  pray: boolean;
  emoji: ReactionKind | null;
  /** Kinds already sent: the station counts a reaction once, unpressing is local. */
  sent: ReactionKind[];
  at: number;
}

const KEEP = 200;
export const NO_MARK: Mark = { heart: false, pray: false, emoji: null, sent: [], at: 0 };

interface ReactionsState {
  marks: Record<string, Mark>;
  /** Heart or pray on and off; returns whether the kind still has to be sent. */
  toggle: (id: string, kind: 'heart' | 'pray') => boolean;
  /** One emoji at a time; the same one again takes it back. */
  pick: (id: string, kind: ReactionKind) => boolean;
}

function update(marks: Record<string, Mark>, id: string, next: Mark): Record<string, Mark> {
  const all = { ...marks, [id]: next };
  const ids = Object.keys(all);
  if (ids.length <= KEEP) return all;
  ids.sort((a, b) => all[b]!.at - all[a]!.at);
  return Object.fromEntries(ids.slice(0, KEEP).map((k) => [k, all[k]!]));
}

/**
 * Reactions stay pressed, per device, as in the design — the voice or prayer
 * stays on screen. Kept for the last 200 things reacted to; "Delete this
 * device's data" clears it (arche.reactions).
 */
export const useReactions = create<ReactionsState>()(
  persist(
    (set, get) => ({
      marks: {},
      toggle: (id, kind) => {
        const mark = get().marks[id] ?? NO_MARK;
        const on = !mark[kind];
        const send = on && !mark.sent.includes(kind);
        set({ marks: update(get().marks, id, { ...mark, [kind]: on, sent: send ? [...mark.sent, kind] : mark.sent, at: Date.now() }) });
        return send;
      },
      pick: (id, kind) => {
        const mark = get().marks[id] ?? NO_MARK;
        const emoji = mark.emoji === kind ? null : kind;
        const send = emoji !== null && !mark.sent.includes(kind);
        set({ marks: update(get().marks, id, { ...mark, emoji, sent: send ? [...mark.sent, kind] : mark.sent, at: Date.now() }) });
        return send;
      },
    }),
    { name: 'arche.reactions', version: 1 },
  ),
);
