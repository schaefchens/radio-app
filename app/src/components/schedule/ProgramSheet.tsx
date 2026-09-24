import { useTranslation } from 'react-i18next';
import type { DayProgram, Lang } from '@arche/shared';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';

export function ProgramSheet({ program, onClose }: { program: DayProgram | null; onClose: () => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  return (
    <BottomSheet open={program !== null} onClose={onClose} title={program?.title[lang] ?? ''}>
      <BottomSheetBody>
        {program && (
          <div className="flex flex-col gap-3">
            <div className="h-1.5 w-16 rounded-full" style={{ background: program.color }} />
            {program.subtitle[lang] && <p className="text-brand-bright">{program.subtitle[lang]}</p>}
            {program.description[lang] && <p className="text-sm leading-relaxed text-ink">{program.description[lang]}</p>}
            <div>
              <p className="label">{t('schedule.accepts')}</p>
              {program.allowed.length === 0 ? (
                <p className="text-sm text-ink-muted">{t('schedule.nothingAccepted')}</p>
              ) : (
                <ul className="flex flex-wrap gap-2">
                  {program.allowed.map((a) => (
                    <li key={a} className="rounded-full border border-night-line/40 bg-night-deep/60 px-3 py-1 text-xs">
                      {a === 'song' ? t('submit.song.title') : a === 'prayer' ? t('submit.prayer.title') : t(`record.${a}`)}
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
