import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { useApi } from './useApi';
import { useModChannelId, useOverview } from './overview';
import { modError, type ModProgram, type ProgramSettings } from './modApi';
import { ChannelSelect } from './ChannelSelect';
import { Check, ConfirmButton, Field, Loading, Notice, Pill, Section, TagsInput } from './ui';

const TYPES = ['song', 'story', 'testimony', 'greeting', 'prayer'] as const;

const DEFAULT_SETTINGS: ProgramSettings = {
  host: { enabled: true, every_songs: 3, intro: true, outro: true },
  jingle_every_songs: 4,
  silence: { every_min: 0, dur_s: 60 },
  closing_min: 25,
  closed_min: 15,
  max_queue_min: 30,
  replay_contrib: false,
};

type Draft = Omit<ModProgram, 'id' | 'channel_id' | 'active'> & { active: boolean };

function draftOf(p: ModProgram | null): Draft {
  return {
    slug: p?.slug ?? '',
    title_en: p?.title_en ?? '',
    title_de: p?.title_de ?? '',
    subtitle_en: p?.subtitle_en ?? '',
    subtitle_de: p?.subtitle_de ?? '',
    description_en: p?.description_en ?? '',
    description_de: p?.description_de ?? '',
    tagline_en: p?.tagline_en ?? '',
    tagline_de: p?.tagline_de ?? '',
    color: p?.color ?? '#2f7bff',
    image: p?.image ?? null,
    stage_mode: p?.stage_mode ?? 'ambient',
    allowed: p?.allowed ?? ['song'],
    themes: p?.themes ?? [],
    moods: p?.moods ?? [],
    settings: p?.settings ?? DEFAULT_SETTINGS,
    active: p ? Number(p.active) === 1 : true,
  };
}

