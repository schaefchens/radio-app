import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { api } from '@/lib/api';
import { clockDuration } from '@/lib/format';
import { useApi } from './useApi';
import { useOverview } from './overview';
import { modError, VOICES, type LibraryItem, type VideoLookup } from './modApi';
import { Check, ConfirmButton, Field, Loading, Notice, Pill, Section, TagsInput } from './ui';
import { MusicIcon } from '@/components/common/icons';

type Kind = '' | 'song' | 'jingle' | 'contrib';

export function LibraryPanel() {
  const { t } = useTranslation();
  const youtube = useOverview((s) => s.data?.youtube ?? false);
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [kind, setKind] = useState<Kind>('');
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const path = `/mod/library?limit=200&q=${encodeURIComponent(query)}&kind=${kind}`;
  const { data, error, reload } = useApi<{ items: LibraryItem[] }>(path);

  const done = (text: string, tone: 'ok' | 'error' = 'ok'): void => {
    setNotice({ tone, text });
    reload();
    void useOverview.getState().load();
  };

  return (
    <div className="flex flex-col gap-4">
      {youtube ? <AddSong onAdded={() => done(t('mod.library.addedOk'))} /> : <Notice tone="error">{t('mod.library.noYoutube')}</Notice>}

      <Section
        title={t('mod.nav.library')}
        actions={
          <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              setQuery(q.trim());
            }}
          >
            <input className="field w-56 py-1.5" placeholder={t('mod.library.search')} value={q} onChange={(e) => setQ(e.target.value)} />
            <select className="field w-auto py-1.5" value={kind} onChange={(e) => setKind(e.target.value as Kind)}>
              <option value="">{t('mod.library.kind.all')}</option>
              <option value="song">{t('mod.library.kind.song')}</option>
              <option value="jingle">{t('mod.library.kind.jingle')}</option>
              <option value="contrib">{t('mod.library.kind.contrib')}</option>
            </select>
            <button type="submit" className="btn-ghost px-3 py-1.5">
              {t('mod.common.search')}
            </button>
          </form>
        }
      >
        {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
        {error && <Notice tone="error">{error}</Notice>}
        {!data ? (
          <Loading />
        ) : data.items.length === 0 ? (
          <p className="text-sm text-ink-muted">{t('mod.library.none')}</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {data.items.map((item) => (
              <LibraryRow key={`${item.id}:${item.updated}`} item={item} onChanged={done} />
            ))}
          </ul>
        )}
      </Section>

      <JinglePanel onAdded={() => done(t('mod.library.addedOk'))} />
    </div>
  );
}

function AddSong({ onAdded }: { onAdded: () => void }) {
  const { t } = useTranslation();
  const [url, setUrl] = useState('');
  const [video, setVideo] = useState<VideoLookup | null>(null);
  const [title, setTitle] = useState('');
  const [artist, setArtist] = useState('');
  const [attrs, setAttrs] = useState<{ themes: string[]; moods: string[]; languages: string[]; program_ids: number[] }>({ themes: [], moods: [], languages: [], program_ids: [] });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const lookup = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    setVideo(null);
    try {
      const r = await api<{ video: VideoLookup }>('/mod/library/lookup', { body: { url } });
      setVideo(r.video);
      setTitle(r.video.title);
      setArtist(r.video.artist);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const add = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await api('/mod/library', { body: { url, title, artist, ...attrs } });
      setUrl('');
      setVideo(null);
      setAttrs({ themes: [], moods: [], languages: [], program_ids: [] });
      onAdded();
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const usable = video !== null && video.existing === null && video.embeddable && video.public && !video.live && !video.age_restricted;
  return (
    <Section title={t('mod.library.addSong')}>
      <form
        className="flex flex-wrap gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          void lookup();
        }}
      >
        <input className="field min-w-0 flex-1" inputMode="url" placeholder="https://youtu.be/…" value={url} onChange={(e) => setUrl(e.target.value)} aria-label={t('mod.library.url')} />
        <button type="submit" className="btn-ghost" disabled={busy || url.trim() === ''}>
          {t('mod.library.lookup')}
        </button>
      </form>
      {error && <Notice tone="error">{error}</Notice>}
      {video && (
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="font-semibold">{video.channel}</span>
            <Pill>
              {t('mod.library.duration')} {clockDuration(video.duration_ms)}
            </Pill>
            {video.existing !== null && <Pill tone="warn">{t('mod.library.existing')}</Pill>}
            {!video.embeddable && <Pill tone="bad">{t('mod.library.notEmbeddable')}</Pill>}
            {!video.playable && <Pill tone="warn">{t('mod.library.notPlayable')}</Pill>}
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('mod.library.titleField')}>
              <input className="field" value={title} onChange={(e) => setTitle(e.target.value)} />
            </Field>
            <Field label={t('mod.library.artist')}>
              <input className="field" value={artist} onChange={(e) => setArtist(e.target.value)} />
            </Field>
          </div>
          <AttrsEditor value={attrs} onChange={setAttrs} />
          <button type="button" className="btn-primary self-start" disabled={!usable || busy} onClick={() => void add()}>
            {t('mod.library.add')}
          </button>
        </div>
      )}
    </Section>
  );
}

