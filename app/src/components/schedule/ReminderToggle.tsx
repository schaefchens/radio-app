import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { useReminders } from '@/store/reminders';
import { LEAD_MS } from '@/lib/reminderPlan';
import { platform } from '@/lib/native';
import { allowOnTimeReminders, onTimeReminders, toggleReminder } from '@/lib/reminders';
import { BellIcon } from '@/components/common/icons';

const MINUTES = LEAD_MS / 60_000;

/** "Remind me" for one program on one channel (store apps with the notifications plugin). */
export function ReminderToggle({ channel, program }: { channel: string; program: string }) {
  const { t } = useTranslation();
  const on = useReminders((s) => s.list.some((r) => r.ch === channel && r.p === program));
  const permission = useReminders((s) => s.permission);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<'denied' | 'failed' | null>(null);
  // Android without "Alarms & reminders" may deliver late: false offers to allow it.
  const [onTime, setOnTime] = useState<boolean | null>(null);

  useEffect(() => {
    if (!on) return;
    let live = true;
    void onTimeReminders().then((v) => {
      if (live) setOnTime(v);
    });
    return () => {
      live = false;
    };
  }, [on]);

  const flip = (): void => {
    setBusy(true);
    setProblem(null);
    void toggleReminder(channel, program, !on).then((r) => {
      setBusy(false);
      if (r === 'denied' || r === 'failed') setProblem(r);
    });
  };

  const denied = problem === 'denied' || (on && permission === 'denied');
  return (
    <div className="flex flex-col gap-2">
      <button
        type="button"
        aria-pressed={on}
        disabled={busy}
        onClick={flip}
        className={clsx('btn self-start border', on ? 'border-accent-fill bg-accent-fill/20 text-ink' : 'border-line bg-soft/60 text-ink hover:bg-soft')}
      >
        <BellIcon size={16} filled={on} />
        {on ? t('reminders.on') : t('reminders.remind')}
      </button>
      <p className="text-xs text-ink-muted">{on ? t('reminders.onHint', { minutes: MINUTES }) : t('reminders.offHint', { minutes: MINUTES })}</p>
      {denied && <p className="text-xs text-warn">{platform() === 'ios' ? t('reminders.deniedIos') : t('reminders.deniedAndroid')}</p>}
      {problem === 'failed' && <p className="text-xs text-warn">{t('reminders.failed')}</p>}
      {on && onTime === false && (
        <div className="flex flex-col items-start gap-1">
          <p className="text-xs text-ink-muted">{t('reminders.late')}</p>
          <button type="button" className="btn-ghost text-xs" onClick={() => void allowOnTimeReminders().then(setOnTime)}>
            {t('reminders.onTime')}
          </button>
        </div>
      )}
    </div>
  );
}
