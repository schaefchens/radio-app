import { describe, expect, it, vi } from 'vitest';
import type { ChannelInfo, DayFile, DayProgram } from '@arche/shared';
import { addDays, stationDate } from '@/lib/format';
import { LEAD_MS } from '@/lib/reminderPlan';
import { createReminderRunner, REMINDER_CHANNEL, type NativeNotification, type NotificationsApi, type RunnerEnv } from '@/lib/reminderRunner';
import type { ReminderIntent } from '@/lib/reminderPlan';

/**
 * The runner against a fake plugin: the two traps of @capacitor/local-notifications
 * 8.3 (schedule() asks for permission by itself; isExactNotification opens the
 * alarm settings unless stated) must never be sprung by a start or a return.
 */

const TZ = 'Europe/Berlin';
const MAIN: ChannelInfo = { id: 'main', name: { en: 'Arche Radio', de: 'Arche Radio' }, main: true, tz: TZ, color: '#000', host: { name: 'Noah', avatar: null, color: '#2f7bff', about: { en: '', de: '' }, voice: 'openai' }, evergreen: null };
const GEBET: DayProgram = { id: 'gebet', title: { en: 'Prayer hour', de: 'Gebetsstunde' }, subtitle: { en: '', de: '' }, description: { en: '', de: '' }, color: '#000', stage: {} as DayProgram['stage'], allowed: [], format: 'prayer', voicedBy: [], hosts: [] };

const NOW = Date.UTC(2026, 9, 7, 8, 0); // 10:00 in Berlin
const SKEW = 4_000; // the server is 4 s ahead of this phone

function dayFile(date: string): DayFile {
  // 20:00–21:00 Berlin (UTC+2 in October), the rest music.
  const start = Date.parse(`${date}T18:00:00Z`);
  return {
    v: 1, channel: 'main', date, tz: TZ, gen: NOW, played: [],
    blocks: [{ start: start - 18 * 3_600_000, end: start, p: 'musik' }, { start, end: start + 3_600_000, p: 'gebet' }, { start: start + 3_600_000, end: start + 6 * 3_600_000, p: 'musik' }],
    programs: { gebet: GEBET },
  };
}

function setup(opts: { display?: 'granted' | 'denied' | 'prompt'; afterRequest?: 'granted' | 'denied'; exact?: boolean; platform?: 'ios' | 'android'; intents?: ReminderIntent[] } = {}) {
  const calls: string[] = [];
  const pending: { id: number; title: string; body: string; extra: unknown }[] = [];
  let display: 'granted' | 'denied' | 'prompt' = opts.display ?? 'granted';
  const scheduled: NativeNotification[] = [];
  const api: NotificationsApi = {
    checkPermissions: vi.fn(async () => ({ display })),
    requestPermissions: vi.fn(async () => {
      calls.push('request');
      display = opts.afterRequest ?? 'granted';
      return { display };
    }),
    checkExactNotificationSetting: vi.fn(async () => ({ exact_alarm: opts.exact ? ('granted' as const) : ('denied' as const) })),
    getPending: vi.fn(async () => ({ notifications: [...pending] })),
    cancel: vi.fn(async ({ notifications }) => {
      calls.push('cancel');
      for (const { id } of notifications) pending.splice(pending.findIndex((n) => n.id === id), 1);
    }),
    schedule: vi.fn(async ({ notifications }) => {
      calls.push('schedule');
      scheduled.push(...notifications);
      for (const n of notifications) pending.push({ id: n.id, title: n.title, body: n.body, extra: n.extra });
    }),
    createChannel: vi.fn(async () => {
      calls.push('channel');
    }),
  };
  const intents = { list: opts.intents ?? [{ ch: 'main', p: 'gebet', since: 0 }] };
  const permission = { last: null as unknown };
  const env: RunnerEnv = {
    platform: opts.platform ?? 'android',
    intents: () => intents.list,
    setPermission: (p) => (permission.last = p),
    channels: async () => [MAIN],
    day: async (_ch, date) => dayFile(date),
    now: () => NOW,
    offset: () => SKEW,
    describe: (_c, p, slug) => ({ title: p?.title.de ?? slug, body: 'Beginnt bald.' }),
    channelText: () => ({ name: 'Sendungs-Erinnerungen', description: 'Wenn eine Sendung beginnt' }),
    later: () => {},
  };
  return { runner: createReminderRunner(api, env), api, calls, pending, scheduled, intents, permission };
}

