import type { ChannelInfo, DayProgram } from '@arche/shared';
import i18n from '@/i18n';
import { useReminders } from '@/store/reminders';
import { useSettings } from '@/store/settings';
import { clockOffset, serverNow } from './clock';
import { localTime } from './format';
import { hasPlugin, isNative, platform, plugin } from './native';
import { fetchChannels, fetchDay } from './programFiles';
import { parseExtra } from './reminderPlan';
import type { Reason, ReminderRunner } from './reminderRunner';

/**
 * Program reminders, the part every page may call. In a store app with the
 * notifications plugin it loads the runner (lib/reminderRunner.ts) and the
 * plugin's code on first use; everywhere else — the website, an app
 * installed before reminders existed — every function does nothing.
 */

export function remindersAvailable(): boolean {
  return isNative() && hasPlugin('LocalNotifications');
}

let runner: Promise<ReminderRunner | null> | null = null;

function describe(channel: ChannelInfo, program: DayProgram | undefined, slug: string, start: number): { title: string; body: string } {
  const lng = useSettings.getState().lang;
  return {
    title: program?.title[lng] || program?.title.en || slug,
    body: i18n.t('reminders.body', { lng, time: localTime(start, lng), channel: channel.name[lng] || channel.name.en }),
  };
}

function getRunner(): Promise<ReminderRunner | null> {
  runner ??= loadRunner()
    .catch(() => null)
    .then((r) => {
      // A chunk that did not load is tried again next time: the iOS app has no service worker keeping one.
      if (r === null) runner = null;
      return r;
    });
  return runner;
}

async function loadRunner(): Promise<ReminderRunner | null> {
  if (!remindersAvailable()) return null;
  const [m, { createReminderRunner }] = await Promise.all([plugin('LocalNotifications'), import('./reminderRunner')]);
  if (!m) return null;
  return createReminderRunner(m.LocalNotifications, {
    platform: platform() === 'ios' ? 'ios' : 'android',
    intents: () => useReminders.getState().list,
    setPermission: (p) => useReminders.getState().setPermission(p),
    channels: async () => (await fetchChannels())?.channels ?? null,
    day: fetchDay,
    now: serverNow,
    offset: clockOffset,
    describe,
    channelText: () => {
      const lng = useSettings.getState().lang;
      return { name: i18n.t('reminders.channel', { lng }), description: i18n.t('reminders.channelHint', { lng }) };
    },
    later: (run, ms) => window.setTimeout(run, ms),
  });
}

/**
 * At boot, before anything else can open a channel: a tap on a reminder that
 * started the app arrives once a listener is registered (the plugin keeps it).
 */
export function startReminders(onTap: (channel: string) => void): void {
  if (!remindersAvailable()) return;
  void plugin('LocalNotifications').then((m) =>
    m?.LocalNotifications.addListener('localNotificationActionPerformed', (action) => {
      const extra = parseExtra(action.notification.extra);
      if (extra && action.actionId !== 'dismiss') onTap(extra.ch);
    }).catch(() => {}),
  );
}

/** Bring the phone's reminders in line with the plan (cheap when nothing is set). */
export function reconcileReminders(reason: Reason): void {
  if (!remindersAvailable()) return;
  void getRunner().then((r) => r?.reconcile(reason));
}

export type ToggleResult = 'on' | 'off' | 'denied' | 'failed';

/** "Remind me" on or off. Turning it on is the one moment the phone may ask for permission. */
export async function toggleReminder(ch: string, p: string, on: boolean): Promise<ToggleResult> {
  const r = await getRunner();
  if (!r) return 'failed';
  try {
    if (on) {
      if ((await r.ensurePermission()) !== 'granted') return 'denied';
      useReminders.getState().add(ch, p);
    } else {
      useReminders.getState().remove(ch, p);
    }
    await r.reconcile('change');
    return on ? 'on' : 'off';
  } catch {
    return 'failed';
  }
}

/**
 * Android 12+: whether reminders may come on the minute. Without "Alarms &
 * reminders" (off by default from Android 14) Android may hold one back for
 * up to an hour. Null where that does not apply (iOS, the website, an older app).
 */
export async function onTimeReminders(): Promise<boolean | null> {
  if (!remindersAvailable() || platform() !== 'android' || !hasPlugin('LocalNotifications', 'checkExactNotificationSetting')) return null;
  const m = await plugin('LocalNotifications');
  if (!m) return null;
  try {
    return (await m.LocalNotifications.checkExactNotificationSetting()).exact_alarm === 'granted';
  } catch {
    return null;
  }
}

/** Opens Android's "Alarms & reminders" for the app; back from there, every reminder is set again. */
export async function allowOnTimeReminders(): Promise<boolean | null> {
  if (!remindersAvailable() || platform() !== 'android' || !hasPlugin('LocalNotifications', 'changeExactNotificationSetting')) return null;
  const m = await plugin('LocalNotifications');
  if (!m) return null;
  try {
    const granted = (await m.LocalNotifications.changeExactNotificationSetting()).exact_alarm === 'granted';
    // Exact or not is part of each reminder (reminderPlan.ts): all are written again, meanwhile.
    void getRunner().then((r) => r?.reconcile('change'));
    return granted;
  } catch {
    return null;
  }
}

/** "Delete data on this device": the phone's scheduled reminders go too, not just the list. */
export async function forgetReminders(): Promise<void> {
  if (!remindersAvailable()) return;
  const cancel = getRunner().then((r) => r?.cancelOurs());
  // A plugin that does not answer must not keep the data from being deleted.
  await Promise.race([cancel.catch(() => {}), new Promise((resolve) => setTimeout(resolve, 2000))]);
}
