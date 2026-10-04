import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { ReminderIntent } from '@/lib/reminderPlan';

export const REMINDERS_KEY = 'arche.reminders';

/** What the phone answers when asked about notifications (null: not asked yet). */
export type NotifyPermission = 'granted' | 'denied' | 'prompt' | 'prompt-with-rationale' | null;

interface RemindersState {
  /** Programs the listener wants to be reminded of, on this device only. */
  list: ReminderIntent[];
  permission: NotifyPermission;
  add: (ch: string, p: string) => void;
  remove: (ch: string, p: string) => void;
  setPermission: (p: NotifyPermission) => void;
}

export const useReminders = create<RemindersState>()(
  persist(
    (set) => ({
      list: [],
      permission: null,
      add: (ch, p) =>
        set((s) => (s.list.some((r) => r.ch === ch && r.p === p) ? s : { list: [...s.list, { ch, p, since: Date.now() }] })),
      remove: (ch, p) => set((s) => ({ list: s.list.filter((r) => !(r.ch === ch && r.p === p)) })),
      setPermission: (permission) => set((s) => (s.permission === permission ? s : { permission })),
    }),
    // The phone's answer is asked again every time: it can change in Settings.
    { name: REMINDERS_KEY, version: 1, partialize: (s) => ({ list: s.list }) },
  ),
);