interface Attrs {
  themes: string[];
  moods: string[];
  languages: string[];
  program_ids: number[];
}

function AttrsEditor({ value, onChange }: { value: Attrs; onChange: (v: Attrs) => void }) {
  const { t, i18n } = useTranslation();
  const channels = useOverview((s) => s.data?.channels ?? []);
  const toggle = <T,>(list: T[], v: T): T[] => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={t('mod.library.themes')} hint={t('mod.library.tagsHint')}>
        <TagsInput value={value.themes} onChange={(themes) => onChange({ ...value, themes })} />
      </Field>
      <Field label={t('mod.library.moods')}>
        <TagsInput value={value.moods} onChange={(moods) => onChange({ ...value, moods })} />
      </Field>
      <div>
        <p className="label">{t('mod.library.languages')}</p>
        <div className="flex gap-4">
          {['en', 'de'].map((l) => (
            <Check key={l} label={l.toUpperCase()} checked={value.languages.includes(l)} onChange={() => onChange({ ...value, languages: toggle(value.languages, l) })} />
          ))}
        </div>
      </div>
      <div>
        <p className="label">{t('mod.library.programs')}</p>
        <p className="mb-1 text-xs text-ink-faint">{t('mod.library.programsAny')}</p>
        <div className="flex max-h-32 flex-col gap-1 overflow-auto">
          {channels.flatMap((c) =>
            c.programs.map((p) => (
              <Check
                key={p.id}
                label={`${i18n.language === 'de' ? p.title_de : p.title_en}${channels.length > 1 ? ` (${c.slug})` : ''}`}
                checked={value.program_ids.includes(p.id)}
                onChange={() => onChange({ ...value, program_ids: toggle(value.program_ids, p.id) })}
              />
            )),
          )}
        </div>
      </div>
    </div>
  );
}