export function ProgramsPanel() {
  const { t, i18n } = useTranslation();
  const channelId = useModChannelId();
  const { data, error, reload } = useApi<{ programs: ModProgram[] }>(channelId ? `/mod/channels/${channelId}/programs` : null);
  const [open, setOpen] = useState<number | 'new' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const saved = (): void => {
    setOpen(null);
    setNotice(t('mod.common.saved'));
    reload();
    void useOverview.getState().load();
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <ChannelSelect />
        <button type="button" className="btn-primary" onClick={() => setOpen('new')}>
          {t('mod.programs.new')}
        </button>
      </div>
      {notice && <Notice tone="ok">{notice}</Notice>}
      {error && <Notice tone="error">{error}</Notice>}
      {open === 'new' && channelId && <ProgramEditor channelId={channelId} program={null} onSaved={saved} onCancel={() => setOpen(null)} />}
      {!data ? (
        <Loading />
      ) : (
        <ul className="flex flex-col gap-3">
          {data.programs.map((p) => (
            <li key={p.id}>
              {open === p.id ? (
                <ProgramEditor channelId={channelId!} program={p} onSaved={saved} onCancel={() => setOpen(null)} />
              ) : (
                <div className="card flex flex-wrap items-center gap-3 p-3">
                  <span className="h-10 w-2 shrink-0 rounded-full" style={{ background: p.color }} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold">
                      {i18n.language === 'de' ? p.title_de : p.title_en} <span className="text-xs text-ink-faint">{p.slug}</span>
                    </p>
                    <p className="truncate text-xs text-ink-muted">{i18n.language === 'de' ? p.subtitle_de : p.subtitle_en}</p>
                    <div className="mt-1 flex flex-wrap gap-1">
                      {Number(p.active) !== 1 && <Pill>{t('mod.common.inactive')}</Pill>}
                      {p.allowed.map((a) => (
                        <Pill key={a}>{a}</Pill>
                      ))}
                      {p.themes.map((a) => (
                        <Pill key={a}>#{a}</Pill>
                      ))}
                    </div>
                  </div>
                  <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => setOpen(p.id)}>
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

function ProgramEditor({ channelId, program, onSaved, onCancel }: { channelId: number; program: ModProgram | null; onSaved: () => void; onCancel: () => void }) {
  const { t } = useTranslation();
  const [d, setD] = useState<Draft>(() => draftOf(program));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = <K extends keyof Draft>(k: K, v: Draft[K]): void => setD((x) => ({ ...x, [k]: v }));
  const setS = (patch: Partial<ProgramSettings>): void => setD((x) => ({ ...x, settings: { ...x.settings, ...patch } }));
  const num = (v: string): number => (Number.isFinite(Number(v)) ? Number(v) : 0);

  const save = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    const body = { ...d, image: undefined };
    try {
      if (program) await api(`/mod/programs/${program.id}`, { method: 'PATCH', body: { ...body, slug: undefined } });
      else await api(`/mod/channels/${channelId}/programs`, { body });
      onSaved();
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const upload = async (file: File): Promise<void> => {
    if (!program) return;
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.set('image', file);
      const r = await api<{ program: ModProgram }>(`/mod/programs/${program.id}/image`, { form });
      set('image', r.program.image);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    if (!program) return;
    setBusy(true);
    setError(null);
    try {
      await api(`/mod/programs/${program.id}`, { method: 'DELETE' });
      onSaved();
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const text = (k: 'title_en' | 'title_de' | 'subtitle_en' | 'subtitle_de' | 'tagline_en' | 'tagline_de', label: string) => (
    <Field label={label}>
      <input className="field" maxLength={120} value={d[k]} onChange={(e) => set(k, e.target.value)} />
    </Field>
  );

  return (
    <Section title={program ? program.slug : t('mod.programs.new')}>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-3 sm:grid-cols-2">
        {!program && (
          <Field label={t('mod.programs.slug')}>
            <input className="field font-mono" maxLength={32} value={d.slug} onChange={(e) => set('slug', e.target.value.toLowerCase())} />
          </Field>
        )}
        <Field label={t('mod.programs.color')}>
          <input type="color" className="h-10 w-20 rounded-lg border border-night-line/30 bg-night-deep" value={d.color} onChange={(e) => set('color', e.target.value)} />
        </Field>
        {text('title_en', t('mod.programs.titleEn'))}
        {text('title_de', t('mod.programs.titleDe'))}
        {text('subtitle_en', t('mod.programs.subtitleEn'))}
        {text('subtitle_de', t('mod.programs.subtitleDe'))}
        <Field label={t('mod.programs.descriptionEn')}>
          <textarea className="field min-h-[72px]" maxLength={600} value={d.description_en} onChange={(e) => set('description_en', e.target.value)} />
        </Field>
        <Field label={t('mod.programs.descriptionDe')}>
          <textarea className="field min-h-[72px]" maxLength={600} value={d.description_de} onChange={(e) => set('description_de', e.target.value)} />
        </Field>
        {text('tagline_en', t('mod.programs.taglineEn'))}
        {text('tagline_de', t('mod.programs.taglineDe'))}
        <Field label={t('mod.programs.stageMode')}>
          <select className="field" value={d.stage_mode} onChange={(e) => set('stage_mode', e.target.value as Draft['stage_mode'])}>
            <option value="ambient">{t('mod.programs.stage.ambient')}</option>
            <option value="image">{t('mod.programs.stage.image')}</option>
            <option value="flyins">{t('mod.programs.stage.flyins')}</option>
          </select>
        </Field>
        <div>
          <p className="label">{t('mod.programs.image')}</p>
          <div className="flex items-center gap-3">
            {d.image && <img src={d.image} alt="" className="h-14 w-24 rounded-lg object-cover" />}
            {program ? (
              <input type="file" accept="image/*" className="text-sm" onChange={(e) => e.target.files?.[0] && void upload(e.target.files[0])} aria-label={t('mod.programs.uploadImage')} />
            ) : (
              <span className="text-xs text-ink-faint">—</span>
            )}
          </div>
        </div>
      </div>

      <div>
        <p className="label">{t('mod.programs.allowed')}</p>
        <div className="flex flex-wrap gap-4">
          {TYPES.map((type) => (
            <Check
              key={type}
              label={type === 'song' ? t('submit.song.title') : type === 'prayer' ? t('submit.prayer.title') : t(`record.${type}`)}
              checked={d.allowed.includes(type)}
              onChange={(on) => set('allowed', on ? [...d.allowed, type] : d.allowed.filter((a) => a !== type))}
            />
          ))}
        </div>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('mod.programs.themes')} hint={t('mod.library.tagsHint')}>
          <TagsInput value={d.themes} onChange={(v) => set('themes', v)} />
        </Field>
        <Field label={t('mod.programs.moods')}>
          <TagsInput value={d.moods} onChange={(v) => set('moods', v)} />
        </Field>
      </div>

      <div className="card-inset flex flex-col gap-3 p-3">
        <p className="label">{t('mod.programs.host')}</p>
        <div className="flex flex-wrap gap-4">
          <Check label={t('mod.programs.hostEnabled')} checked={d.settings.host.enabled} onChange={(v) => setS({ host: { ...d.settings.host, enabled: v } })} />
          <Check label={t('mod.programs.intro')} checked={d.settings.host.intro} onChange={(v) => setS({ host: { ...d.settings.host, intro: v } })} />
          <Check label={t('mod.programs.outro')} checked={d.settings.host.outro} onChange={(v) => setS({ host: { ...d.settings.host, outro: v } })} />
          <Check label={t('mod.programs.replay')} checked={d.settings.replay_contrib} onChange={(v) => setS({ replay_contrib: v })} />
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('mod.programs.everySongs')}>
            <input type="number" min={1} max={12} className="field" value={d.settings.host.every_songs} onChange={(e) => setS({ host: { ...d.settings.host, every_songs: num(e.target.value) } })} />
          </Field>
          <Field label={t('mod.programs.jingleEvery')}>
            <input type="number" min={0} max={20} className="field" value={d.settings.jingle_every_songs} onChange={(e) => setS({ jingle_every_songs: num(e.target.value) })} />
          </Field>
          <Field label={t('mod.programs.silenceEvery')}>
            <input type="number" min={0} max={240} className="field" value={d.settings.silence.every_min} onChange={(e) => setS({ silence: { ...d.settings.silence, every_min: num(e.target.value) } })} />
          </Field>
          <Field label={t('mod.programs.silenceDur')}>
            <input type="number" min={10} max={300} className="field" value={d.settings.silence.dur_s} onChange={(e) => setS({ silence: { ...d.settings.silence, dur_s: num(e.target.value) } })} />
          </Field>
          <Field label={t('mod.programs.closingMin')}>
            <input type="number" min={0} max={120} className="field" value={d.settings.closing_min} onChange={(e) => setS({ closing_min: num(e.target.value) })} />
          </Field>
          <Field label={t('mod.programs.closedMin')}>
            <input type="number" min={11} max={120} className="field" value={d.settings.closed_min} onChange={(e) => setS({ closed_min: num(e.target.value) })} />
          </Field>
          <Field label={t('mod.programs.maxQueue')}>
            <input type="number" min={5} max={180} className="field" value={d.settings.max_queue_min} onChange={(e) => setS({ max_queue_min: num(e.target.value) })} />
          </Field>
        </div>
      </div>

      <Check label={t('mod.programs.active')} checked={d.active} onChange={(v) => set('active', v)} />
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-primary" disabled={busy} onClick={() => void save()}>
          {busy ? t('mod.common.saving') : program ? t('mod.common.save') : t('mod.common.create')}
        </button>
        <button type="button" className="btn-ghost" onClick={onCancel}>
          {t('mod.common.cancel')}
        </button>
        {program && <ConfirmButton className="btn-ghost text-heart" label={t('mod.common.delete')} question={t('mod.programs.deleteConfirm')} onConfirm={() => void remove()} disabled={busy} />}
      </div>
    </Section>
  );
}
