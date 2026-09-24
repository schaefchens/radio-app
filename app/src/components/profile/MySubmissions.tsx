import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { localDate, localTime } from '@/lib/format';

interface SubmissionView {
  id: string;
  type: string;
  mode: string;
  status: 'pending' | 'approved' | 'scheduled' | 'aired' | 'library' | 'missed' | 'rejected';
  reason: string | null;
  title: string;
  airsAt: number | null;
  airedAt: number | null;
  created: number;
}

const TONE: Record<SubmissionView['status'], string> = {
  pending: 'text-amber-300',
  approved: 'text-brand-bright',
  scheduled: 'text-brand-bright',
  aired: 'text-emerald-300',
  library: 'text-brand-bright',
  missed: 'text-ink-muted',
  rejected: 'text-ink-muted',
};

/** What the listener handed in and where it stands; polled while visible. */
export function MySubmissions() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [items, setItems] = useState<SubmissionView[] | null>(null);

  useEffect(() => {
    let live = true;
    const load = async (): Promise<void> => {
      if (document.visibilityState !== 'visible') return;
      try {
        const r = await api<{ submissions: SubmissionView[] }>('/submissions');
        if (live) setItems(r.submissions);
      } catch {
        if (live) setItems((prev) => prev ?? []);
      }
    };
    void load();
    const id = setInterval(() => void load(), 30_000);
    return () => {
      live = false;
      clearInterval(id);
    };
  }, []);

  const typeLabel = (s: SubmissionView): string =>
    s.type === 'song' ? t('submit.song.title') : s.type === 'prayer' && s.mode === 'text' ? t('submit.prayer.title') : t(`record.${s.type}`);

  if (items === null) return <p className="text-sm text-ink-muted">{t('common.loading')}</p>;
  if (items.length === 0) return <p className="text-sm text-ink-muted">{t('status.empty')}</p>;
  return (
    <ul className="flex flex-col gap-2">
      {items.map((s) => (
        <li key={s.id} className="card-inset flex flex-col gap-0.5 px-3 py-2">
          <div className="flex items-center justify-between gap-2">
            <span className="truncate text-sm font-medium">
              {typeLabel(s)}
              {s.title ? ` · ${s.title}` : ''}
            </span>
            <span className="shrink-0 text-xs text-ink-faint">{localDate(s.created, lang)}</span>
          </div>
          <span className={clsx('text-xs', TONE[s.status])}>
            {t(`status.${s.status}`)}
            {s.status === 'scheduled' && s.airsAt ? ` · ${localTime(s.airsAt, lang)}` : ''}
            {s.status === 'aired' && s.airedAt ? ` · ${localTime(s.airedAt, lang)}` : ''}
          </span>
          {s.status === 'rejected' && s.reason && <span className="text-xs text-ink-faint">{t(`status.reason.${s.reason}`)}</span>}
        </li>
      ))}
    </ul>
  );
}
