import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { rulesText } from '@/content/rules';
import { acceptRules } from '@/lib/rulesConsent';
import { Blocks } from './Blocks';

/**
 * The community rules, accepted once per device before the first post (the
 * app stores ask for it): a box to tick, and the rules themselves to read
 * right there — a sheet must not send the listener to another page.
 */
export function RulesCheckbox({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const id = useId();
  return (
    <div className="flex flex-col gap-1 text-sm">
      <label className="flex items-start gap-2 text-ink-muted" htmlFor={id}>
        <input id={id} type="checkbox" className="mt-1" checked={checked} onChange={(e) => onChange(e.target.checked)} />
        <span>{t('rules.accept')}</span>
      </label>
      <details className="ml-6 text-xs">
        <summary className="cursor-pointer text-accent underline">{t('rules.read')}</summary>
        <div className="mt-2 max-h-64 overflow-y-auto rounded-xl border border-line/40 p-3">
          <Blocks blocks={rulesText(lang)} />
        </div>
      </details>
    </div>
  );
}

/** In a room, for a listener with a name who has not accepted the rules yet: instead of the input. */
export function RulesGate() {
  const { t } = useTranslation();
  const [checked, setChecked] = useState(false);
  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-line/40 bg-surface/80 p-3">
      <p className="text-sm text-ink">{t('rules.beforeWriting')}</p>
      <RulesCheckbox checked={checked} onChange={setChecked} />
      <button type="button" className="btn-primary self-start" disabled={!checked} onClick={acceptRules}>
        {t('rules.acceptAndWrite')}
      </button>
    </div>
  );
}
