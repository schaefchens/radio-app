import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { VIDEO_SUBMISSION_TYPES, isVideoFormat, isVideoSubmissionType, type SubmissionType } from '@arche/shared';
import { api } from '@/lib/api';
import { useApi } from './useApi';
import { useModChannelId, useOverview } from './overview';
import { clockDuration } from '@/lib/format';
import { DEFAULT_LINE_KINDS, LINE_KINDS, modError, type LibraryItem, type LineKind, type LinesMode, type ModProgram, type ProgramLines, type ProgramSettings } from './modApi';
import { ChannelSelect } from './ChannelSelect';
import { OpeningPrayers } from './OpeningPrayers';
import { Check, ConfirmButton, Field, Loading, Notice, Pill, Section, TagsInput } from './ui';
import { submissionLabel } from '@/i18n';
import { allowedForFormat } from './programFormat';
import { HostLineup } from './HostLineup';

/** Suggested videos only in a video program: the server drops their types anywhere else. */
const TYPES: readonly SubmissionType[] = ['song', 'story', 'testimony', 'greeting', 'prayer', ...VIDEO_SUBMISSION_TYPES];

const DEFAULT_SETTINGS: ProgramSettings = {
  host: { enabled: true, every_songs: 3, intro: true, outro: true },
  jingle_every_songs: 4,
  silence: { every_min: 0, dur_s: 60 },
  closing_min: 25,
  closed_min: 15,
  max_queue_min: 30,
  replay_contrib: false,
  format: 'music',
  prayer: { collect: { songs: 0, minutes: 10, bed_id: 0 }, quiet_min: 4, after_songs: 0, opendoors: true },
  preaching: { songs_between: 2 },
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
    // A server older than a setting (during a deploy) answers without it.
    settings: { ...DEFAULT_SETTINGS, ...p?.settings },
    active: p ? Number(p.active) === 1 : true,
    // Its hosts only when the server sent them: from an older one, [] would empty the lineup.
    ...(p === null ? { hosts: [] } : p.hosts ? { hosts: p.hosts } : {}),
    // The same for where the host's words come from: sent back only as it came.
    ...(p?.lines ? { lines: p.lines } : {}),
  };
}

