import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { useApi } from './useApi';
import {
  LINE_BULK_ACTIONS,
  LINE_KINDS,
  LINE_MOODS,
  LINE_SORTS,
  LINE_STATES,
  LINE_TIMES,
  modError,
  type LineBulkAction,
  type LineKind,
  type LineSort,
  type LinesOverview,
  type LineTime,
  type ModHost,
  type ModLine,
} from './modApi';
import { HostAvatar } from './HostLineup';
import { LineRow } from './LineRow';
import { LineOptionsCard } from './LineOptions';
import { ConfirmButton, Field, Loading, Notice, Pill, Section } from './ui';

/** Lines asked for at a time; "Load more" asks for the next as many. */
const PAGE = 50;
/** Stable references while loading: a new [] per render would re-render every row. */
const NO_HOSTS: ModHost[] = [];
const NO_PROGRAMS: LinesOverview['programs'] = [];
const NO_SELECTION: ReadonlySet<number> = new Set();

type Tone = 'ok' | 'error';
type ProgramChoice = LinesOverview['programs'][number];

/** The programs a kind's lines can belong to: the prayer hour's own kinds only in a prayer hour. */
function programsFor(kind: LineKind, programs: readonly ProgramChoice[]): ProgramChoice[] {
  return kind === 'present' || kind === 'prayertime' ? programs.filter((p) => p.format === 'prayer') : [...programs];
}

/**
 * /mod › Lines: a host's recorded lines — welcomes, outros, encouragements —
 * that the AI picks from instead of writing and voicing new words each time.
 * Moderators see how full each kind is, have new lines written, listen to,
 * edit, pause and remove lines; admins set the host's options. `?host=<id>`
 * opens a host (the Hosts list links here).
 */
export function LinesPanel() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const hostsApi = useApi<{ hosts: ModHost[] }>('/mod/hosts');
  // Active hosts first, those on air in a lineup before the rest: the one opened without ?host.
  const hosts = useMemo(
    () => [...(hostsApi.data?.hosts ?? NO_HOSTS)].sort((a, b) => Number(b.active) - Number(a.active) || Number(b.used_in.length > 0) - Number(a.used_in.length > 0)),
    [hostsApi.data],
  );
  const wanted = Number(params.get('host') ?? 0);
  const host = hosts.find((h) => h.id === wanted) ?? hosts[0] ?? null;
  const overview = useApi<LinesOverview>(host ? `/mod/lines/overview?host=${host.id}` : null);

  if (hostsApi.error) return <Notice tone="error">{hostsApi.error}</Notice>;
  if (!hostsApi.data) return <Loading />;
  if (!host) return <Notice>{t('mod.lines.noHosts')}</Notice>;
  const o = overview.data;

  return (
    <div className="flex flex-col gap-4">
      <Section
        title={t('mod.nav.lines')}
        actions={
          hosts.length > 1 ? (
            <label className="flex items-center gap-2 text-sm">
              <span className="text-ink-muted">{t('mod.lines.host')}</span>
              <select className="field w-auto py-1.5" value={host.id} onChange={(e) => setParams({ host: e.target.value }, { replace: true })}>
                {hosts.map((h) => (
                  <option key={h.id} value={h.id}>
                    {h.name}
                    {h.active ? '' : ` (${t('mod.common.inactive')})`}
                  </option>
                ))}
              </select>
            </label>
          ) : undefined
        }
      >
        <p className="text-sm text-ink-muted">{t('mod.lines.intro')}</p>
        {overview.error && <Notice tone="error">{overview.error}</Notice>}
        {o ? <Summary o={o} /> : !overview.error && <Loading />}
      </Section>
      {o && <PoolsSection key={`pools:${host.id}`} o={o} onChanged={overview.reload} />}
      <LinesList key={`list:${host.id}`} hostId={host.id} o={o} onChanged={overview.reload} />
      {o?.can_edit_options && <LineOptionsCard key={`options:${host.id}`} hostId={host.id} options={o.options} onSaved={overview.reload} />}
    </div>
  );
}

