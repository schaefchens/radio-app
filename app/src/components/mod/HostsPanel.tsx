import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { Lang, VoiceProvider } from '@arche/shared';
import { api } from '@/lib/api';
import { localDate, localTime } from '@/lib/format';
import { useApi } from './useApi';
import { HOST_DEFAULTS, MODELS, VOICES, modError, type HostSettings, type ModHost, type VoiceCatalog } from './modApi';
import { HostAvatar } from './HostLineup';
import { Check, ConfirmButton, Field, Loading, Notice, Pill, Section } from './ui';

/** What the editor changes; the key is typed separately and never comes back. */
interface Draft {
  name: string;
  color: string;
  about_en: string;
  about_de: string;
  style: string;
  provider: VoiceProvider;
  model: string;
  instructions: string;
  settings: HostSettings;
  max_chars_day: number;
  active: boolean;
  voice: string;
  /** Another voice for German: one voice speaks both languages otherwise. */
  otherDe: boolean;
  voiceDe: string;
}

const NEW_DIRECTION = 'Warm and calm, like a Christian radio host.';

function draftOf(h: ModHost | null): Draft {
  const provider = h?.provider ?? 'openai';
  const voice = h?.voices.en ?? h?.voices.de ?? (provider === 'openai' ? 'coral' : '');
  const voiceDe = h?.voices.de ?? voice;
  return {
    name: h?.name ?? '',
    color: h?.color ?? '#2f7bff',
    about_en: h?.about_en ?? '',
    about_de: h?.about_de ?? '',
    style: h?.style ?? '',
    provider,
    model: h?.model ?? HOST_DEFAULTS[provider].model,
    instructions: h ? h.instructions : NEW_DIRECTION,
    settings: { ...HOST_DEFAULTS[provider].settings, ...h?.settings },
    max_chars_day: h?.max_chars_day ?? 0,
    active: h?.active ?? true,
    voice,
    otherDe: voiceDe !== voice,
    voiceDe,
  };
}

/** The draft as the API takes it (Host\Hosts::save). */
function bodyOf(d: Draft): Record<string, unknown> {
  const { voice, voiceDe, otherDe, ...rest } = d;
  return { ...rest, voices: { en: voice, de: otherDe ? voiceDe : voice } };
}

/**
 * /mod › Hosts (admins): the station's on-air hosts — the persona listeners
 * see (picture, name, color, a few words), private style notes for the
 * writer, and the voice: OpenAI or ElevenLabs with its key, model, voice,
 * direction and settings, and a daily character limit. Programs and channels
 * pick from them (HostLineup).
 */
export function HostsPanel() {
  const { t } = useTranslation();
  const { data, error, reload } = useApi<{ hosts: ModHost[] }>('/mod/hosts');
  const [editing, setEditing] = useState<ModHost | 'new' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Loading />;
  const done = (text: string, keep?: ModHost): void => {
    setEditing(keep ?? null);
    setNotice(text);
    reload();
  };

  return (
    <div className="flex flex-col gap-4">
      {notice && <Notice tone="ok">{notice}</Notice>}
      {editing !== null ? (
        <HostEditor key={editing === 'new' ? 'new' : editing.id} host={editing === 'new' ? null : editing} onDone={done} onCancel={() => setEditing(null)} />
      ) : (
        <Section
          title={t('mod.nav.hosts')}
          actions={
            <button type="button" className="btn-ghost px-3 py-1.5" onClick={() => setEditing('new')}>
              {t('mod.hosts.new')}
            </button>
          }
        >
          <p className="text-sm text-ink-muted">{t('mod.hosts.intro')}</p>
          <ul className="flex flex-col gap-2">
            {data.hosts.map((h) => (
              <li key={h.id} className="card-inset flex flex-wrap items-center gap-3 p-3">
                <HostAvatar host={h} size="h-10 w-10" />
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium" style={{ color: h.color }}>
                    {h.name}
                  </p>
                  <p className="truncate text-xs text-ink-muted">
                    {t(`mod.hosts.providers.${h.provider}`)} · {h.model} ·{' '}
                    {h.max_chars_day > 0 ? t('mod.hosts.charsOf', { used: h.today.chars, cap: h.max_chars_day }) : t('mod.hosts.chars', { used: h.today.chars })}
                  </p>
                  <div className="mt-1 flex flex-wrap gap-1">
                    <HostState host={h} />
                    {h.used_in.length === 0 && <Pill>{t('mod.hosts.unused')}</Pill>}
                  </div>
                </div>
                <button type="button" className="btn-ghost px-3 py-1.5" onClick={() => setEditing(h)}>
                  {t('mod.common.edit')}
                </button>
              </li>
            ))}
          </ul>
        </Section>
      )}
    </div>
  );
}

