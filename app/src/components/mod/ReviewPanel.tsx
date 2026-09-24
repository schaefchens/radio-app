import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { deviceHeaders } from '@/lib/device';
import { clockDuration, localDate, localTime } from '@/lib/format';
import { useApi } from './useApi';
import { useOverview } from './overview';
import { modError } from './modApi';
import { Loading, Notice, Pill, Section } from './ui';

type Status = 'received' | 'checking' | 'review' | 'approved' | 'scheduled' | 'aired' | 'library' | 'missed' | 'rejected';

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
  status: Status;
  /** The generic reason the listener was shown. */
  reason: string;
  updated: number;
  /** Rejected only: why it can no longer be approved (null: it can). */
  blocker: 'recording_deleted' | 'video_unplayable' | 'not_rejected' | null;
}

const REASONS = ['not_program_fit', 'not_suitable', 'not_accepted'] as const;
const FILTERS = ['review', 'rejected', 'all'] as const;
type Filter = (typeof FILTERS)[number];
const FLAGS = ['safe', 'christian', 'program_fit', 'message_ok', 'type_allowed'] as const;

/**
 * What the automatic check decided and why: the review queue (it was unsure;
 * only with MODERATION_HUMAN_REVIEW=1), the rejections — which a moderator
 * can overrule while they can still air — and everything recent.
 */
export function ReviewPanel() {
  const { t } = useTranslation();
  // Rejections are what a station without human review mostly has to look at.
  const [filter, setFilter] = useState<Filter>(() => ((useOverview.getState().data?.review ?? 0) > 0 ? 'review' : 'rejected'));
  const { data, error, reload } = useApi<{ items: ReviewItem[] }>(`/mod/review?status=${filter}`);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const decided = (text: string, tone: 'ok' | 'error' = 'ok'): void => {
    setNotice({ tone, text });
    reload();
    void useOverview.getState().load();
  };
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-1" role="group" aria-label={t('mod.review.filterLabel')}>
        {FILTERS.map((f) => (
          <button
            key={f}
            type="button"
            aria-pressed={filter === f}
            className={clsx('rounded-xl px-3 py-2 text-sm', filter === f ? 'bg-brand/20 text-ink shadow-glow' : 'text-ink-muted hover:text-ink')}
            onClick={() => {
              setFilter(f);
              setNotice(null);
            }}
          >
            {t(`mod.review.filter.${f}`)}
          </button>
        ))}
      </div>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {error && <Notice tone="error">{error}</Notice>}
      {!data ? (
        <Loading />
      ) : data.items.length === 0 ? (
        <p className="card px-4 py-3 text-sm text-ink-muted">{t(`mod.review.empty.${filter}`)}</p>
      ) : (
        data.items.map((it) => <ReviewCard key={it.id} item={it} onDone={decided} />)
      )}
    </div>
  );
}

