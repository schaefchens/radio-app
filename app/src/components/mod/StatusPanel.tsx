import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { localDate, localTime } from '@/lib/format';
import { useApi } from './useApi';
import { Loading, Notice, Pill, Section } from './ui';

interface StatusData {
  now: number;
  lastTick: { at: number; source: string } | null;
  channels: { slug: string; frontier: number | null; published: number | null; aheadSec: number | null; drafts: number; listeners: number }[];
  jobs: Record<string, number>;
  hostBreaks: { state: string; source: string; n: number }[];
  usage: { day: string; kind: string; calls: number; input_tokens: number; output_tokens: number; cost_micros: number }[];
  spentTodayUsd: number;
  budgetUsd: number;
  /** '' = no text model: templates only, submissions closed. */
  ai: { text: '' | 'stub' | 'anthropic' | 'openai'; hostModel: string; moderationModel: string; voice: 'openai' | 'elevenlabs' | 'stub' };
  realtime: { driver: string; slots: string[]; nodes: { slot: string; state: string; connections: number; last_report: number | null; error: string }[] };
  /** base '' = the app reads from this site; api = the server can purge and count (absent from an older server). */
  cdn?: { base: string; api: boolean; queued: number; counts: Record<string, { minute: number; n: number } | null> };
  audit: { time: number; actor: string; event: string; detail: string }[];
}

const PROVIDERS: Record<string, string> = { anthropic: 'Claude', openai: 'OpenAI', elevenlabs: 'ElevenLabs', stub: 'Stub' };

