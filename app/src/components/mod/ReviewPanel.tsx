import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { deviceHeaders } from '@/lib/device';
import { clockDuration, localDate, localTime } from '@/lib/format';
import { useApi } from './useApi';
import { useOverview } from './overview';
import { modError } from './modApi';
import { Loading, Notice, Pill, Section } from './ui';

interface ReviewItem {
  id: string;
  type: string;
  mode: string;
  program: string | null;
  name: string;
  place: string;
  message: string;
  text: string;
  transcript: string;
  yt: string | null;
  video: { title?: string; artist?: string; channel?: string; duration_ms?: number } | null;
  verdict: Record<string, unknown> | null;
  created: number;
}

const REASONS = ['not_program_fit', 'not_suitable', 'not_accepted'] as const;

/** Submissions the automatic check was unsure about (only with MODERATION_HUMAN_REVIEW=1). */
export function ReviewPanel() {
  const { t } = useTranslation();
  const { data, error, reload } = useApi<{ items: ReviewItem[] }>('/mod/review');
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const decided = (text: string, tone: 'ok' | 'error' = 'ok'): void => {
    setNotice({ tone, text });
    reload();
    void useOverview.getState().load();
  };
  return (
    <div className="flex flex-col gap-3">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {error && <Notice tone="error">{error}</Notice>}
      {!data ? <Loading /> : data.items.length === 0 ? <p className="card px-4 py-3 text-sm text-ink-muted">{t('mod.review.empty')}</p> : data.items.map((it) => <ReviewCard key={it.id} item={it} onDone={decided} />)}
    </div>
  );
}

function ReviewCard({ item, onDone }: { item: ReviewItem; onDone: (text: string, tone?: 'ok' | 'error') => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [reason, setReason] = useState<(typeof REASONS)[number]>('not_accepted');
  const [busy, setBusy] = useState(false);
  const decide = async (decision: 'approve' | 'reject'): Promise<void> => {
    setBusy(true);
    try {
      await api(`/mod/review/${item.id}`, { body: { decision, reason } });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  const v = item.verdict ?? {};
  const flag = (k: string): React.ReactNode => (k in v ? <Pill tone={v[k] === true ? 'good' : 'bad'}>{k}</Pill> : null);
  return (
    <Section title={`${item.type}${item.program ? ` · ${item.program}` : ''}`}>
      <p className="text-xs text-ink-muted">
        {item.place ? t('mod.review.listenerFrom', { name: item.name || '—', place: item.place }) : item.name || '—'} · {localDate(item.created, lang)} {localTime(item.created, lang)}
      </p>
      {item.video && (
        <div className="card-inset p-3 text-sm">
          <p className="label">{t('mod.review.video')}</p>
          <p className="font-semibold">{item.video.title}</p>
          <p className="text-ink-muted">
            {item.video.artist || item.video.channel} {item.video.duration_ms ? `· ${clockDuration(item.video.duration_ms)}` : ''}
          </p>
          {item.yt && (
            <a className="text-xs text-brand-bright underline" href={`https://www.youtube.com/watch?v=${item.yt}`} target="_blank" rel="noreferrer">
              youtube.com/watch?v={item.yt}
            </a>
          )}
        </div>
      )}
      {item.message && (
        <div>
          <p className="label">{t('mod.review.message')}</p>
          <p className="text-sm">{item.message}</p>
        </div>
      )}
      {item.text && <p className="text-sm">{item.text}</p>}
      {item.mode === 'audio' && <Recording id={item.id} />}
      {item.transcript && (
        <div>
          <p className="label">{t('mod.review.transcript')}</p>
          <p className="whitespace-pre-wrap text-sm text-ink-muted">{item.transcript}</p>
        </div>
      )}
      <div>
        <p className="label">{t('mod.review.verdict')}</p>
        <div className="flex flex-wrap gap-1">
          {flag('safe')}
          {flag('christian')}
          {flag('program_fit')}
          {flag('message_ok')}
          {typeof v.verdict === 'string' && <Pill tone="warn">{v.verdict}</Pill>}
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-primary" disabled={busy} onClick={() => void decide('approve')}>
          {t('mod.review.approve')}
        </button>
        <select className="field w-auto" value={reason} onChange={(e) => setReason(e.target.value as (typeof REASONS)[number])} aria-label={t('mod.review.reason')}>
          {REASONS.map((r) => (
            <option key={r} value={r}>
              {t(`mod.review.reasons.${r}`)}
            </option>
          ))}
        </select>
        <button type="button" className="btn-ghost text-heart" disabled={busy} onClick={() => void decide('reject')}>
          {t('mod.review.reject')}
        </button>
      </div>
    </Section>
  );
}

/**
 * The upload is private until approved: it comes from /api with the device
 * headers (which an <audio src> cannot send) and plays from a blob URL.
 */
function Recording({ id }: { id: string }) {
  const { t } = useTranslation();
  const [src, setSrc] = useState<{ id: string; url: string | null } | null>(null);
  useEffect(() => {
    let url: string | null = null;
    let cancelled = false;
    void fetch(`/api/mod/review/${id}/audio`, { headers: deviceHeaders(), cache: 'no-store' })
      .then(async (r) => (r.ok ? URL.createObjectURL(await r.blob()) : null))
      .catch(() => null)
      .then((u) => {
        url = u;
        if (cancelled) {
          if (u) URL.revokeObjectURL(u);
        } else {
          setSrc({ id, url: u });
        }
      });
    return () => {
      cancelled = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [id]);
  const current = src?.id === id ? src : null;
  return (
    <div>
      <p className="label">{t('mod.review.recording')}</p>
      {!current ? (
        <Loading />
      ) : current.url ? (
        <audio controls preload="metadata" src={current.url} className="w-full" />
      ) : (
        <p className="text-sm text-heart">{t('mod.review.recordingMissing')}</p>
      )}
    </div>
  );
}
