import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { isVideoSubmissionType } from '@arche/shared';
import en from './en.json';
import de from './de.json';
import { useSettings } from '@/store/settings';
import { localDate, localTime } from '@/lib/format';
import { LIMIT_KINDS, daysAhead } from '@/lib/intake';

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

/** "heute ab 19:42", "morgen ab 09:15", "am Montag ab 09:15": in the listener's own time. */
export function whenText(start: number, now: number): string {
  const lang = i18n.language === 'de' ? 'de' : 'en';
  const time = localTime(start, lang);
  const ahead = daysAhead(start, now);
  if (ahead === 0) return i18n.t('submit.locked.today', { time });
  if (ahead === 1) return i18n.t('submit.locked.tomorrow', { time });
  return i18n.t('submit.locked.onDay', { day: localDate(start, lang, { weekday: 'long' }), time });
}

/**
 * A refused send in the listener's words: which of their limits it met
 * (Submissions::limit — so many waiting at once, so many a day) and, for
 * the day's, when the next is possible. Else the code's text: a server from
 * before the limits said which only knew "too many attempts".
 */
export function refusalText(code: string, detail: Record<string, unknown>, now: number): string {
  const { limit, kind, max, until } = detail;
  if (code === 'rate_limited' && LIMIT_KINDS.includes(kind as never) && typeof max === 'number') {
    const what = i18n.t(`submit.kinds.${kind as string}`);
    if (limit === 'waiting') return i18n.t('errors.waiting', { count: max, what });
    if (limit === 'day' && typeof until === 'number') return i18n.t('errors.daily', { max, what, when: whenText(until, now) });
  }
  return errorText(code);
}

export default i18n;
