import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { localTime } from '@/lib/format';
import { useApi } from './useApi';
import { useOverview } from './overview';
import { modError } from './modApi';
import { ConfirmButton, Loading, Notice, Pill, Section } from './ui';

interface Report {
  id: number;
  msg: string;
  text: string;
  author: string;
  reporter: string;
  reason: string;
  status: string;
  at: number;
}

interface Highlight {
  uid: string;
  channel: string;
  name: string;
  country: string;
  text: string;
  likes: number;
  reactions: number;
  status: 'candidate' | 'approved' | 'rejected';
  at: number;
}

export function ChatModPanel() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const reports = useApi<{ reports: Report[] }>('/mod/reports');
  const highlights = useApi<{ highlights: Highlight[] }>('/mod/highlights');
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);

  const act = async (fn: () => Promise<unknown>): Promise<void> => {
    try {
      await fn();
      setNotice({ tone: 'ok', text: t('mod.common.saved') });
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    }
    reports.reload();
    highlights.reload();
    void useOverview.getState().load();
  };

  const sortedHighlights = [...(highlights.data?.highlights ?? [])].sort((a, b) => (a.status === 'candidate' ? -1 : 0) - (b.status === 'candidate' ? -1 : 0));

  return (
    <div className="flex flex-col gap-4">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <Section title={t('mod.chat.reports')}>
        {reports.error && <Notice tone="error">{reports.error}</Notice>}
        {!reports.data ? (
          <Loading />
        ) : reports.data.reports.length === 0 ? (
          <p className="text-sm text-ink-muted">{t('mod.chat.noReports')}</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {reports.data.reports.map((r) => (
              <li key={r.id} className="card-inset flex flex-col gap-2 p-3 text-sm">
                <p className="whitespace-pre-wrap">{r.text}</p>
                <p className="text-xs text-ink-faint">
                  {r.author} · {t('mod.chat.reporter')} {r.reporter} · {r.reason} · {localTime(r.at, lang)}
                </p>
                <div className="flex flex-wrap gap-2">
                  <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => void act(() => api(`/mod/reports/${r.id}`, { body: { action: 'dismiss' } }))}>
                    {t('mod.chat.dismiss')}
                  </button>
                  <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => void act(() => api(`/mod/reports/${r.id}`, { body: { action: 'remove' } }))}>
                    {t('mod.chat.remove')}
                  </button>
                  <ConfirmButton
                    className="btn-ghost px-3 py-1.5 text-xs text-heart"
                    label={t('mod.chat.ban')}
                    question={t('mod.chat.ban') + '?'}
                    onConfirm={() => void act(() => api(`/mod/reports/${r.id}`, { body: { action: 'ban' } }))}
                  />
                </div>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Section title={t('mod.chat.highlights')}>
        {highlights.error && <Notice tone="error">{highlights.error}</Notice>}
        {!highlights.data ? (
          <Loading />
        ) : sortedHighlights.length === 0 ? (
          <p className="text-sm text-ink-muted">{t('mod.chat.noHighlights')}</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {sortedHighlights.map((h) => (
              <li key={h.uid} className="card-inset flex flex-wrap items-center gap-3 p-3 text-sm">
                <div className="min-w-0 flex-1">
                  <p>{h.text}</p>
                  <p className="text-xs text-ink-faint">
                    {h.name} {h.country && `· ${h.country}`} · {h.channel} · {t('mod.chat.likes', { n: h.likes })}
                  </p>
                </div>
                <Pill tone={h.status === 'approved' ? 'good' : h.status === 'rejected' ? 'bad' : 'warn'}>{h.status}</Pill>
                <div className="flex gap-2">
                  {h.status !== 'approved' && (
                    <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => void act(() => api(`/mod/highlights/${h.uid}`, { body: { status: 'approved' } }))}>
                      {t('mod.chat.approve')}
                    </button>
                  )}
                  {h.status !== 'rejected' && (
                    <button type="button" className="btn-ghost px-3 py-1.5 text-xs text-heart" onClick={() => void act(() => api(`/mod/highlights/${h.uid}`, { body: { status: 'rejected' } }))}>
                      {t('mod.chat.reject')}
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Blocklist />
    </div>
  );
}

function Blocklist() {
  const { data } = useApi<{ words: string[] }>('/mod/chat-blocklist');
  return data ? <BlocklistEditor key={data.words.join('\n')} words={data.words} /> : <Loading />;
}

function BlocklistEditor({ words }: { words: string[] }) {
  const { t } = useTranslation();
  const [text, setText] = useState(words.join('\n'));
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const save = async (): Promise<void> => {
    try {
      const r = await api<{ words: string[] }>('/mod/chat-blocklist', { method: 'PUT', body: { words: text.split('\n') } });
      setText(r.words.join('\n'));
      setNotice({ tone: 'ok', text: t('mod.common.saved') });
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    }
  };
  return (
    <Section title={t('mod.chat.blocklist')}>
      <p className="text-xs text-ink-faint">{t('mod.chat.blocklistHint')}</p>
      <textarea className="field min-h-[140px] font-mono" value={text} onChange={(e) => setText(e.target.value)} aria-label={t('mod.chat.blocklist')} />
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <button type="button" className="btn-primary self-start" onClick={() => void save()}>
        {t('mod.common.save')}
      </button>
    </Section>
  );
}