function LibraryRow({ item, onChanged }: { item: LibraryItem; onChanged: (text: string, tone?: 'ok' | 'error') => void }) {
  const { t } = useTranslation();
  const [editing, setEditing] = useState(false);
  const [title, setTitle] = useState(item.title);
  const [artist, setArtist] = useState(item.artist);
  const [attrs, setAttrs] = useState<Attrs>({ themes: item.themes, moods: item.moods, languages: item.languages, program_ids: item.program_ids });
  const [busy, setBusy] = useState(false);
  const active = Number(item.active) === 1;

  const patch = async (body: Record<string, unknown>, ok: string): Promise<void> => {
    setBusy(true);
    try {
      await api(`/mod/library/${item.id}`, { method: 'PATCH', body });
      setEditing(false);
      onChanged(ok);
    } catch (e) {
      onChanged(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const pull = async (): Promise<void> => {
    setBusy(true);
    try {
      const r = await api<{ blocked: number }>(`/mod/library/${item.id}/pull`, { body: {} });
      onChanged(t('mod.library.pulled', { n: r.blocked }));
    } catch (e) {
      onChanged(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className={clsx('card-inset flex flex-col gap-3 p-3', !active && 'opacity-60')}>
      <div className="flex items-center gap-3">
        <div className="flex h-12 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-night-deep text-ink-muted">
          {item.thumb ? <img src={item.thumb} alt="" className="h-full w-full object-cover" /> : <MusicIcon />}
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium">{item.title}</p>
          <p className="truncate text-xs text-ink-muted">
            {item.artist}
            {item.artist ? ' · ' : ''}
            {clockDuration(item.duration_ms)}
            {item.yt_id ? ` · ${item.yt_id}` : ''}
          </p>
          <div className="mt-1 flex flex-wrap gap-1">
            <Pill tone={active ? 'good' : 'default'}>{active ? t('mod.common.active') : t('mod.common.inactive')}</Pill>
            <Pill>{t(`mod.library.kind.${item.kind}`)}</Pill>
            {item.kind === 'song' && <Pill>{item.source === 'submission' ? t('mod.library.source.submission') : t('mod.library.source.curated')}</Pill>}
            <Pill>
              {t('mod.library.plays')} {item.plays}
            </Pill>
            {item.trend_score > 0 && (
              <Pill>
                {t('mod.library.trend')} {item.trend_score.toFixed(1)}
              </Pill>
            )}
            {item.themes.map((th) => (
              <Pill key={th}>{th}</Pill>
            ))}
          </div>
        </div>
      </div>
      <div className="flex flex-wrap gap-2">
        <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => setEditing((e) => !e)}>
          {t('mod.common.edit')}
        </button>
        <button type="button" className="btn-ghost px-3 py-1.5 text-xs" disabled={busy} onClick={() => void patch({ active: !active }, t('mod.common.saved'))}>
          {active ? t('mod.library.deactivate') : t('mod.library.activate')}
        </button>
        {active && item.kind !== 'contrib' && (
          <ConfirmButton className="btn-ghost px-3 py-1.5 text-xs text-heart" label={t('mod.library.pull')} question={t('mod.library.pullConfirm')} onConfirm={() => void pull()} disabled={busy} />
        )}
      </div>
      {editing && (
        <div className="flex flex-col gap-3 border-t border-night-line/20 pt-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('mod.library.titleField')}>
              <input className="field" value={title} onChange={(e) => setTitle(e.target.value)} />
            </Field>
            <Field label={t('mod.library.artist')}>
              <input className="field" value={artist} onChange={(e) => setArtist(e.target.value)} />
            </Field>
          </div>
          <AttrsEditor value={attrs} onChange={setAttrs} />
          <div className="flex gap-2">
            <button type="button" className="btn-primary" disabled={busy} onClick={() => void patch({ title, artist, ...attrs }, t('mod.common.saved'))}>
              {t('mod.common.save')}
            </button>
            <button type="button" className="btn-ghost" onClick={() => setEditing(false)}>
              {t('mod.common.cancel')}
            </button>
          </div>
        </div>
      )}
    </li>
  );
}

function JinglePanel({ onAdded }: { onAdded: () => void }) {
  const { t } = useTranslation();
  const [file, setFile] = useState<File | null>(null);
  const [title, setTitle] = useState('');
  const [text, setText] = useState('');
  const [voice, setVoice] = useState<string>('coral');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (fn: () => Promise<unknown>): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await fn();
      onAdded();
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title={t('mod.library.jingles')}>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-4 md:grid-cols-2">
        <form
          className="flex flex-col gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (!file) return;
            const form = new FormData();
            form.set('audio', file);
            form.set('title', title);
            void run(() => api('/mod/jingles', { form }));
          }}
        >
          <p className="label">{t('mod.library.uploadJingle')}</p>
          <input type="file" accept="audio/mpeg,.mp3" className="text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          <input className="field" placeholder={t('mod.library.jingleTitle')} value={title} onChange={(e) => setTitle(e.target.value)} />
          <button type="submit" className="btn-ghost self-start" disabled={!file || busy}>
            {t('mod.library.upload')}
          </button>
        </form>
        <form
          className="flex flex-col gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            void run(() => api('/mod/jingles/tts', { body: { text, voice } }));
          }}
        >
          <p className="label">{t('mod.library.tts')}</p>
          <input className="field" maxLength={200} placeholder={t('mod.library.ttsText')} value={text} onChange={(e) => setText(e.target.value)} />
          <select className="field" value={voice} onChange={(e) => setVoice(e.target.value)} aria-label={t('mod.library.voice')}>
            {VOICES.map((v) => (
              <option key={v} value={v}>
                {v}
              </option>
            ))}
          </select>
          <button type="submit" className="btn-ghost self-start" disabled={text.trim() === '' || busy}>
            {t('mod.library.generate')}
          </button>
        </form>
      </div>
    </Section>
  );
}
