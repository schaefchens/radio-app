/** Clock time in the listener's own timezone (the plan is authored in station time). */
export function localTime(ms: number, locale: string): string {
  return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date(ms));
}

export function localDate(ms: number, locale: string, opts: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' }): string {
  return new Intl.DateTimeFormat(locale, opts).format(new Date(ms));
}

/** "2:14" */
export function clockDuration(ms: number): string {
  const s = Math.max(0, Math.floor(ms / 1000));
  const m = Math.floor(s / 60);
  return `${m}:${String(s % 60).padStart(2, '0')}`;
}

export function ago(ms: number, now: number, locale: string): string {
  const diff = Math.round((ms - now) / 60_000);
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto', style: 'short' });
  if (Math.abs(diff) < 60) return rtf.format(diff, 'minute');
  return rtf.format(Math.round(diff / 60), 'hour');
}

/** The station-local date (Y-m-d) of an instant, for day files. */
export function stationDate(ms: number, tz: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(ms));
  const get = (t: string): string => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('year')}-${get('month')}-${get('day')}`;
}

export function addDays(date: string, days: number): string {
  const d = new Date(`${date}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

export function countryName(code: string, locale: string): string {
  if (!code) return '';
  try {
    return new Intl.DisplayNames([locale], { type: 'region' }).of(code) ?? code;
  } catch {
    return code;
  }
}

/** A best guess at the listener's country from the browser, never from IP. */
export function guessCountry(): string {
  const langs = navigator.languages?.length ? navigator.languages : [navigator.language];
  for (const l of langs) {
    const m = /-([A-Z]{2})$/i.exec(l ?? '');
    if (m?.[1]) return m[1].toUpperCase();
  }
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
  if (tz === 'Europe/Berlin') return 'DE';
  if (tz === 'Europe/Vienna') return 'AT';
  if (tz === 'Europe/Zurich') return 'CH';
  return '';
}
