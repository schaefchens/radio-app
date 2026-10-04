import type { ChannelInfo, DayFile, DayProgram } from '@arche/shared';
import type { NotifyPermission } from '@/store/reminders';
import { addDays, stationDate } from './format';
import { DAY_OFFSETS, parseExtra, planReminders, type PendingReminder, type ReminderIntent, type Wanted } from './reminderPlan';

/**
 * Carries out reminderPlan.ts on the phone through @capacitor/local-notifications
 * (handed in, so tests can use a fake). Two traps of the plugin (8.3+) shape it:
 * - schedule() asks for the notification permission by itself, so nothing is
 *   scheduled unless checkPermissions() already says granted: a start or a
 *   return to the app must never pop up the system's question. Only the
 *   listener's first tap on "Remind me" asks.
 * - isExactNotification defaults to true, and on Android 12+ without exact
 *   alarms that opens the "Alarms & reminders" settings screen: every
 *   notification states it, true only where it is already allowed.
 */

export const REMINDER_CHANNEL = 'program-reminders';

type State = 'granted' | 'denied' | 'prompt' | 'prompt-with-rationale';

/** The part of the plugin used here. */
export interface NotificationsApi {
  checkPermissions(): Promise<{ display: State }>;
  requestPermissions(): Promise<{ display: State }>;
  checkExactNotificationSetting(): Promise<{ exact_alarm: State }>;
  getPending(): Promise<{ notifications: { id: number; title?: string; body?: string; extra?: unknown }[] }>;
  cancel(options: { notifications: { id: number }[] }): Promise<void>;
  schedule(options: { notifications: NativeNotification[] }): Promise<unknown>;
  createChannel(channel: { id: string; name: string; description?: string; importance?: 1 | 2 | 3 | 4 | 5 }): Promise<void>;
}

export interface NativeNotification {
  id: number;
  title: string;
  body: string;
  schedule: { at: Date; allowWhileIdle: boolean };
  isExactNotification: boolean;
  channelId: string;
  autoCancel: boolean;
  threadIdentifier: string;
  extra: Wanted['extra'];
}

export interface RunnerEnv {
  platform: 'ios' | 'android';
  intents: () => readonly ReminderIntent[];
  setPermission: (p: NotifyPermission) => void;
  channels: () => Promise<ChannelInfo[] | null>;
  day: (channel: string, date: string) => Promise<DayFile | null>;
  /** Server time, and how far ahead of this device it is. */
  now: () => number;
  offset: () => number;
  describe: (channel: ChannelInfo, program: DayProgram | undefined, slug: string, start: number) => { title: string; body: string };
  /** The Android channel's name and description, in the listener's language. */
  channelText: () => { name: string; description: string };
  /** One more run after a stale day file, later. */
  later: (run: () => void, ms: number) => void;
}

export type Reason = 'boot' | 'change' | 'lang' | 'resume' | 'tick' | 'retry';

/** Between runs for the same reason: a resume is often, the clock tick regular. */
const THROTTLE_MS: Partial<Record<Reason, number>> = { resume: 10 * 60_000, tick: 30 * 60_000 };