/** Whether the host speaks (the server's answer, so a test stack without keys says so too), and if not, the first thing to fix. */
function HostState({ host: h }: { host: ModHost }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  if (!h.active) return <Pill>{t('mod.common.inactive')}</Pill>;
  if (h.speaks) return <Pill tone="good">{t('mod.hosts.state.speaks')}</Pill>;
  if (h.resting_until > 0) return <Pill tone="warn">{t('mod.hosts.state.resting', { time: localTime(h.resting_until * 1000, lang) })}</Pill>;
  if (h.key_unreadable) return <Pill tone="bad">{t('mod.hosts.state.keyUnreadable')}</Pill>;
  if (!h.key_set && !h.station_key) return <Pill tone="bad">{t('mod.hosts.state.noKey')}</Pill>;
  if (h.provider === 'elevenlabs' && h.max_chars_day <= 0) return <Pill tone="bad">{t('mod.hosts.state.noCap')}</Pill>;
  return <Pill tone="warn">{t('mod.hosts.state.capReached')}</Pill>;
}

function HostEditor({ host, onDone, onCancel }: { host: ModHost | null; onDone: (text: string, keep?: ModHost) => void; onCancel: () => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const modelsId = useId();
  const [d, setD] = useState<Draft>(() => draftOf(host));
  const [avatar, setAvatar] = useState<string | null>(host?.avatar ?? null);
  const [key, setKey] = useState('');
  const [replaceKey, setReplaceKey] = useState(!host?.key_set);
  const [removeKey, setRemoveKey] = useState(false);
  const [catalog, setCatalog] = useState<VoiceCatalog | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = <K extends keyof Draft>(k: K, v: Draft[K]): void => setD((x) => ({ ...x, [k]: v }));
  const setting = <K extends keyof HostSettings>(k: K, v: HostSettings[K]): void => setD((x) => ({ ...x, settings: { ...x.settings, [k]: v } }));
  const eleven = d.provider === 'elevenlabs';
  // The other provider's key would only fail there: switched, it goes with the save.
  const keyGoes = host !== null && host.provider !== d.provider;

  const switchTo = (provider: VoiceProvider): void => {
    setCatalog(null);
    setD((x) => {
      const back = host?.provider === provider ? draftOf(host) : null;
      const voice = back?.voice ?? (provider === 'openai' ? 'coral' : '');
      return {
        ...x,
        provider,
        model: back?.model ?? HOST_DEFAULTS[provider].model,
        settings: back?.settings ?? HOST_DEFAULTS[provider].settings,
        voice,
        voiceDe: back?.voiceDe ?? voice,
        otherDe: back?.otherDe ?? false,
      };
    });
  };

  const keyBody = (): Record<string, unknown> => (removeKey ? { api_key: '' } : key.trim() !== '' ? { api_key: key.trim() } : {});

  const save = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const body = { ...bodyOf(d), ...keyBody() };
      const r = host ? await api<{ host: ModHost }>(`/mod/hosts/${host.id}`, { method: 'PATCH', body }) : await api<{ host: ModHost }>('/mod/hosts', { body });
      // A new host stays open: its picture and "Try voice" need it saved.
      onDone(t('mod.hosts.saved'), host ? undefined : r.host);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    if (!host) return;
    setBusy(true);
    setError(null);
    try {
      await api(`/mod/hosts/${host.id}`, { method: 'DELETE' });
      onDone(t('mod.hosts.deleted'));
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const upload = async (file: File): Promise<void> => {
    if (!host) return;
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.set('image', file);
      const r = await api<{ host: ModHost }>(`/mod/hosts/${host.id}/avatar`, { form });
      setAvatar(r.host.avatar);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const loadVoices = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      setCatalog(await api<VoiceCatalog>('/mod/hosts/catalog', { body: { provider: d.provider, host_id: host?.id, ...(key.trim() !== '' ? { api_key: key.trim() } : {}) } }));
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const models = [...new Set([...MODELS[d.provider], ...(catalog?.models.map((m) => m.id) ?? [])])];
  const voiceOptions = eleven ? (catalog?.voices ?? []) : VOICES.map((v) => ({ id: v, name: v, labels: '' }));

  return (
    <Section title={host ? t('mod.hosts.edit', { name: host.name }) : t('mod.hosts.new')}>
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto]">
          <Field label={t('mod.hosts.name')}>
            <input className="field" maxLength={40} value={d.name} onChange={(e) => set('name', e.target.value)} />
          </Field>
          <Field label={t('mod.hosts.color')}>
            <input type="color" className="h-10 w-20 rounded-lg border border-line/30 bg-soft" value={d.color} onChange={(e) => set('color', e.target.value)} />
          </Field>
        </div>
        <div>
          <p className="label">{t('mod.hosts.picture')}</p>
          <div className="flex flex-wrap items-center gap-3">
            <HostAvatar host={{ name: d.name || '?', avatar, color: d.color }} size="h-14 w-14" />
            {host ? (
              <input type="file" accept="image/*" className="text-sm" aria-label={t('mod.hosts.uploadPicture')} onChange={(e) => e.target.files?.[0] && void upload(e.target.files[0])} />
            ) : (
              <span className="text-xs text-ink-faint">{t('mod.hosts.pictureAfterSave')}</span>
            )}
          </div>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t('mod.hosts.aboutEn')} hint={`${t('mod.hosts.aboutHint')} ${d.about_en.length}/200`}>
            <textarea className="field min-h-[64px]" maxLength={200} value={d.about_en} onChange={(e) => set('about_en', e.target.value)} />
          </Field>
          <Field label={t('mod.hosts.aboutDe')} hint={`${d.about_de.length}/200`}>
            <textarea className="field min-h-[64px]" maxLength={200} value={d.about_de} onChange={(e) => set('about_de', e.target.value)} />
          </Field>
        </div>
        <Field label={t('mod.hosts.style')} hint={t('mod.hosts.styleHint')}>
          <textarea className="field min-h-[64px]" maxLength={600} value={d.style} onChange={(e) => set('style', e.target.value)} />
        </Field>

        <div className="card-inset flex flex-col gap-3 p-3">
          <p className="label">{t('mod.hosts.voice')}</p>
          <div className="flex flex-wrap gap-4" role="radiogroup" aria-label={t('mod.hosts.provider')}>
            {(['openai', 'elevenlabs'] as const).map((p) => (
              <label key={p} className="flex items-center gap-2 text-sm">
                <input type="radio" name="host-provider" className="accent-accent-fill" checked={d.provider === p} onChange={() => switchTo(p)} />
                {t(`mod.hosts.providers.${p}`)}
              </label>
            ))}
          </div>

          <div className="flex flex-col gap-1">
            <p className="label">{t('mod.hosts.key')}</p>
            {host?.key_set && !replaceKey && !keyGoes ? (
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className={removeKey ? 'text-ink-faint line-through' : 'text-ink'}>{t('mod.hosts.keySaved', { hint: host.key_hint ?? '' })}</span>
                {host.key_unreadable && <Pill tone="bad">{t('mod.hosts.state.keyUnreadable')}</Pill>}
                <button type="button" className="btn-ghost px-3 py-1 text-xs" onClick={() => setReplaceKey(true)}>
                  {t('mod.hosts.keyReplace')}
                </button>
                <button type="button" className="btn-ghost px-3 py-1 text-xs" onClick={() => setRemoveKey((v) => !v)}>
                  {removeKey ? t('mod.hosts.keyKeep') : t('mod.hosts.keyRemove')}
                </button>
              </div>
            ) : (
              <input
                className="field font-mono"
                type="password"
                autoComplete="off"
                spellCheck={false}
                placeholder={eleven ? 'sk_…' : 'sk-…'}
                value={key}
                aria-label={t('mod.hosts.key')}
                onChange={(e) => setKey(e.target.value)}
              />
            )}
            <span className="text-xs text-ink-faint">
              {keyGoes ? `${t('mod.hosts.keyGoes')} ` : ''}
              {eleven ? t('mod.hosts.keyHintElevenLabs') : t('mod.hosts.keyHintOpenAi')}
            </span>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label={t('mod.hosts.model')} hint={eleven ? t('mod.hosts.modelHintElevenLabs') : t('mod.hosts.modelHintOpenAi')}>
              <input className="field font-mono" list={modelsId} value={d.model} onChange={(e) => set('model', e.target.value.trim())} />
              <datalist id={modelsId}>
                {models.map((m) => (
                  <option key={m} value={m} />
                ))}
              </datalist>
            </Field>
            <VoicePicker label={t('mod.hosts.voiceId')} value={d.voice} options={voiceOptions} eleven={eleven} onChange={(v) => set('voice', v)} />
          </div>
          {eleven && (
            <div className="flex flex-col gap-1.5">
              <button type="button" className="btn-ghost self-start px-3 py-1.5 text-sm" disabled={busy || (!key.trim() && !(host?.key_set && host.provider === 'elevenlabs'))} onClick={() => void loadVoices()}>
                {t('mod.hosts.loadVoices')}
              </button>
              {catalog?.stub && <p className="text-xs text-ink-faint">{t('mod.hosts.catalogStub')}</p>}
              {catalog?.account && (
                <p className="text-xs text-ink-muted">
                  {t('mod.hosts.account', {
                    left: Math.max(0, catalog.account.limit - catalog.account.used).toLocaleString(lang),
                    limit: catalog.account.limit.toLocaleString(lang),
                    date: catalog.account.resets > 0 ? localDate(catalog.account.resets * 1000, lang, { day: 'numeric', month: 'long' }) : '—',
                  })}
                </p>
              )}
              {catalog?.errors.map((m) => (
                <p key={m} className="text-xs text-heart">
                  {m}
                </p>
              ))}
            </div>
          )}
          <Check label={t('mod.hosts.otherDe')} checked={d.otherDe} onChange={(v) => set('otherDe', v)} />
          {d.otherDe && <VoicePicker label={t('mod.hosts.voiceDe')} value={d.voiceDe} options={voiceOptions} eleven={eleven} onChange={(v) => set('voiceDe', v)} />}

          {!eleven && (
            <Field label={t('mod.hosts.direction')} hint={d.model.startsWith('tts-1') ? t('mod.hosts.directionIgnored') : t('mod.hosts.directionHint')}>
              <textarea className="field min-h-[64px]" maxLength={500} value={d.instructions} onChange={(e) => set('instructions', e.target.value)} />
            </Field>
          )}
          {eleven ? (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Slider label={t('mod.hosts.settings.stability')} hint={t('mod.hosts.settings.stabilityHint')} min={0} max={1} step={0.05} value={d.settings.stability ?? 0.5} onChange={(v) => setting('stability', v)} />
              <Slider label={t('mod.hosts.settings.similarity')} min={0} max={1} step={0.05} value={d.settings.similarity ?? 0.75} onChange={(v) => setting('similarity', v)} />
              <Slider label={t('mod.hosts.settings.style')} hint={t('mod.hosts.settings.styleHint')} min={0} max={1} step={0.05} value={d.settings.style ?? 0} onChange={(v) => setting('style', v)} />
              <Slider label={t('mod.hosts.settings.speed')} min={0.7} max={1.2} step={0.05} value={d.settings.speed} onChange={(v) => setting('speed', v)} />
              <Check label={t('mod.hosts.settings.speakerBoost')} checked={d.settings.speaker_boost ?? true} onChange={(v) => setting('speaker_boost', v)} />
              <Check label={t('mod.hosts.settings.language')} checked={d.settings.language ?? true} onChange={(v) => setting('language', v)} />
            </div>
          ) : (
            <Slider label={t('mod.hosts.settings.speed')} min={0.5} max={2} step={0.05} value={d.settings.speed} onChange={(v) => setting('speed', v)} />
          )}
          <Field label={t('mod.hosts.cap')} hint={eleven ? t('mod.hosts.capHintElevenLabs') : t('mod.hosts.capHintOpenAi')}>
            <input type="number" min={0} max={1000000} className="field w-40" value={d.max_chars_day} onChange={(e) => set('max_chars_day', Math.max(0, Number(e.target.value) || 0))} />
          </Field>
          <Check label={t('mod.hosts.active')} checked={d.active} onChange={(v) => set('active', v)} />
          {host && host.resting_until > 0 && <Notice tone="error">{t('mod.hosts.restingNotice', { time: localTime(host.resting_until * 1000, lang), error: host.last_error })}</Notice>}
          {host && host.resting_until === 0 && host.last_error && <p className="text-xs text-ink-faint">{t('mod.hosts.lastError', { error: host.last_error })}</p>}
        </div>

        {host && <TryVoice host={host} draft={d} keyBody={keyBody} />}
        {host && host.used_in.length > 0 && (
          <p className="text-xs text-ink-muted">
            {t('mod.hosts.usedIn', {
              list: host.used_in.map((u) => `${u.title[lang] || u.title.en} (${t(u.role === 'main' ? 'mod.hosts.lineup.main' : 'mod.hosts.lineup.fallback')})`).join(', '),
            })}
          </p>
        )}

        {error && <Notice tone="error">{error}</Notice>}
        <div className="flex flex-wrap gap-2">
          <button type="button" className="btn-primary" disabled={busy || d.name.trim() === ''} onClick={() => void save()}>
            {t('mod.common.save')}
          </button>
          <button type="button" className="btn-ghost" onClick={onCancel}>
            {t('mod.common.cancel')}
          </button>
          {host && <ConfirmButton label={t('mod.common.delete')} question={t('mod.hosts.deleteConfirm', { name: host.name })} disabled={busy} onConfirm={() => void remove()} />}
        </div>
      </div>
    </Section>
  );
}

/** The list entry that opens the field for an id of one's own. */
const OTHER_VOICE = '__other';

/**
 * A voice: every voice there is to choose in a list — OpenAI's thirteen with
 * a few words about each, or the ElevenLabs account's once loaded — and a
 * field for anything else (a custom voice_… id, an ElevenLabs id pasted). A
 * list, not a text field with suggestions: browsers suggest only what matches
 * the text already in the field, so the other voices never showed.
 */
function VoicePicker({
  label,
  value,
  options,
  eleven,
  onChange,
}: {
  label: string;
  value: string;
  options: VoiceCatalog['voices'];
  eleven: boolean;
  onChange: (v: string) => void;
}) {
  const { t } = useTranslation();
  const [typing, setTyping] = useState(false);
  const listed = options.some((o) => o.id === value);
  const own = typing || (!listed && value !== '');
  const known = options.find((o) => o.id === value);
  const hint = eleven ? (known ? `${known.name}${known.labels ? ` · ${known.labels}` : ''}` : t('mod.hosts.voiceHintElevenLabs')) : t('mod.hosts.voiceHintOpenAi');
  return (
    <Field label={label} hint={hint}>
      {options.length > 0 && (
        <select
          className="field"
          value={own ? OTHER_VOICE : value}
          onChange={(e) => {
            const v = e.target.value;
            setTyping(v === OTHER_VOICE);
            if (v !== OTHER_VOICE) onChange(v);
          }}
        >
          {value === '' && !own && <option value="">{t('mod.common.choose')}</option>}
          {options.map((o) => (
            <option key={o.id} value={o.id}>
              {eleven ? `${o.name}${o.labels ? ` · ${o.labels}` : ''}` : `${o.id} – ${t(`mod.hosts.voiceNames.${o.id}`)}`}
            </option>
          ))}
          <option value={OTHER_VOICE}>{t(eleven ? 'mod.hosts.otherVoiceElevenLabs' : 'mod.hosts.otherVoiceOpenAi')}</option>
        </select>
      )}
      {(own || options.length === 0) && (
        <input
          className={clsx('field font-mono', options.length > 0 && 'mt-2')}
          value={value}
          spellCheck={false}
          placeholder={eleven ? 'voice id' : 'voice_…'}
          aria-label={t(eleven ? 'mod.hosts.otherVoiceElevenLabs' : 'mod.hosts.otherVoiceOpenAi')}
          onChange={(e) => onChange(e.target.value.trim())}
        />
      )}
    </Field>
  );
}

function Slider({ label, hint, min, max, step, value, onChange }: { label: string; hint?: string; min: number; max: number; step: number; value: number; onChange: (v: number) => void }) {
  return (
    <Field label={`${label}: ${value.toFixed(2)}`} hint={hint}>
      <input type="range" className="accent-accent-fill" min={min} max={max} step={step} value={value} onChange={(e) => onChange(Number(e.target.value))} />
    </Field>
  );
}

/**
 * A sample in the host's voice, with the editor's unsaved changes — how a
 * direction or a setting sounds before it is saved. It costs what a moment
 * costs (ElevenLabs: characters of the account) and counts against the cap.
 */
function TryVoice({ host, draft, keyBody }: { host: ModHost; draft: Draft; keyBody: () => Record<string, unknown> }) {
  const { t, i18n } = useTranslation();
  const ids = { text: useId(), lang: useId() };
  const [lang, setLang] = useState<Lang>(i18n.language === 'de' ? 'de' : 'en');
  const [text, setText] = useState<Record<Lang, string>>(() => ({
    en: t('mod.hosts.try.sampleEn', { name: host.name }),
    de: t('mod.hosts.try.sampleDe', { name: host.name }),
  }));
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const playing = useRef<HTMLAudioElement | null>(null);

  const play = async (): Promise<void> => {
    setBusy(true);
    setResult(null);
    playing.current?.pause();
    try {
      const r = await api<{ audio: string; ms: number; provider: string; voice: string; model: string }>('/mod/hosts/try', {
        body: { host_id: host.id, lang, text: text[lang], draft: { ...bodyOf(draft), ...keyBody() } },
      });
      setResult({ tone: 'ok', text: t('mod.hosts.try.played', { seconds: (r.ms / 1000).toFixed(1), chars: text[lang].length, voice: r.voice, model: r.model }) });
      const audio = new Audio(`data:audio/mpeg;base64,${r.audio}`);
      playing.current = audio;
      // The clip was made (and paid for) either way; a browser that will not play it says so in its own way.
      void Promise.resolve()
        .then(() => audio.play())
        .catch(() => undefined);
    } catch (e) {
      setResult({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card-inset flex flex-col gap-2 p-3">
      <p className="label">{t('mod.hosts.try.title')}</p>
      <div className="flex flex-wrap items-end gap-2">
        <label className="flex flex-col" htmlFor={ids.lang}>
          <span className="label">{t('mod.hosts.try.lang')}</span>
          <select id={ids.lang} className="field w-auto" value={lang} onChange={(e) => setLang(e.target.value as Lang)}>
            <option value="de">{t('mod.hosts.try.de')}</option>
            <option value="en">{t('mod.hosts.try.en')}</option>
          </select>
        </label>
        <button type="button" className="btn-primary" disabled={busy || text[lang].trim() === ''} onClick={() => void play()}>
          {busy ? t('mod.hosts.try.making') : t('mod.hosts.try.play')}
        </button>
      </div>
      <label className="flex flex-col" htmlFor={ids.text}>
        <span className="sr-only">{t('mod.hosts.try.text')}</span>
        <textarea id={ids.text} className="field min-h-[56px]" maxLength={300} value={text[lang]} onChange={(e) => setText((x) => ({ ...x, [lang]: e.target.value }))} />
      </label>
      <p className="text-xs text-ink-faint">{draft.provider === 'elevenlabs' ? t('mod.hosts.try.costElevenLabs') : t('mod.hosts.try.costOpenAi')}</p>
      {result && <Notice tone={result.tone}>{result.text}</Notice>}
    </div>
  );
}