function Summary({ o }: { o: LinesOverview }) {
  const { t, i18n } = useTranslation();
  const sum = (k: 'active' | 'waiting' | 'failed' | 'old_voice'): number => o.pools.reduce((n, p) => n + p[k], 0);
  const voices = [...new Set(Object.values(o.host.voices).filter(Boolean))].join(' / ');
  const num = (n: number): string => n.toLocaleString(i18n.language);
  return (
    <div className="flex flex-col gap-2">
      <div className="flex min-w-0 items-center gap-3">
        <HostAvatar host={o.host} size="h-10 w-10" />
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium" style={{ color: o.host.color }}>
            {o.host.name}
          </p>
          <p className="truncate text-xs text-ink-muted">
            {t('mod.lines.voice', { voice: [t(`mod.hosts.providers.${o.host.provider}`), o.host.model, voices].filter(Boolean).join(' · ') })}
          </p>
        </div>
      </div>
      <div className="flex flex-wrap gap-1">
        <Pill tone="good">{t('mod.lines.summary.active', { n: num(sum('active')) })}</Pill>
        {sum('waiting') > 0 && <Pill tone="warn">{t('mod.lines.summary.waiting', { n: num(sum('waiting')) })}</Pill>}
        {sum('failed') > 0 && <Pill tone="bad">{t('mod.lines.summary.failed', { n: num(sum('failed')) })}</Pill>}
        {sum('old_voice') > 0 && <Pill tone="warn">{t('mod.lines.summary.oldVoice', { n: num(sum('old_voice')) })}</Pill>}
        {o.queued > 0 && <Pill>{t('mod.lines.queued', { count: o.queued })}</Pill>}
      </div>
      <p className="text-sm text-ink-muted">
        {t('mod.lines.month', { chars: num(o.month.chars), allowance: num(o.month.allowance) })}
        {o.host.provider === 'elevenlabs' && ` ${t('mod.lines.elevenlabsNote')}`}
      </p>
      {o.month.allowance <= 0 && <Notice>{t('mod.lines.noAllowance')}</Notice>}
    </div>
  );
}

