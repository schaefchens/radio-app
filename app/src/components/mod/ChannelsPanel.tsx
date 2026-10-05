import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { useApi } from './useApi';
import { useOverview } from './overview';
import { modError, type ModChannel, type PlansData } from './modApi';
import { Check, Field, Loading, Notice, Pill, Section } from './ui';
import { HostLineup } from './HostLineup';

const FALLBACK_ZONES = ['Europe/Berlin', 'Europe/London', 'Europe/Vienna', 'Europe/Zurich', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'America/Sao_Paulo', 'Africa/Lagos', 'Africa/Nairobi', 'Asia/Kolkata', 'Asia/Singapore', 'Australia/Sydney', 'UTC'];
const ZONES: string[] = (() => {
  try {
    const list = Intl.supportedValuesOf('timeZone');
    return list.includes('UTC') ? list : [...list, 'UTC'];
  } catch {
    return FALLBACK_ZONES;
  }
})();

export function ChannelsPanel() {
  const { t, i18n } = useTranslation();
  const { data, error, reload } = useApi<{ channels: ModChannel[] }>('/mod/channels');
  const [open, setOpen] = useState<number | 'new' | null>(null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const done = (text: string, tone: 'ok' | 'error' = 'ok'): void => {
    setNotice({ tone, text });
    if (tone === 'ok') {
      setOpen(null);
      reload();
      void useOverview.getState().load();
    }
  };
  return (
    <div className="flex flex-col gap-4">
      <button type="button" className="btn-primary self-start" onClick={() => setOpen('new')}>
        {t('mod.channels.new')}
      </button>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {error && <Notice tone="error">{error}</Notice>}
      {open === 'new' && <NewChannel onDone={done} onCancel={() => setOpen(null)} />}
      {!data ? (
        <Loading />
      ) : (
        <ul className="flex flex-col gap-3">
          {data.channels.map((c) => (
            <li key={c.id}>
              {open === c.id ? (
                <ChannelEditor channel={c} onDone={done} onCancel={() => setOpen(null)} />
              ) : (
                <div className="card flex flex-wrap items-center gap-3 p-3">
                  <span className="h-10 w-2 rounded-full" style={{ background: c.color }} />
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold">
                      {i18n.language === 'de' ? c.name_de : c.name_en} <span className="text-xs text-ink-faint">{c.slug}</span>
                    </p>
                    <p className="text-xs text-ink-muted">{c.timezone}</p>
                    <div className="mt-1 flex gap-1">
                      {Number(c.is_main) === 1 && <Pill tone="good">{t('mod.channels.main')}</Pill>}
                      {Number(c.active) !== 1 && <Pill>{t('mod.common.inactive')}</Pill>}
                    </div>
                  </div>
                  <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => setOpen(c.id)}>
                    {t('mod.common.edit')}
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function ZoneSelect({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <select className="field" value={value} onChange={(e) => onChange(e.target.value)}>
      {ZONES.map((z) => (
        <option key={z} value={z}>
          {z}
        </option>
      ))}
    </select>
  );
}

function NewChannel({ onDone, onCancel }: { onDone: (text: string, tone?: 'ok' | 'error') => void; onCancel: () => void }) {
  const { t } = useTranslation();
  const [slug, setSlug] = useState('');
  const [nameEn, setNameEn] = useState('');
  const [nameDe, setNameDe] = useState('');
  const [timezone, setTimezone] = useState('Europe/Berlin');
  const [color, setColor] = useState('#8e4dff');
  const [busy, setBusy] = useState(false);
  const create = async (): Promise<void> => {
    setBusy(true);
    try {
      await api('/mod/channels', { body: { slug, name_en: nameEn, name_de: nameDe, timezone, color } });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Section title={t('mod.channels.new')}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('mod.channels.slug')}>
          <input className="field font-mono" maxLength={24} value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase())} />
        </Field>
        <Field label={t('mod.channels.timezone')}>
          <ZoneSelect value={timezone} onChange={setTimezone} />
        </Field>
        <Field label={t('mod.channels.nameEn')}>
          <input className="field" value={nameEn} onChange={(e) => setNameEn(e.target.value)} />
        </Field>
        <Field label={t('mod.channels.nameDe')}>
          <input className="field" value={nameDe} onChange={(e) => setNameDe(e.target.value)} />
        </Field>
        <Field label={t('mod.channels.color')}>
          <input type="color" className="h-10 w-20 rounded-lg border border-line/30 bg-soft" value={color} onChange={(e) => setColor(e.target.value)} />
        </Field>
      </div>
      <div className="flex gap-2">
        <button type="button" className="btn-primary" disabled={busy || !slug || !nameEn || !nameDe} onClick={() => void create()}>
          {t('mod.common.create')}
        </button>
        <button type="button" className="btn-ghost" onClick={onCancel}>
          {t('mod.common.cancel')}
        </button>
      </div>
    </Section>
  );
}

function ChannelEditor({ channel, onDone, onCancel }: { channel: ModChannel; onDone: (text: string, tone?: 'ok' | 'error') => void; onCancel: () => void }) {
  const { t, i18n } = useTranslation();
  const plans = useApi<PlansData>(`/mod/channels/${channel.id}/plans`);
  const [c, setC] = useState<ModChannel>(channel);
  const [busy, setBusy] = useState(false);
  const set = <K extends keyof ModChannel>(k: K, v: ModChannel[K]): void => setC((x) => ({ ...x, [k]: v }));

  const save = async (): Promise<void> => {
    setBusy(true);
    try {
      await api(`/mod/channels/${channel.id}`, {
        method: 'PATCH',
        body: {
          name_en: c.name_en,
          name_de: c.name_de,
          timezone: c.timezone,
          color: c.color,
          is_main: Number(c.is_main) === 1,
          active: Number(c.active) === 1,
          default_day_plan_id: c.default_day_plan_id,
          fallback_program_id: c.fallback_program_id,
          // Only when the server sent a lineup: an older one answers without, and [] would empty it.
          ...(c.hosts ? { hosts: c.hosts } : {}),
        },
      });
      onDone(t('mod.common.saved'));
    } catch (e) {
      onDone(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title={channel.slug}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('mod.channels.nameEn')}>
          <input className="field" value={c.name_en} onChange={(e) => set('name_en', e.target.value)} />
        </Field>
        <Field label={t('mod.channels.nameDe')}>
          <input className="field" value={c.name_de} onChange={(e) => set('name_de', e.target.value)} />
        </Field>
        <Field label={t('mod.channels.timezone')}>
          <ZoneSelect value={c.timezone} onChange={(v) => set('timezone', v)} />
        </Field>
        <Field label={t('mod.channels.color')}>
          <input type="color" className="h-10 w-20 rounded-lg border border-line/30 bg-soft" value={c.color} onChange={(e) => set('color', e.target.value)} />
        </Field>
        <div className="flex flex-col gap-3">
          <Field label={t('mod.channels.defaultPlan')}>
            <select className="field" value={c.default_day_plan_id ?? ''} onChange={(e) => set('default_day_plan_id', e.target.value === '' ? null : Number(e.target.value))}>
              <option value="">{t('mod.common.none')}</option>
              {(plans.data?.dayPlans ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label={t('mod.channels.fallbackProgram')}>
            <select className="field" value={c.fallback_program_id ?? ''} onChange={(e) => set('fallback_program_id', e.target.value === '' ? null : Number(e.target.value))}>
              <option value="">{t('mod.common.none')}</option>
              {/* A prayer hour's running order needs an end: never the fallback. */}
              {(plans.data?.programs ?? [])
                .filter((p) => p.settings.format !== 'prayer' || p.id === c.fallback_program_id)
                .map((p) => (
                  <option key={p.id} value={p.id}>
                    {i18n.language === 'de' ? p.title_de : p.title_en}
                  </option>
                ))}
            </select>
          </Field>
        </div>
      </div>
      {c.hosts && (
        <div className="card-inset flex flex-col gap-2 p-3">
          <p className="label">{t('mod.hosts.lineup.channelTitle')}</p>
          <HostLineup value={c.hosts} onChange={(v) => set('hosts', v)} emptyHint={t(Number(c.is_main) === 1 ? 'mod.hosts.lineup.emptyMain' : 'mod.hosts.lineup.emptyChannel')} />
        </div>
      )}
      {plans.data === null && <Loading />}
      <div className="flex flex-wrap gap-4">
        <Check label={t('mod.channels.main')} checked={Number(c.is_main) === 1} onChange={(v) => set('is_main', v ? 1 : 0)} />
        <Check label={t('mod.channels.active')} checked={Number(c.active) === 1} onChange={(v) => set('active', v ? 1 : 0)} />
      </div>
      <div className="flex gap-2">
        <button type="button" className="btn-primary" disabled={busy} onClick={() => void save()}>
          {t('mod.common.save')}
        </button>
        <button type="button" className="btn-ghost" onClick={onCancel}>
          {t('mod.common.cancel')}
        </button>
      </div>
    </Section>
  );
}