describe('the reminder runner', () => {
  it('without permission: never asks, never schedules — but still cancels what was turned off', async () => {
    const { runner, api, calls, pending, intents } = setup({ display: 'prompt' });
    await runner.reconcile('boot');
    expect(api.requestPermissions).not.toHaveBeenCalled();
    expect(api.schedule).not.toHaveBeenCalled();

    pending.push({ id: 5, title: 't', body: 'b', extra: { arche: 1, ch: 'main', p: 'gebet', start: NOW + 86_400_000, at: NOW + 86_400_000 - LEAD_MS, sig: 's' } });
    intents.list = [];
    await runner.reconcile('change');
    expect(calls).toEqual(['cancel']);
  });

  it('states exactness on every notification, creates the channel first, and counts in the phone’s clock', async () => {
    const { runner, scheduled, calls } = setup();
    await runner.reconcile('boot');
    expect(calls).toEqual(['channel', 'schedule']);
    expect(scheduled.length).toBe(7); // today 20:00 and six more evenings
    for (const n of scheduled) {
      expect(n.isExactNotification).toBe(false);
      expect(n.schedule.allowWhileIdle).toBe(true);
      expect(n.channelId).toBe(REMINDER_CHANNEL);
      expect(n.schedule.at.getTime()).toBe(n.extra.at - SKEW);
    }
    const today = stationDate(NOW, TZ);
    expect(scheduled[0]!.extra.start).toBe(dayFile(today).blocks[1]!.start);
    expect(scheduled.at(-1)!.extra.start).toBe(dayFile(addDays(today, 6)).blocks[1]!.start);
  });

  it('exact only where Android already allows it; iOS needs no channel', async () => {
    const exact = setup({ exact: true });
    await exact.runner.reconcile('boot');
    expect(exact.scheduled.every((n) => n.isExactNotification)).toBe(true);
    const ios = setup({ platform: 'ios' });
    await ios.runner.reconcile('boot');
    expect(ios.calls).toEqual(['schedule']);
  });

  it('the listener’s tap is the only place the phone asks', async () => {
    const asked = setup({ display: 'prompt', afterRequest: 'denied' });
    expect(await asked.runner.ensurePermission()).toBe('denied');
    expect(asked.calls).toEqual(['request']);
    expect(asked.permission.last).toBe('denied');
    const granted = setup();
    expect(await granted.runner.ensurePermission()).toBe('granted');
    expect(granted.calls).toEqual([]);
  });

  it('a call during a run runs once more afterwards, not twice', async () => {
    const { runner, api } = setup();
    const first = runner.reconcile('boot');
    void runner.reconcile('change');
    void runner.reconcile('change');
    await first;
    await vi.waitFor(() => expect(api.getPending).toHaveBeenCalledTimes(2));
  });

  it('"delete data" during a run: the run schedules nothing after it and writes nothing back, and none follows', async () => {
    const t = setup();
    let release!: () => void;
    const gate = new Promise<void>((resolve) => (release = resolve));
    vi.mocked(t.api.checkPermissions).mockImplementationOnce(async () => {
      await gate;
      return { display: 'granted' };
    });
    const run = t.runner.reconcile('boot');
    const cancel = t.runner.cancelOurs();
    release();
    await Promise.all([run, cancel]);
    expect(t.api.schedule).not.toHaveBeenCalled();
    expect(t.permission.last).toBeNull();
    await t.runner.reconcile('change');
    expect(t.api.schedule).not.toHaveBeenCalled();
  });

  it('"delete data" takes our reminders off the phone, nobody else’s', async () => {
    const { runner, pending } = setup();
    await runner.reconcile('boot');
    pending.push({ id: 1, title: 'other app', body: '', extra: { kind: 'theirs' } });
    await runner.cancelOurs();
    expect(pending.map((n) => n.id)).toEqual([1]);
  });
});
