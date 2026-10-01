import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { useSettings } from '@/store/settings';
import { THEME, THEMES, type ThemeId } from '@/lib/theme';

/**
 * The station's look, applied at once. `auto` (Profile only) goes back to
 * following the device; the welcome dialog offers just the two themes.
 */
export function ThemePicker({ withAuto = true }: { withAuto?: boolean }) {
  const { t } = useTranslation();
  const name = useId();
  const theme = useSettings((s) => s.theme);
  const setTheme = useSettings((s) => s.setTheme);
  const options: { id: ThemeId | null; label: string }[] = [
    ...(withAuto ? [{ id: null, label: t('theme.auto') }] : []),
    ...THEMES.map((id) => ({ id, label: t(THEME[id].label) })),
  ];
  return (
    <fieldset className="min-w-0">
      <legend className="label">{t('theme.label')}</legend>
      {/* Each option as wide as its name: equal thirds cut "Automatisch" on a phone. */}
      <div className="flex gap-1.5">
        {options.map((o) => (
          <label
            key={o.id ?? 'auto'}
            className={clsx(
              'flex min-h-[42px] min-w-0 flex-auto cursor-pointer items-center justify-center gap-1.5 rounded-xl border px-2 text-center text-sm',
              theme === o.id ? 'border-accent bg-accent/10 font-semibold text-ink' : 'border-line bg-soft text-ink-muted hover:text-ink',
            )}
            title={o.id === null ? t('theme.autoHint') : undefined}
          >
            <input type="radio" name={name} className="accent-accent-fill" checked={theme === o.id} onChange={() => setTheme(o.id)} />
            {o.label}
          </label>
        ))}
      </div>
    </fieldset>
  );
}
