import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { UsersIcon } from '@/components/common/icons';

export function LiveBar() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const engine = useRadio((s) => s.engine);
  const program = engine.program;
  const formatter = new Intl.NumberFormat(lang);
  return (
    <div className="card flex items-center gap-4 px-4 py-3">
      <span className="inline-flex shrink-0 items-center gap-2 rounded-lg bg-live px-3 py-1.5 text-sm font-bold tracking-wide text-white shadow-[0_0_20px_rgb(239_51_64/0.45)]">
        <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-white" />
        {t('live.badge')}
      </span>
      <div className="min-w-0 flex-1 border-l border-night-line/30 pl-4">
        <p className="truncate text-lg font-semibold leading-tight">{program?.title[lang] ?? 'ARCHE'}</p>
        <p className="truncate text-sm text-brand-bright">{program?.subtitle[lang] ?? t('app.tagline')}</p>
      </div>
      <div className="flex shrink-0 items-center gap-2 text-right">
        <UsersIcon size={26} className="text-ink-muted" />
        <div className="leading-tight">
          <p className="text-base font-semibold tabular-nums">{formatter.format(engine.listeners)}</p>
          <p className="text-[0.7rem] text-ink-muted">{t('live.listeners', { count: engine.listeners }).replace(/^[\d.,\s]+/, '')}</p>
        </div>
      </div>
    </div>
  );
}