export function StatusPanel() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const { data, error, reload } = useApi<StatusData>('/mod/status');

  useEffect(() => {
    const id = setInterval(reload, 15_000);
    return () => clearInterval(id);
  }, [reload]);

  if (error && !data) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Loading />;
  const tickAge = data.lastTick ? Math.max(0, Math.round((data.now - data.lastTick.at * 1000) / 1000)) : null;
  const time = (ms: number): string => `${localDate(ms, lang, { day: 'numeric', month: 'short' })} ${localTime(ms, lang)}`;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Section title={t('mod.status.lastTick')} actions={<button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={reload}>{t('mod.common.refresh')}</button>}>
        <p className="text-sm">
          {tickAge === null ? t('mod.status.never') : t('mod.status.ago', { n: tickAge })}{' '}
          {data.lastTick && <span className="text-ink-faint">{t('mod.status.source', { source: data.lastTick.source })}</span>}{' '}
          {tickAge !== null && tickAge > 150 && <Pill tone="bad">!</Pill>}
        </p>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-ink-muted">
              <tr>
                <th className="py-1 pr-3">{t('mod.common.channel')}</th>
                <th className="py-1 pr-3">{t('mod.status.frontier')}</th>
                <th className="py-1 pr-3">{t('mod.status.ahead')}</th>
                <th className="py-1 pr-3">{t('mod.status.drafts')}</th>
                <th className="py-1">{t('mod.status.listeners')}</th>
              </tr>
            </thead>
            <tbody>
              {data.channels.map((c) => (
                <tr key={c.slug} className="border-t border-night-line/20">
                  <td className="py-1 pr-3 font-medium">{c.slug}</td>
                  <td className="py-1 pr-3 tabular-nums">{c.frontier ? localTime(c.frontier, lang) : '—'}</td>
                  <td className="py-1 pr-3 tabular-nums">
                    {c.aheadSec === null ? '—' : <Pill tone={c.aheadSec < 300 ? 'bad' : c.aheadSec < 600 ? 'warn' : 'good'}>{Math.round(c.aheadSec / 60)} {t('mod.common.minutes')}</Pill>}
                  </td>
                  <td className="py-1 pr-3 tabular-nums">{c.drafts}</td>
                  <td className="py-1 tabular-nums">{c.listeners}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <Section title={t('mod.status.ai')}>
        {data.ai.text === '' ? (
          <Notice tone="error">{t('mod.status.noTextModel')}</Notice>
        ) : (
          <p className="text-sm">
            {t('mod.status.textModel', { provider: PROVIDERS[data.ai.text], host: data.ai.hostModel || '—', moderation: data.ai.moderationModel || '—' })}
            {' · '}
            {t('mod.status.voice', { provider: PROVIDERS[data.ai.voice] })}
          </p>
        )}
        <p className="text-sm">{t('mod.status.spend', { spent: data.spentTodayUsd.toFixed(2), budget: data.budgetUsd.toFixed(2) })}</p>
        <div className="h-2 overflow-hidden rounded-full bg-night-line/30">
          <div className="h-full rounded-full bg-brand" style={{ width: `${Math.min(100, (data.spentTodayUsd / Math.max(0.01, data.budgetUsd)) * 100)}%` }} />
        </div>
        <div className="max-h-48 overflow-auto">
          <table className="w-full text-left text-xs">
            <thead className="text-ink-muted">
              <tr>
                <th className="py-1 pr-2">{t('mod.status.day')}</th>
                <th className="py-1 pr-2">{t('mod.status.kind')}</th>
                <th className="py-1 pr-2">{t('mod.status.calls')}</th>
                <th className="py-1">{t('mod.status.cost')}</th>
              </tr>
            </thead>
            <tbody>
              {data.usage.map((u) => (
                <tr key={`${u.day}-${u.kind}`} className="border-t border-night-line/20">
                  <td className="py-1 pr-2 tabular-nums">{u.day}</td>
                  <td className="py-1 pr-2">{u.kind}</td>
                  <td className="py-1 pr-2 tabular-nums">{u.calls}</td>
                  <td className="py-1 tabular-nums">${(u.cost_micros / 1_000_000).toFixed(3)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <Section title={t('mod.status.jobs')}>
        <div className="flex flex-wrap gap-2">
          {Object.entries(data.jobs).map(([k, n]) => (
            <Pill key={k} tone={k === 'failed' ? 'bad' : k === 'done' ? 'good' : 'default'}>
              {k}: {n}
            </Pill>
          ))}
        </div>
        <p className="label mt-2">{t('mod.status.hostBreaks')}</p>
        <ul className="flex flex-col gap-1 text-xs">
          {data.hostBreaks.map((h) => (
            <li key={`${h.state}-${h.source}`} className="flex justify-between gap-2">
              <span>
                {h.state} <span className="text-ink-faint">{h.source}</span>
              </span>
              <span className="tabular-nums">{h.n}</span>
            </li>
          ))}
        </ul>
      </Section>

      <Section title={t('mod.status.realtime')}>
        <p className="text-sm">
          {t('mod.status.driver')}: <span className="font-semibold">{data.realtime.driver}</span>
          {data.realtime.slots.length > 0 && <span className="text-ink-faint"> · {data.realtime.slots.join(', ')}</span>}
        </p>
        {data.realtime.nodes.length === 0 ? (
          <p className="text-sm text-ink-muted">{t('mod.status.noNodes')}</p>
        ) : (
          <table className="w-full text-left text-xs">
            <thead className="text-ink-muted">
              <tr>
                <th className="py-1 pr-2">{t('mod.status.slot')}</th>
                <th className="py-1 pr-2">{t('mod.status.state')}</th>
                <th className="py-1 pr-2">{t('mod.status.connections')}</th>
                <th className="py-1">{t('mod.status.lastReport')}</th>
              </tr>
            </thead>
            <tbody>
              {data.realtime.nodes.map((n) => (
                <tr key={n.slot} className="border-t border-night-line/20">
                  <td className="py-1 pr-2">{n.slot}</td>
                  <td className="py-1 pr-2">
                    {n.state}
                    {n.error && <span className="block text-heart">{n.error}</span>}
                  </td>
                  <td className="py-1 pr-2 tabular-nums">{n.connections}</td>
                  <td className="py-1 tabular-nums">{n.last_report ? localTime(n.last_report * 1000, lang) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      {data.cdn && (
        <Section title={t('mod.status.cdn')}>
          {data.cdn.base === '' ? (
            <p className="text-sm text-ink-muted">{t('mod.status.cdnOff')}</p>
          ) : (
            <>
              <p className="text-sm">
                <span className="font-semibold">{data.cdn.base.replace(/^https?:\/\//, '')}</span>
                {data.cdn.api && <span className="text-ink-faint"> · {t('mod.status.cdnQueued', { n: data.cdn.queued })}</span>}
              </p>
              {data.cdn.api ? (
                <ul className="flex flex-col gap-1 text-xs">
                  {Object.entries(data.cdn.counts).map(([slug, c]) => (
                    <li key={slug} className="flex justify-between gap-2">
                      <span>{t('mod.status.cdnListeners', { channel: slug })}</span>
                      <span className="tabular-nums">{c ? `${c.n} (${localTime(c.minute, lang)})` : '—'}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <Notice tone="error">{t('mod.status.cdnNoApi')}</Notice>
              )}
            </>
          )}
        </Section>
      )}

      <Section title={t('mod.status.audit')} className="lg:col-span-2">
        <ul className="flex max-h-96 flex-col gap-1 overflow-auto text-xs">
          {data.audit.map((a, i) => (
            <li key={i} className="grid grid-cols-[7.5rem_7rem_1fr] gap-2 border-t border-night-line/10 py-1">
              <span className="tabular-nums text-ink-faint">{time(a.time * 1000)}</span>
              <span className="truncate text-ink-muted">{a.actor}</span>
              <span className="min-w-0">
                <span className="font-medium">{a.event}</span> <span className="break-words text-ink-faint">{a.detail}</span>
              </span>
            </li>
          ))}
        </ul>
      </Section>
    </div>
  );
}