export function createReminderRunner(api: NotificationsApi, env: RunnerEnv) {
  let running = false;
  let again: Reason | null = null;
  let retryQueued = false;
  // After "delete data on this device": no run writes or schedules anything again.
  let forgotten = false;
  let current: Promise<void> = Promise.resolve();
  const lastRun: Partial<Record<Reason, number>> = {};

  async function loadDays(channels: ChannelInfo[], intents: readonly ReminderIntent[]): Promise<Record<string, Record<string, DayFile | null>>> {
    const wanted = new Set(intents.map((i) => i.ch));
    const out: Record<string, Record<string, DayFile | null>> = {};
    await Promise.all(
      channels
        .filter((c) => wanted.has(c.id))
        .map(async (c) => {
          const today = stationDate(env.now(), c.tz);
          const days: Record<string, DayFile | null> = {};
          await Promise.all(DAY_OFFSETS.map(async (d) => {
            const date = addDays(today, d);
            days[date] = await env.day(c.id, date).catch(() => null);
          }));
          out[c.id] = days;
        }),
    );
    return out;
  }

  function toNative(w: Wanted, exact: boolean): NativeNotification {
    return {
      id: w.id,
      title: w.title,
      body: w.body,
      // The phone counts in its own clock.
      schedule: { at: new Date(w.at - env.offset()), allowWhileIdle: true },
      isExactNotification: exact,
      channelId: REMINDER_CHANNEL,
      autoCancel: true,
      threadIdentifier: 'reminders',
      extra: w.extra,
    };
  }

  async function runOnce(reason: Reason): Promise<void> {
    const { display } = await api.checkPermissions();
    // Writing the store writes it to localStorage, even unchanged: not once the data is deleted.
    if (forgotten) return;
    env.setPermission(display);
    const pending: PendingReminder[] = (await api.getPending()).notifications.map((n) => ({
      id: n.id,
      title: n.title ?? '',
      body: n.body ?? '',
      extra: parseExtra(n.extra),
    }));
    const intents = env.intents();
    if (intents.length === 0 && !pending.some((n) => n.extra)) return;

    const granted = display === 'granted';
    const channels = granted ? await env.channels().catch(() => null) : null;
    const days = granted && channels ? await loadDays(channels, intents) : {};
    const exact = env.platform === 'android' ? await api.checkExactNotificationSetting().then((r) => r.exact_alarm === 'granted', () => false) : true;
    const plan = planReminders({ intents, channels, days, pending, now: env.now(), exact, refreshAll: reason === 'boot', describe: env.describe });
    if (forgotten) return;

    if (plan.cancel.length > 0) await api.cancel({ notifications: plan.cancel.map((id) => ({ id })) });
    if (granted && plan.schedule.length > 0) {
      // A channel that does not exist swallows the notification on Android.
      if (env.platform === 'android') await api.createChannel({ id: REMINDER_CHANNEL, ...env.channelText(), importance: 4 });
      if (forgotten) return;
      await api.schedule({ notifications: plan.schedule.map((w) => toNative(w, exact)) });
    }
    if (plan.stale && !retryQueued) {
      retryQueued = true;
      env.later(() => {
        retryQueued = false;
        void reconcile('retry');
      }, 30_000);
    }
  }

  /** One run at a time; a call during a run means one more afterwards. */
  async function reconcile(reason: Reason): Promise<void> {
    if (forgotten) return;
    const throttle = THROTTLE_MS[reason];
    const now = Date.now();
    if (throttle && now - (lastRun[reason] ?? 0) < throttle) return;
    lastRun[reason] = now;
    if (running) {
      // A start writes everything again; it must not be lost behind a change.
      if (!again || reason === 'boot') again = reason;
      return;
    }
    running = true;
    current = runOnce(reason).catch(() => {
      /* the next start or return tries again */
    });
    await current;
    running = false;
    if (again && !forgotten) {
      const next = again;
      again = null;
      await reconcile(next === 'boot' ? 'boot' : 'change');
    }
  }

  /**
   * Ask for permission when the listener turns a reminder on (the only place
   * the question may come up). Returns the phone's answer.
   */
  async function ensurePermission(): Promise<State> {
    let { display } = await api.checkPermissions();
    if (display === 'prompt' || display === 'prompt-with-rationale') ({ display } = await api.requestPermissions());
    env.setPermission(display);
    return display;
  }

  /**
   * Every reminder of ours off the phone ("delete data on this device"), for
   * good: a run under way ends first, and none follows.
   */
  async function cancelOurs(): Promise<void> {
    forgotten = true;
    again = null;
    await current;
    const ours = (await api.getPending()).notifications.filter((n) => parseExtra(n.extra));
    if (ours.length > 0) await api.cancel({ notifications: ours.map((n) => ({ id: n.id })) });
  }

  return { reconcile, ensurePermission, cancelOurs };
}

export type ReminderRunner = ReturnType<typeof createReminderRunner>;
