import { useTranslation } from 'react-i18next';
import type { DayProgram, Lang } from '@arche/shared';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { submissionLabel } from '@/i18n';
import { localDate } from '@/lib/format';
import { remindersAvailable } from '@/lib/reminders';
import { ReminderToggle } from './ReminderToggle';

interface Props {
  program: DayProgram | null;
  /** The channel the schedule shows (for a reminder). */
  channel: string;
  /** When it begins next within the published week, server time; null: not this week. */
  next: number | null;
  onClose: () => void;
}

export function ProgramSheet({ program, channel, next, onClose }: Props) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  return (
    <BottomSheet open={program !== null} onClose={onClose} title={program?.title[lang] ?? ''}>
      <BottomSheetBody>
        {program && (
          <div className="flex flex-col gap-3">
            <div className="h-1.5 w-16 rounded-full" style={{ background: program.color }} />
            {program.subtitle[lang] && <p className="text-accent">{program.subtitle[lang]}</p>}
            {program.description[lang] && <p className="text-sm leading-relaxed text-ink">{program.description[lang]}</p>}
            <p className="text-sm text-ink-muted">
              {next === null
                ? t('schedule.noneSoon')
                : t('schedule.next', { when: localDate(next, lang, { weekday: 'long', hour: '2-digit', minute: '2-digit' }) })}
            </p>
            {remindersAvailable() && channel && <ReminderToggle channel={channel} program={program.id} />}
            <div>
              <p className="label">{t('schedule.accepts')}</p>
              {program.allowed.length === 0 ? (
                <p className="text-sm text-ink-muted">{t('schedule.nothingAccepted')}</p>
              ) : (
                <ul className="flex flex-wrap gap-2">
                  {program.allowed.map((a) => (
                    <li key={a} className="rounded-full border border-line/40 bg-soft/60 px-3 py-1 text-xs">
                      {submissionLabel(a)}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )}
      </BottomSheetBody>
    </BottomSheet>
  );
}