/** How full each kind is, and new lines written on request. */
function PoolsSection({ o, onChanged }: { o: LinesOverview; onChanged: () => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [form, setForm] = useState<{ kind: LineKind; program_id: number | null } | null>(null);
  const [notice, setNotice] = useState<{ tone: Tone; text: string } | null>(null);
  const title = (id: number | null): string => (id === null ? t('mod.lines.pools.generic') : (o.programs.find((p) => p.id === id)?.title[lang] ?? `#${id}`));

  return (
    <Section
      title={t('mod.lines.pools.title')}
      actions={
        <button type="button" className="btn-ghost px-3 py-1.5" onClick={() => setForm({ kind: o.kinds[0] ?? 'encourage', program_id: null })}>
          {t('mod.lines.write.title')}
        </button>
      }
    >
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {form && (
        <WriteLinesForm
          key={`${form.kind}:${form.program_id ?? ''}`}
          o={o}
          initial={form}
          onCancel={() => setForm(null)}
          onQueued={(count) => {
            setForm(null);
            setNotice({ tone: 'ok', text: t('mod.lines.write.queued', { count }) });
            onChanged();
          }}
        />
      )}
      {o.pools.length === 0 ? (
        <p className="text-sm text-ink-muted">{t('mod.lines.pools.none')}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[34rem] text-left text-sm">
            <thead className="text-xs text-ink-faint">
              <tr>
                <th className="py-1 pr-3 font-medium">{t('mod.lines.pools.kind')}</th>
                <th className="py-1 pr-3 font-medium">{t('mod.lines.pools.lines')}</th>
                <th className="py-1 pr-3 font-medium">{t('mod.lines.pools.waiting')}</th>
                <th className="py-1 pr-3 font-medium">{t('mod.lines.pools.failed')}</th>
                <th className="py-1 pr-3 font-medium">{t('mod.lines.pools.oldVoice')}</th>
                <th className="py-1 pr-3 font-medium">{t('mod.lines.pools.aired')}</th>
                <th className="py-1">
                  <span className="sr-only">{t('mod.lines.write.open')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {o.pools.map((p) => (
                <tr key={`${p.kind}:${p.program_id ?? 'all'}`} className="border-t border-line/20 align-top">
                  <td className="py-2 pr-3">
                    {t(`mod.lines.kinds.${p.kind}`)}
                    <span className="block text-xs text-ink-faint">{title(p.program_id)}</span>
                  </td>
                  <td className="py-2 pr-3 tabular-nums">
                    {t('mod.lines.pools.ofTarget', { active: p.active, target: p.target })}{' '}
                    {p.active < p.target && <Pill tone="warn">{t('mod.lines.pools.low')}</Pill>}
                  </td>
                  <td className="py-2 pr-3 tabular-nums">{p.waiting}</td>
                  <td className={clsx('py-2 pr-3 tabular-nums', p.failed > 0 && 'text-heart')}>{p.failed}</td>
                  <td className="py-2 pr-3 tabular-nums">{p.old_voice}</td>
                  <td className="py-2 pr-3 tabular-nums">{p.aired_7d}</td>
                  <td className="py-2 text-right">
                    <button
                      type="button"
                      className="btn-ghost px-2 py-1 text-xs"
                      onClick={() => setForm({ kind: p.kind, program_id: p.program_id })}
                      aria-label={`${t('mod.lines.write.open')}: ${t(`mod.lines.kinds.${p.kind}`)} · ${title(p.program_id)}`}
                    >
                      {t('mod.lines.write.open')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Section>
  );
}

function WriteLinesForm({
  o,
  initial,
  onCancel,
  onQueued,
}: {
  o: LinesOverview;
  initial: { kind: LineKind; program_id: number | null };
  onCancel: () => void;
  onQueued: (count: number) => void;
}) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const kinds = o.kinds.length ? o.kinds : [...LINE_KINDS];
  const [kind, setKind] = useState<LineKind>(initial.kind);
  const choices = programsFor(kind, o.programs);
  const byProgram = o.program_kinds.includes(kind);
  const [programId, setProgramId] = useState<number | null>(initial.program_id ?? (byProgram ? (choices[0]?.id ?? null) : null));
  const [count, setCount] = useState(5);
  const [hint, setHint] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // A program from another kind's list (or none) is not one this kind can take.
  const program = byProgram ? (choices.find((p) => p.id === programId)?.id ?? choices[0]?.id ?? null) : null;

  const submit = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await api('/mod/lines/write', { body: { host_id: o.host.id, kind, program_id: program, count, hint: hint.trim() } });
      onQueued(count);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form
      className="card-inset flex flex-col gap-3 p-3"
      onSubmit={(e) => {
        e.preventDefault();
        void submit();
      }}
    >
      <p className="label">{t('mod.lines.write.title')}</p>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label={t('mod.lines.write.kind')}>
          <select className="field" value={kind} onChange={(e) => setKind(e.target.value as LineKind)}>
            {kinds.map((k) => (
              <option key={k} value={k}>
                {t(`mod.lines.kinds.${k}`)}
              </option>
            ))}
          </select>
        </Field>
        {byProgram && (
          <Field label={t('mod.lines.write.program')} hint={choices.length === 0 ? t('mod.lines.write.noProgram') : undefined}>
            <select className="field" value={program ?? ''} disabled={choices.length === 0} onChange={(e) => setProgramId(Number(e.target.value))}>
              {choices.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.title[lang]}
                </option>
              ))}
            </select>
          </Field>
        )}
        <Field label={t('mod.lines.write.count')}>
          <input type="number" min={1} max={10} className="field" value={count} onChange={(e) => setCount(Math.min(10, Math.max(1, Math.round(Number(e.target.value)) || 1)))} />
        </Field>
        <Field label={t('mod.lines.write.hint')}>
          <input className="field" maxLength={200} value={hint} placeholder={t('mod.lines.write.hintPlaceholder')} onChange={(e) => setHint(e.target.value)} />
        </Field>
      </div>
      <p className="text-xs text-ink-faint">{t('mod.lines.write.costHint')}</p>
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn-primary" disabled={busy || (byProgram && program === null)}>
          {busy ? t('mod.common.saving') : t('mod.lines.write.submit')}
        </button>
        <button type="button" className="btn-ghost" onClick={onCancel}>
          {t('mod.common.cancel')}
        </button>
      </div>
    </form>
  );
}

/** The lines themselves: filters, rows, a selection for bulk actions, and more on request. */
function LinesList({ hostId, o, onChanged }: { hostId: number; o: LinesOverview | null; onChanged: () => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const programs = o?.programs ?? NO_PROGRAMS;
  const [kind, setKind] = useState<'' | LineKind>('');
  const [program, setProgram] = useState('');
  const [state, setState] = useState('');
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [sort, setSort] = useState<LineSort>('newest');
  const [pages, setPages] = useState(1);
  const [selected, setSelected] = useState<ReadonlySet<number>>(NO_SELECTION);
  const [notice, setNotice] = useState<{ tone: Tone; text: string } | null>(null);
  const [adding, setAdding] = useState(false);
  const [busy, setBusy] = useState(false);

  const search = new URLSearchParams({ host: String(hostId) });
  if (kind) search.set('kind', kind);
  if (program) search.set('program', program);
  if (state) search.set('state', state);
  if (query) search.set('q', query);
  search.set('sort', sort);
  search.set('limit', String(PAGE * pages));
  const { data, error, reload } = useApi<{ lines: ModLine[]; total: number }>(`/mod/lines?${search.toString()}`);
  // The last answer stays on screen while the next loads (a filter, "Load more"), dimmed.
  const [kept, setKept] = useState<{ lines: ModLine[]; total: number } | null>(null);
  if (data && data !== kept) setKept(data);
  const list = data ?? kept;

  const refilter = (apply: () => void): void => {
    apply();
    setPages(1);
    setSelected(NO_SELECTION);
  };
  const changed = (text: string, tone: Tone = 'ok'): void => {
    setNotice({ tone, text });
    reload();
    onChanged();
  };
  const titleOf = (id: number | null): string | null => (id === null ? null : (programs.find((p) => p.id === id)?.title[lang] ?? `#${id}`));
  const shown = list?.lines ?? [];
  const picked = shown.filter((l) => selected.has(l.id)).map((l) => l.id);
  const selectable = shown.filter((l) => l.state !== 'removed');
  const allPicked = selectable.length > 0 && selectable.every((l) => selected.has(l.id));

  const bulk = async (action: LineBulkAction): Promise<void> => {
    if (picked.length === 0) return;
    setBusy(true);
    try {
      const r = await api<{ ok: boolean; changed: number }>('/mod/lines/bulk', { body: { ids: picked, action } });
      setSelected(NO_SELECTION);
      changed(t('mod.lines.done.changed', { count: r.changed }));
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section
      title={t('mod.lines.list.title')}
      actions={
        o && (
          <button type="button" className="btn-ghost px-3 py-1.5" onClick={() => setAdding((v) => !v)}>
            {t('mod.lines.add.open')}
          </button>
        )
      }
    >
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {adding && o && (
        <AddLineForm
          o={o}
          onCancel={() => setAdding(false)}
          onAdded={() => {
            setAdding(false);
            changed(t('mod.lines.add.done'));
          }}
        />
      )}
      <form
        className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
        aria-label={t('mod.lines.filters.label')}
        onSubmit={(e) => {
          e.preventDefault();
          refilter(() => setQuery(q.trim()));
        }}
      >
        <Field label={t('mod.lines.filters.kind')}>
          <select className="field py-1.5" value={kind} onChange={(e) => refilter(() => setKind(e.target.value as '' | LineKind))}>
            <option value="">{t('mod.lines.filters.allKinds')}</option>
            {LINE_KINDS.map((k) => (
              <option key={k} value={k}>
                {t(`mod.lines.kinds.${k}`)}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('mod.lines.filters.program')}>
          <select className="field py-1.5" value={program} onChange={(e) => refilter(() => setProgram(e.target.value))}>
            <option value="">{t('mod.lines.filters.allPrograms')}</option>
            <option value="generic">{t('mod.lines.filters.generic')}</option>
            {programs.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title[lang]}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('mod.lines.filters.state')}>
          <select className="field py-1.5" value={state} onChange={(e) => refilter(() => setState(e.target.value))}>
            <option value="">{t('mod.lines.filters.allStates')}</option>
            {LINE_STATES.map((s) => (
              <option key={s} value={s}>
                {t(`mod.lines.states.${s}`)}
              </option>
            ))}
            <option value="old_voice">{t('mod.lines.oldVoice')}</option>
          </select>
        </Field>
        <Field label={t('mod.lines.filters.sort')}>
          <select className="field py-1.5" value={sort} onChange={(e) => refilter(() => setSort(e.target.value as LineSort))}>
            {LINE_SORTS.map((s) => (
              <option key={s} value={s}>
                {t(`mod.lines.sorts.${s}`)}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('mod.lines.filters.search')}>
          <input className="field py-1.5" value={q} onChange={(e) => setQ(e.target.value)} />
        </Field>
        <div className="flex items-end">
          <button type="submit" className="btn-ghost px-3 py-1.5">
            {t('mod.common.search')}
          </button>
        </div>
      </form>

      {error && <Notice tone="error">{error}</Notice>}
      {!list ? (
        !error && <Loading />
      ) : list.lines.length === 0 ? (
        <p className="text-sm text-ink-muted">{t('mod.lines.list.empty')}</p>
      ) : (
        <div className={clsx('flex flex-col gap-2', data === null && 'opacity-60')}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="h-4 w-4 accent-accent-fill"
                checked={allPicked}
                disabled={selectable.length === 0}
                onChange={(e) => setSelected(e.target.checked ? new Set(selectable.map((l) => l.id)) : NO_SELECTION)}
              />
              {t('mod.lines.list.selectAll')}
            </label>
            <span className="text-xs text-ink-faint">{t('mod.lines.list.count', { shown: list.lines.length, total: list.total })}</span>
          </div>
          {picked.length > 0 && (
            <div className="card-inset flex flex-wrap items-center gap-2 p-2" role="group" aria-label={t('mod.lines.bulk.label')}>
              <span className="text-sm font-medium">{t('mod.lines.bulk.selected', { count: picked.length })}</span>
              {LINE_BULK_ACTIONS.map((a) =>
                a === 'remove' || a === 'rerecord' ? (
                  <ConfirmButton
                    key={a}
                    className={clsx('btn-ghost px-3 py-1.5 text-xs', a === 'remove' && 'text-heart')}
                    label={t(`mod.lines.actions.${a}`)}
                    question={t(a === 'remove' ? 'mod.lines.bulk.confirmRemove' : 'mod.lines.bulk.confirmRerecord', { count: picked.length })}
                    disabled={busy}
                    onConfirm={() => void bulk(a)}
                  />
                ) : (
                  <button key={a} type="button" className="btn-ghost px-3 py-1.5 text-xs" disabled={busy} onClick={() => void bulk(a)}>
                    {t(`mod.lines.actions.${a}`)}
                  </button>
                ),
              )}
              <button type="button" className="px-2 py-1 text-xs text-ink-muted hover:text-ink" onClick={() => setSelected(NO_SELECTION)}>
                {t('mod.lines.bulk.clear')}
              </button>
            </div>
          )}
          <ul className="flex flex-col gap-2">
            {list.lines.map((line) => (
              <LineRow
                key={`${line.id}:${line.updated}`}
                line={line}
                programTitle={titleOf(line.program_id)}
                selected={selected.has(line.id)}
                onSelect={(on) =>
                  setSelected((s) => {
                    const next = new Set(s);
                    if (on) next.add(line.id);
                    else next.delete(line.id);
                    return next;
                  })
                }
                onChanged={changed}
              />
            ))}
          </ul>
          {list.lines.length < list.total && (
            <div>
              <button type="button" className="btn-ghost px-3 py-1.5" disabled={data === null} onClick={() => setPages((p) => p + 1)}>
                {t('mod.lines.list.more')}
              </button>
            </div>
          )}
        </div>
      )}
    </Section>
  );
}

/** A line a moderator writes: recorded in the host's voice like one the AI wrote. */
function AddLineForm({ o, onCancel, onAdded }: { o: LinesOverview; onCancel: () => void; onAdded: () => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const kinds = o.kinds.length ? o.kinds : [...LINE_KINDS];
  const [kind, setKind] = useState<LineKind>(kinds[0] ?? 'encourage');
  const choices = programsFor(kind, o.programs);
  const byProgram = o.program_kinds.includes(kind);
  const [programId, setProgramId] = useState<number | null>(null);
  const [texts, setTexts] = useState<Record<Lang, string>>({ en: '', de: '' });
  const [time, setTime] = useState<LineTime>('any');
  const [mood, setMood] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const program = byProgram ? (choices.find((p) => p.id === programId)?.id ?? choices[0]?.id ?? null) : null;

  const submit = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const words = Object.fromEntries((['en', 'de'] as const).filter((l) => texts[l].trim() !== '').map((l) => [l, texts[l].trim()]));
      await api('/mod/lines', { body: { host_id: o.host.id, kind, program_id: program, texts: words, tags: { time, mood } } });
      onAdded();
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form
      className="card-inset flex flex-col gap-3 p-3"
      onSubmit={(e) => {
        e.preventDefault();
        void submit();
      }}
    >
      <p className="label">{t('mod.lines.add.title')}</p>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label={t('mod.lines.write.kind')}>
          <select className="field" value={kind} onChange={(e) => setKind(e.target.value as LineKind)}>
            {kinds.map((k) => (
              <option key={k} value={k}>
                {t(`mod.lines.kinds.${k}`)}
              </option>
            ))}
          </select>
        </Field>
        {byProgram && (
          <Field label={t('mod.lines.write.program')} hint={choices.length === 0 ? t('mod.lines.write.noProgram') : undefined}>
            <select className="field" value={program ?? ''} disabled={choices.length === 0} onChange={(e) => setProgramId(Number(e.target.value))}>
              {choices.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.title[lang]}
                </option>
              ))}
            </select>
          </Field>
        )}
        <Field label={t('mod.lines.textEn')}>
          <textarea className="field min-h-[72px]" maxLength={1100} value={texts.en} onChange={(e) => setTexts((x) => ({ ...x, en: e.target.value }))} />
        </Field>
        <Field label={t('mod.lines.textDe')}>
          <textarea className="field min-h-[72px]" maxLength={1100} value={texts.de} onChange={(e) => setTexts((x) => ({ ...x, de: e.target.value }))} />
        </Field>
        <Field label={t('mod.lines.time')}>
          <select className="field" value={time} onChange={(e) => setTime(e.target.value as LineTime)}>
            {LINE_TIMES.map((x) => (
              <option key={x} value={x}>
                {t(`mod.lines.times.${x}`)}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('mod.lines.mood')}>
          <select className="field" value={mood} onChange={(e) => setMood(e.target.value)}>
            <option value="">{t('mod.lines.moodNone')}</option>
            {LINE_MOODS.map((x) => (
              <option key={x} value={x}>
                {t(`mod.lines.moods.${x}`)}
              </option>
            ))}
          </select>
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn-primary" disabled={busy || (byProgram && program === null) || (texts.en.trim() === '' && texts.de.trim() === '')}>
          {busy ? t('mod.common.saving') : t('mod.lines.add.submit')}
        </button>
        <button type="button" className="btn-ghost" onClick={onCancel}>
          {t('mod.common.cancel')}
        </button>
      </div>
    </form>
  );
}
