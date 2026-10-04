import { useEffect, useId, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { useSettings } from '@/store/settings';
import { useOverlay } from '@/lib/backStack';
import { THEME, THEMES, useTheme } from '@/lib/theme';

/**
 * The first visit (theme-preview.html, the welcome dialog): what makes the
 * station special, and language and design, both applied at once. Closed
 * once — by ×, Escape or "Let's get started" — it does not come back;
 * Profile changes both later. It loads nothing from Google.
 */
export function WelcomeDialog() {
  const { t } = useTranslation();
  const welcomed = useSettings((s) => s.welcomed);
  const setWelcomed = useSettings((s) => s.setWelcomed);
  const lang = useSettings((s) => s.lang);
  const setLang = useSettings((s) => s.setLang);
  const setTheme = useSettings((s) => s.setTheme);
  const theme = useTheme();
  const dialog = useRef<HTMLDialogElement>(null);
  const id = useId();

  useEffect(() => {
    const d = dialog.current;
    if (welcomed || !d) return;
    if (!d.open) d.showModal();
  }, [welcomed]);
  // The dialog sits in the top layer, over the YouTube player: the player
  // must pause while it is open, like under a sheet. Back closes it, as × does.
  useOverlay(!welcomed, () => dialog.current?.close());

  if (welcomed) return null;
  return (
    <dialog ref={dialog} className="welcome-dialog" aria-labelledby={`${id}-title`} aria-describedby={`${id}-intro`} onClose={() => setWelcomed()}>
      <div className="welcome-art" style={{ backgroundImage: `url(${THEME[theme].art.top})` }} aria-hidden="true" />
      <div className="welcome-content">
        <div className="welcome-topline">
          <h2 id={`${id}-title`}>{t('welcome.title')}</h2>
          <button type="button" className="welcome-close" aria-label={t('welcome.close')} onClick={() => dialog.current?.close()}>
            ×
          </button>
        </div>
        <p className="welcome-intro" id={`${id}-intro`}>
          {t('welcome.intro')}
        </p>
        <ul className="welcome-points">
          <li>
            <span aria-hidden="true">♫</span>
            <span>{t('welcome.music')}</span>
          </li>
          <li>
            <span aria-hidden="true">♡</span>
            <span>{t('welcome.voices')}</span>
          </li>
          <li>
            <span aria-hidden="true">🙏</span>
            <span>{t('welcome.prayer')}</span>
          </li>
        </ul>
        <p className="welcome-disclaimer">{t('welcome.disclaimer')}</p>
        <div className="welcome-settings">
          <div>
            <label htmlFor={`${id}-lang`}>{t('welcome.language')}</label>
            <select id={`${id}-lang`} value={lang} onChange={(e) => setLang(e.target.value as Lang)}>
              <option value="de">Deutsch</option>
              <option value="en">English</option>
            </select>
          </div>
          <fieldset className="welcome-theme">
            <legend>{t('theme.label')}</legend>
            <div className="welcome-theme-options">
              {THEMES.map((th) => (
                <label key={th}>
                  <input type="radio" name={`${id}-theme`} value={th} checked={theme === th} onChange={() => setTheme(th)} />
                  <span>{t(THEME[th].label)}</span>
                </label>
              ))}
            </div>
          </fieldset>
        </div>
        <button type="button" className="welcome-start" onClick={() => dialog.current?.close()}>
          {t('welcome.start')}
        </button>
        <p className="welcome-later">{t('welcome.later')}</p>
      </div>
    </dialog>
  );
}