function ReviewCard({ item, onDone }: { item: ReviewItem; onDone: (text: string, tone?: 'ok' | 'error') => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [reason, setReason] = useState<(typeof REASONS)[number]>('not_accepted');
  const [busy, setBusy] = useState(false);
  const decide = async (decision: 'approve' | 'reject', keepMessage = true): Promise<void> => {
    setBusy(true);
    try {
      await api(`/mod/review/${item.id}`, { body: { decision, reason, keepMessage } });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  const when = (ms: number): string => `${localDate(ms, lang)} ${localTime(ms, lang)}`;
  const withMessage = item.type === 'song' && item.message.trim() !== '';
  const approveButtons = (label: string): React.ReactNode => (
    <>
      <button type="button" className="btn-primary" disabled={busy} onClick={() => void decide('approve')}>
        {label}
      </button>
      {withMessage && (
        <button type="button" className="btn-ghost" disabled={busy} onClick={() => void decide('approve', false)}>
          {t('mod.review.withoutMessage')}
        </button>
      )}
    </>
  );
  return (
    <Section title={`${t(`mod.review.types.${item.type}`, { defaultValue: item.type })}${item.program ? ` · ${item.program}` : ''}`} actions={<StatusPill status={item.status} />}>
      <p className="text-xs text-ink-muted">
        {item.place ? t('mod.review.listenerFrom', { name: item.name || '—', place: item.place }) : item.name || '—'} · {when(item.created)}
        {item.status === 'rejected' && ` · ${t('mod.review.rejectedAt', { when: when(item.updated) })}`}
        {/* The id a listener's own list shows: what they quote when they ask about it. */}
        <span className="text-ink-faint"> · #{item.id}</span>
      </p>
      {(item.video || item.yt) && (
        <div className="card-inset p-3 text-sm">
          <p className="label">{t('mod.review.video')}</p>
          {item.video?.title && <p className="font-semibold">{item.video.title}</p>}
          {item.video && (
            <p className="text-ink-muted">
              {item.video.artist || item.video.channel} {item.video.duration_ms ? `· ${clockDuration(item.video.duration_ms)}` : ''}
            </p>
          )}
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
      {item.mode === 'audio' && item.status === 'review' && <Recording id={item.id} />}
      {item.transcript && (
        <div>
          <p className="label">{t('mod.review.transcript')}</p>
          <p className="whitespace-pre-wrap text-sm text-ink-muted">{item.transcript}</p>
        </div>
      )}
      <Why item={item} />
      {item.status === 'review' && (
        <div className="flex flex-wrap items-center gap-2">
          {approveButtons(t('mod.review.approve'))}
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
      )}
      {item.status === 'rejected' &&
        (item.blocker === null ? (
          <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">{approveButtons(t('mod.review.overrule'))}</div>
            <p className="text-xs text-ink-faint">{t('mod.review.overruleHint')}</p>
          </div>
        ) : (
          <p className="text-xs text-ink-faint">{t(`mod.review.blockers.${item.blocker}`)}</p>
        ))}
    </Section>
  );
}

function StatusPill({ status }: { status: Status }) {
  const { t } = useTranslation();
  const tone = status === 'rejected' ? 'bad' : status === 'review' ? 'warn' : ['approved', 'scheduled', 'aired', 'library'].includes(status) ? 'good' : 'default';
  return <Pill tone={tone}>{t(`mod.review.statuses.${status}`, { defaultValue: status })}</Pill>;
}

/** The exact grounds of the decision, from the stored verdict (never shown to the listener). */
function Why({ item }: { item: ReviewItem }) {
  const { t } = useTranslation();
  const v = item.verdict ?? {};
  const note = typeof v.note === 'string' && v.note.trim() !== '' ? v.note : null;
  const video = typeof v.video === 'string' ? [v.video] : Array.isArray(v.video) ? v.video.map(String) : [];
  const error = typeof v.error === 'string' ? v.error : null;
  const flags = FLAGS.filter((k) => typeof v[k] === 'boolean');
  if (!note && video.length === 0 && !error && flags.length === 0 && v.refusal !== true && item.status !== 'rejected') return null;
  const duration = typeof v.duration_ms === 'number' && v.duration_ms > 0 ? clockDuration(v.duration_ms) : '—';
  const markets = Array.isArray(v.blocked_in) ? v.blocked_in.map(String).join(', ') : '';
  return (
    <div className="card-inset flex flex-col gap-2 p-3">
      <p className="label">{t('mod.review.why')}</p>
      {note && <p className="text-sm">„{note}“</p>}
      {video.length > 0 && (
        <ul className="list-disc pl-5 text-sm">
          {video.map((code) => (
            <li key={code}>{t(`mod.review.videoProblems.${code}`, { defaultValue: t('mod.review.videoProblems.unplayable'), duration, markets })}</li>
          ))}
        </ul>
      )}
      {error && <p className="text-sm">{t(`mod.review.errors.${error}`, { defaultValue: error })}</p>}
      {v.refusal === true && <p className="text-sm">{t('mod.review.refusal')}</p>}
      {(flags.length > 0 || typeof v.verdict === 'string') && (
        <div className="flex flex-wrap gap-1">
          {flags.map((k) => (
            <Pill key={k} tone={v[k] === true ? 'good' : 'bad'}>
              {v[k] === true ? '✓' : '✗'} {t(`mod.review.flags.${k}`)}
            </Pill>
          ))}
          {typeof v.verdict === 'string' && (
            <Pill tone={v.verdict === 'approve' ? 'good' : v.verdict === 'reject' ? 'bad' : 'warn'}>{t(`mod.review.verdicts.${v.verdict}`, { defaultValue: v.verdict })}</Pill>
          )}
        </div>
      )}
      <p className="text-xs text-ink-faint">
        {v.overruled === true ? t('mod.review.overruled') : v.human === true ? t('mod.review.human') : t('mod.review.automatic')}
        {item.status === 'rejected' && ` · ${t('mod.review.listenerSaw', { reason: t(`mod.review.reasons.${item.reason || 'not_accepted'}`, { defaultValue: item.reason }) })}`}
      </p>
    </div>
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
