import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { parseDayFile } from '@arche/shared';
import { api } from '@/lib/api';
import { DayBlocks } from '@/components/home/TodayProgram';
import { useApi } from './useApi';
import { useModChannelId, useOverview } from './overview';
import { modError, type DayPlan, type DayPlanBlock, type ModProgram, type PlansData, type SpecialDay } from './modApi';
import { findOverlap, hmToMin, minToHm } from './planMath';
import { ChannelSelect } from './ChannelSelect';
import { ConfirmButton, Field, Loading, Notice, Section } from './ui';

export function PlansPanel() {
  const { t } = useTranslation();
  const channelId = useModChannelId();
  const { data, error, reload } = useApi<PlansData>(channelId ? `/mod/channels/${channelId}/plans` : null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const [editing, setEditing] = useState<number | 'new' | null>(null);

  const done = (text: string, tone: 'ok' | 'error' = 'ok'): void => {
    setNotice({ tone, text });
    if (tone === 'ok') {
      setEditing(null);
      reload();
      void useOverview.getState().load();
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <ChannelSelect />
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {error && <Notice tone="error">{error}</Notice>}
      {!data || !channelId ? (
        <Loading />
      ) : (
        <>
          <Section
            title={t('mod.plans.dayPlans')}
            actions={
              <button type="button" className="btn-primary px-3 py-1.5 text-xs" onClick={() => setEditing('new')}>
                {t('mod.plans.newDayPlan')}
              </button>
            }
          >
            <p className="text-xs text-ink-faint">{t('mod.plans.gapsHint')}</p>
            {editing === 'new' && <DayPlanEditor channelId={channelId} plan={null} programs={data.programs} onDone={done} onCancel={() => setEditing(null)} />}
            {data.dayPlans.length === 0 && editing !== 'new' && <p className="text-sm text-ink-muted">{t('mod.plans.noPlans')}</p>}
            <ul className="flex flex-col gap-3">
              {data.dayPlans.map((plan) =>
                editing === plan.id ? (
                  <li key={plan.id}>
                    <DayPlanEditor channelId={channelId} plan={plan} programs={data.programs} onDone={done} onCancel={() => setEditing(null)} />
                  </li>
                ) : (
                  <li key={plan.id} className="card-inset flex flex-col gap-2 p-3">
                    <div className="flex items-center justify-between gap-2">
                      <p className="font-semibold">{plan.name}</p>
                      <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => setEditing(plan.id)}>
                        {t('mod.common.edit')}
                      </button>
                    </div>
                    <PlanBar plan={plan} programs={data.programs} />
                  </li>
                ),
              )}
            </ul>
          </Section>
          <WeekEditor key={`week-${channelId}-${JSON.stringify(data.week)}`} channelId={channelId} data={data} onDone={done} />
          <SpecialDays channelId={channelId} data={data} onDone={done} />
          <Preview channelId={channelId} />
        </>
      )}
    </div>
  );
}

/** A day plan as one horizontal bar of its blocks. */
function PlanBar({ plan, programs }: { plan: DayPlan; programs: ModProgram[] }) {
  const { i18n } = useTranslation();
  const byId = new Map(programs.map((p) => [p.id, p]));
  return (
    <div className="relative h-8 overflow-hidden rounded-lg bg-night-deep/60">
      {plan.blocks.map((b) => {
        const p = byId.get(b.program_id);
        return (
          <div
            key={b.start_min}
            className="absolute inset-y-0 overflow-hidden border-r border-night/70 px-1 text-[0.65rem] leading-8 text-ink"
            style={{ left: `${(b.start_min / 1440) * 100}%`, width: `${((b.end_min - b.start_min) / 1440) * 100}%`, background: `${p?.color ?? '#2f7bff'}66` }}
            title={`${minToHm(b.start_min)}–${minToHm(b.end_min)} ${p ? (i18n.language === 'de' ? p.title_de : p.title_en) : ''}`}
          >
            {minToHm(b.start_min)} {p ? (i18n.language === 'de' ? p.title_de : p.title_en) : ''}
          </div>
        );
      })}
    </div>
  );
}

interface Row {
  start: string;
  end: string;
  program_id: number;
}

function DayPlanEditor({
  channelId,
  plan,
  programs,
  onDone,
  onCancel,
}: {
  channelId: number;
  plan: DayPlan | null;
  programs: ModProgram[];
  onDone: (text: string, tone?: 'ok' | 'error') => void;
  onCancel: () => void;
}) {
  const { t, i18n } = useTranslation();
  const [name, setName] = useState(plan?.name ?? '');
  const [rows, setRows] = useState<Row[]>(() =>
    (plan?.blocks ?? []).map((b) => ({ start: minToHm(b.start_min), end: minToHm(b.end_min), program_id: b.program_id })),
  );
  const [busy, setBusy] = useState(false);

  const blocks: (DayPlanBlock | null)[] = rows.map((r) => {
    const s = hmToMin(r.start);
    const e = hmToMin(r.end);
    return s === null || e === null || e <= s || s >= 1440 ? null : { start_min: s, end_min: e, program_id: r.program_id };
  });
  const invalid = blocks.some((b) => b === null);
  const overlap = !invalid && findOverlap(blocks as DayPlanBlock[]) !== -1;

  const save = async (): Promise<void> => {
    setBusy(true);
    try {
      const body = { name, blocks };
      if (plan) await api(`/mod/day-plans/${plan.id}`, { method: 'PUT', body });
      else await api(`/mod/channels/${channelId}/day-plans`, { body });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    if (!plan) return;
    setBusy(true);
    try {
      await api(`/mod/day-plans/${plan.id}`, { method: 'DELETE' });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const update = (i: number, patch: Partial<Row>): void => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const addRow = (): void => {
    const last = rows[rows.length - 1];
    const start = last ? last.end : '00:00';
    setRows((rs) => [...rs, { start, end: start === '24:00' ? '24:00' : minToHm(Math.min(1440, (hmToMin(start) ?? 0) + 60)), program_id: programs[0]?.id ?? 0 }]);
  };

  return (
    <div className="card-inset flex flex-col gap-3 p-3">
      <Field label={t('mod.plans.name')}>
        <input className="field" maxLength={60} value={name} onChange={(e) => setName(e.target.value)} />
      </Field>
      <ol className="flex flex-col gap-2">
        {rows.map((r, i) => (
          <li key={i} className="grid grid-cols-[5.5rem_5.5rem_1fr_auto] items-center gap-2">
            <input className="field px-2 font-mono" aria-label={t('mod.plans.start')} placeholder="07:00" value={r.start} onChange={(e) => update(i, { start: e.target.value })} />
            <input className="field px-2 font-mono" aria-label={t('mod.plans.end')} placeholder="08:00" value={r.end} onChange={(e) => update(i, { end: e.target.value })} />
            <select className="field" aria-label={t('mod.common.program')} value={r.program_id} onChange={(e) => update(i, { program_id: Number(e.target.value) })}>
              {programs.map((p) => (
                <option key={p.id} value={p.id}>
                  {i18n.language === 'de' ? p.title_de : p.title_en}
                </option>
              ))}
            </select>
            <button type="button" className="btn-ghost px-2 py-1.5 text-xs" onClick={() => setRows((rs) => rs.filter((_, j) => j !== i))} aria-label={t('mod.common.delete')}>
              ✕
            </button>
          </li>
        ))}
      </ol>
      {invalid && <Notice tone="error">HH:MM</Notice>}
      {overlap && <Notice tone="error">{t('mod.plans.overlap')}</Notice>}
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={addRow}>
          {t('mod.plans.addBlock')}
        </button>
        <button type="button" className="btn-primary" disabled={busy || invalid || overlap || name.trim() === ''} onClick={() => void save()}>
          {plan ? t('mod.common.save') : t('mod.common.create')}
        </button>
        <button type="button" className="btn-ghost" onClick={onCancel}>
          {t('mod.common.cancel')}
        </button>
        {plan && <ConfirmButton className="btn-ghost text-heart" label={t('mod.common.delete')} question={t('mod.common.confirmDelete')} onConfirm={() => void remove()} />}
      </div>
    </div>
  );
}

function WeekEditor({ channelId, data, onDone }: { channelId: number; data: PlansData; onDone: (text: string, tone?: 'ok' | 'error') => void }) {
  const { t } = useTranslation();
  const [week, setWeek] = useState<Record<string, number | null>>(() => {
    const w: Record<string, number | null> = {};
    for (let d = 1; d <= 7; d++) w[String(d)] = data.week[String(d)] ?? null;
    return w;
  });
  const [busy, setBusy] = useState(false);
  const save = async (): Promise<void> => {
    setBusy(true);
    try {
      await api(`/mod/channels/${channelId}/week`, { method: 'PUT', body: { week } });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Section title={t('mod.plans.week')}>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        {['1', '2', '3', '4', '5', '6', '7'].map((d) => (
          <Field key={d} label={t(`mod.plans.weekday.${d}`)}>
            <select className="field" value={week[d] ?? ''} onChange={(e) => setWeek((w) => ({ ...w, [d]: e.target.value === '' ? null : Number(e.target.value) }))}>
              <option value="">{t('mod.plans.unassigned')}</option>
              {data.dayPlans.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </Field>
        ))}
      </div>
      <button type="button" className="btn-primary self-start" disabled={busy} onClick={() => void save()}>
        {t('mod.plans.saveWeek')}
      </button>
    </Section>
  );
}

const PRESETS = [
  { key: 'christmasEve', kind: 'date', month: 12, day: 24 },
  { key: 'christmas', kind: 'date', month: 12, day: 25 },
  { key: 'goodFriday', kind: 'easter', offset: -2 },
  { key: 'easter', kind: 'easter', offset: 0 },
  { key: 'easterMonday', kind: 'easter', offset: 1 },
  { key: 'ascension', kind: 'easter', offset: 39 },
  { key: 'pentecost', kind: 'easter', offset: 49 },
] as const;

function SpecialDays({ channelId, data, onDone }: { channelId: number; data: PlansData; onDone: (text: string, tone?: 'ok' | 'error') => void }) {
  const { t } = useTranslation();
  const [name, setName] = useState('');
  const [kind, setKind] = useState<'date' | 'easter'>('date');
  const [month, setMonth] = useState('12');
  const [day, setDay] = useState('24');
  const [year, setYear] = useState('');
  const [offset, setOffset] = useState('0');
  const [planId, setPlanId] = useState<number | ''>('');
  const [busy, setBusy] = useState(false);
  const planName = (id: number): string => data.dayPlans.find((p) => p.id === id)?.name ?? `#${id}`;

  const add = async (): Promise<void> => {
    setBusy(true);
    try {
      const body =
        kind === 'date'
          ? { name, kind, month: Number(month), day: Number(day), year: year.trim() === '' ? null : Number(year), day_plan_id: planId }
          : { name, kind, easter_offset: Number(offset), day_plan_id: planId };
      await api(`/mod/channels/${channelId}/special-days`, { body });
      setName('');
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const remove = async (s: SpecialDay): Promise<void> => {
    try {
      await api(`/mod/channels/${channelId}/special-days/${s.id}`, { method: 'DELETE' });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    }
  };

  const describe = (s: SpecialDay): string => {
    if (s.kind === 'date') return `${String(s.day).padStart(2, '0')}.${String(s.month).padStart(2, '0')}.${s.year ?? ''}`;
    const o = Number(s.easter_offset);
    return `${t('mod.plans.preset.easter')} ${o >= 0 ? '+' : '−'}${Math.abs(o)}`;
  };

  return (
    <Section title={t('mod.plans.special')}>
      <ul className="flex flex-col gap-2">
        {data.specialDays.map((s) => (
          <li key={s.id} className="card-inset flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
            <span>
              <span className="font-semibold">{s.name}</span> <span className="text-ink-muted">· {describe(s)} · {planName(Number(s.day_plan_id))}</span>
            </span>
            <ConfirmButton className="btn-ghost px-3 py-1.5 text-xs text-heart" label={t('mod.common.delete')} question={t('mod.common.confirmDelete')} onConfirm={() => void remove(s)} />
          </li>
        ))}
      </ul>

      <div className="card-inset flex flex-col gap-3 p-3">
        <p className="label">{t('mod.plans.addSpecial')}</p>
        <div className="flex flex-wrap gap-1">
          <span className="mr-1 self-center text-xs text-ink-faint">{t('mod.plans.presets')}:</span>
          {PRESETS.map((p) => (
            <button
              key={p.key}
              type="button"
              className="rounded-full border border-night-line/30 px-2.5 py-1 text-xs text-ink-muted hover:text-ink"
              onClick={() => {
                setName(t(`mod.plans.preset.${p.key}`));
                setKind(p.kind);
                if (p.kind === 'date') {
                  setMonth(String(p.month));
                  setDay(String(p.day));
                  setYear('');
                } else {
                  setOffset(String(p.offset));
                }
              }}
            >
              {t(`mod.plans.preset.${p.key}`)}
            </button>
          ))}
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('mod.plans.name')}>
            <input className="field" maxLength={60} value={name} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Field label={t('mod.plans.byDate') + ' / ' + t('mod.plans.byEaster')}>
            <select className="field" value={kind} onChange={(e) => setKind(e.target.value === 'easter' ? 'easter' : 'date')}>
              <option value="date">{t('mod.plans.byDate')}</option>
              <option value="easter">{t('mod.plans.byEaster')}</option>
            </select>
          </Field>
          <Field label={t('mod.plans.dayPlan')}>
            <select className="field" value={planId} onChange={(e) => setPlanId(e.target.value === '' ? '' : Number(e.target.value))}>
              <option value="">{t('mod.common.choose')}</option>
              {data.dayPlans.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </Field>
          {kind === 'date' ? (
            <>
              <Field label={t('mod.plans.day')}>
                <input type="number" min={1} max={31} className="field" value={day} onChange={(e) => setDay(e.target.value)} />
              </Field>
              <Field label={t('mod.plans.month')}>
                <input type="number" min={1} max={12} className="field" value={month} onChange={(e) => setMonth(e.target.value)} />
              </Field>
              <Field label={t('mod.plans.year')}>
                <input type="number" min={2024} max={2100} className="field" value={year} onChange={(e) => setYear(e.target.value)} />
              </Field>
            </>
          ) : (
            <Field label={t('mod.plans.offset')}>
              <input type="number" min={-70} max={70} className="field" value={offset} onChange={(e) => setOffset(e.target.value)} />
            </Field>
          )}
        </div>
        <button type="button" className="btn-primary self-start" disabled={busy || name.trim() === '' || planId === ''} onClick={() => void add()}>
          {t('mod.plans.addDay')}
        </button>
      </div>
    </Section>
  );
}

function Preview({ channelId }: { channelId: number }) {
  const { t } = useTranslation();
  const [date, setDate] = useState('');
  const [shown, setShown] = useState('');
  const { data, error } = useApi<{ date: string; dayPlanId: number | null; day: unknown }>(
    shown ? `/mod/channels/${channelId}/preview?date=${encodeURIComponent(shown)}` : null,
  );
  const day = data ? parseDayFile(data.day) : null;
  return (
    <Section title={t('mod.plans.preview')}>
      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          setShown(date);
        }}
      >
        <Field label={t('mod.plans.previewDate')}>
          <input type="date" className="field" value={date} onChange={(e) => setDate(e.target.value)} />
        </Field>
        <button type="submit" className="btn-ghost" disabled={!date}>
          {t('mod.plans.preview')}
        </button>
      </form>
      {error && <Notice tone="error">{error}</Notice>}
      {shown && day && <DayBlocks day={day} compact />}
    </Section>
  );
}
