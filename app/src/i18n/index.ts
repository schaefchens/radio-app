import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { isVideoSubmissionType } from '@arche/shared';
import en from './en.json';
import de from './de.json';
import { useSettings } from '@/store/settings';

void i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, de: { translation: de } },
  lng: useSettings.getState().lang,
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  returnNull: false,
});

useSettings.subscribe((s, prev) => {
  if (s.lang !== prev.lang) {
    void i18n.changeLanguage(s.lang);
    document.documentElement.lang = s.lang;
  }
});
document.documentElement.lang = useSettings.getState().lang;

/**
 * A submission type as the forms name it (a song request, a suggested
 * preaching, testimony video, mission video or film, a prayer request); a
 * recording by its kind. A testimony video must not read "A testimony": that
 * is the recorded one, often listed right next to it.
 */
export function submissionLabel(type: string): string {
  return type === 'song' || type === 'prayer' || isVideoSubmissionType(type) ? i18n.t(`submit.${type}.title`) : i18n.t(`record.${type}`);
}

/** Translate an API error code, falling back to a generic message. */
export function errorText(code: string): string {
  const key = `errors.${code}`;
  return i18n.exists(key) ? i18n.t(key) : i18n.t('errors.generic');
}

export default i18n;