/** The kinds of recorded lines a program of this format has moments for. */
function lineKindsFor(format: ProgramSettings['format']): readonly LineKind[] {
  return format === 'prayer' ? LINE_KINDS.filter((k) => k !== 'break') : LINE_KINDS.filter((k) => k !== 'encourage' && k !== 'present' && k !== 'prayertime');
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
  const prayer = d.settings.format === 'prayer';
  const video = isVideoFormat(d.settings.format);
  const setFormat = (format: ProgramSettings['format']): void =>
    setD((x) => ({ ...x, allowed: allowedForFormat(x.allowed, x.settings.format, format), settings: { ...x.settings, format } }));
  const setP = (patch: Partial<ProgramSettings['prayer']>): void => setS({ prayer: { ...d.settings.prayer, ...patch } });
  const setC = (patch: Partial<ProgramSettings['prayer']['collect']>): void => setP({ collect: { ...d.settings.prayer.collect, ...patch } });
  const beds = useApi<{ items: LibraryItem[] }>(prayer ? '/mod/library?kind=bed&limit=100' : null);
  const linesName = useId();
  const setLines = (lines: ProgramLines): void => set('lines', lines);
  const linesModes: LinesMode[] = d.lines?.mode === 'composed' ? ['fresh', 'library', 'composed'] : ['fresh', 'library'];
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
          <input type="color" className="h-10 w-20 rounded-lg border border-line/30 bg-soft" value={d.color} onChange={(e) => set('color', e.target.value)} />
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

      <Field label={t('mod.programs.format')}>
        <select className="field" value={d.settings.format} onChange={(e) => setFormat(e.target.value as ProgramSettings['format'])}>
          <option value="music">{t('mod.programs.formatMusic')}</option>
          <option value="preaching">{t('mod.programs.formatPreaching')}</option>
          <option value="testimony">{t('mod.programs.formatTestimony')}</option>
          <option value="mission">{t('mod.programs.formatMission')}</option>
          <option value="film">{t('mod.programs.formatFilm')}</option>
          <option value="prayer">{t('mod.programs.formatPrayer')}</option>
        </select>
      </Field>

      <div>
        <p className="label">{t('mod.programs.allowed')}</p>
        {prayer ? (
          <p className="text-sm text-ink-muted">{t('mod.programs.prayer.onlyPrayer')}</p>
        ) : (
          <div className="flex flex-wrap gap-4">
            {TYPES.filter((type) => !isVideoSubmissionType(type) || video).map((type) => (
              <Check
                key={type}
                label={submissionLabel(type)}
                checked={d.allowed.includes(type)}
                onChange={(on) => set('allowed', on ? [...d.allowed, type] : d.allowed.filter((a) => a !== type))}
              />
            ))}
          </div>
        )}
        {video && <p className="mt-1 text-xs text-ink-faint">{t('mod.programs.video.allowedHint')}</p>}
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
          {/* A prayer hour follows its running order instead of these. */}
          {!prayer && (
            <>
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
            </>
          )}
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
        {prayer && <p className="text-xs text-ink-faint">{t('mod.programs.prayer.intakeHint')}</p>}
        {d.hosts && (
          <div className="flex flex-col gap-2 border-t border-line/20 pt-3">
            <p className="label">{t('mod.hosts.lineup.programTitle')}</p>
            <HostLineup value={d.hosts} onChange={(v) => set('hosts', v)} emptyHint={t('mod.hosts.lineup.emptyProgram')} />
          </div>
        )}
        {d.lines && (
          <div className="flex flex-col gap-2 border-t border-line/20 pt-3">
            <p className="label" id={`${linesName}-label`}>
              {t('mod.programs.lines.title')}
            </p>
            <div className="flex flex-wrap gap-4" role="radiogroup" aria-labelledby={`${linesName}-label`}>
              {linesModes.map((mode) => (
                <label key={mode} className="flex items-center gap-2 text-sm">
                  <input
                    type="radio"
                    className="h-4 w-4 accent-accent-fill"
                    name={linesName}
                    checked={d.lines?.mode === mode}
                    onChange={() => d.lines && setLines({ mode, kinds: d.lines.kinds.length > 0 ? d.lines.kinds : [...DEFAULT_LINE_KINDS] })}
                  />
                  {t(`mod.programs.lines.${mode}`)}
                </label>
              ))}
            </div>
            {d.lines.mode !== 'fresh' && (
              <>
                <p className="text-xs text-ink-muted">{t('mod.programs.lines.kinds')}</p>
                <div className="flex flex-wrap gap-4">
                  {lineKindsFor(d.settings.format).map((k) => (
                    <Check
                      key={k}
                      label={t(`mod.lines.kinds.${k}`)}
                      checked={d.lines?.kinds.includes(k) ?? false}
                      onChange={(on) => d.lines && setLines({ ...d.lines, kinds: on ? [...d.lines.kinds, k] : d.lines.kinds.filter((x) => x !== k) })}
                    />
                  ))}
                </div>
                <p className="text-xs text-ink-faint">{t('mod.programs.lines.hint')}</p>
              </>
            )}
          </div>
        )}
      </div>

      {video && (
        <div className="card-inset flex flex-col gap-3 p-3">
          <p className="label">{t('mod.programs.video.heading')}</p>
          <p className="text-sm text-ink-muted">{t('mod.programs.video.order')}</p>
          <div className="grid gap-3 sm:grid-cols-3">
            {/* Every video format's: the settings group keeps the name it had when preaching was the only one. */}
            <Field label={t('mod.programs.video.songsBetween')}>
              <input type="number" min={0} max={10} className="field" value={d.settings.preaching.songs_between} onChange={(e) => setS({ preaching: { songs_between: num(e.target.value) } })} />
            </Field>
          </div>
          <p className="text-xs text-ink-faint">{t('mod.programs.video.intakeHint')}</p>
          {d.settings.format === 'film' && <p className="text-xs text-ink-faint">{t('mod.programs.video.filmHint')}</p>}
        </div>
      )}

      {prayer && (
        <div className="card-inset flex flex-col gap-3 p-3">
          <p className="label">{t('mod.programs.prayer.heading')}</p>
          <p className="text-sm text-ink-muted">{t('mod.programs.prayer.order')}</p>
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label={t('mod.programs.prayer.songs')}>
              <input type="number" min={0} max={3} className="field" value={d.settings.prayer.collect.songs} onChange={(e) => setC({ songs: num(e.target.value) })} />
            </Field>
            <Field label={t('mod.programs.prayer.music')} hint={beds.data?.items.length === 0 ? t('mod.programs.prayer.noMusic') : undefined}>
              <select className="field" value={d.settings.prayer.collect.bed_id} onChange={(e) => setC({ bed_id: num(e.target.value) })}>
                <option value={0}>{t('mod.programs.prayer.quiet')}</option>
                {(beds.data?.items ?? [])
                  .filter((b) => Number(b.active) === 1 || b.id === d.settings.prayer.collect.bed_id)
                  .map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.title} · {clockDuration(b.duration_ms)}
                    </option>
                  ))}
              </select>
            </Field>
            <Field label={t('mod.programs.prayer.minutes')}>
              <input type="number" min={3} max={20} className="field" value={d.settings.prayer.collect.minutes} onChange={(e) => setC({ minutes: num(e.target.value) })} />
            </Field>
            <Field label={t('mod.programs.prayer.quietMin')}>
              <input type="number" min={2} max={15} className="field" value={d.settings.prayer.quiet_min} onChange={(e) => setP({ quiet_min: num(e.target.value) })} />
            </Field>
            <Field label={t('mod.programs.prayer.afterSongs')}>
              <input type="number" min={0} max={5} className="field" value={d.settings.prayer.after_songs} onChange={(e) => setP({ after_songs: num(e.target.value) })} />
            </Field>
          </div>
          <Check label={t('mod.programs.prayer.opendoors')} checked={d.settings.prayer.opendoors} onChange={(v) => setP({ opendoors: v })} />
          {program ? <OpeningPrayers programId={program.id} /> : <p className="text-xs text-ink-faint">{t('mod.programs.opening.saveFirst')}</p>}
        </div>
      )}

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
