import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang, VoiceProvider } from '@arche/shared';
import { api } from '@/lib/api';
import { useApi } from './useApi';
import { modError, type LibraryItem, type ModHost, type ScenarioCatalog, type ScenarioResult, type ScenarioSong, type TestMoment } from './modApi';
import { Field, Notice } from './ui';
import { playClip, speakTry, tryError, type TryClip } from './voiceTry';

const LANGS: readonly Lang[] = ['en', 'de'];
/** What a library search may offer as the song either side (no jingles, recordings or prayer music). */
const SPOKEN_KINDS: readonly string[] = ['song', 'preaching', 'testimony', 'mission', 'film'];

interface Voiced {
  busy: boolean;
  progress: 'waiting' | 'speaking' | null;
  clip?: TryClip;
  error?: string;
}

/**
 * "Test a moment": a moment of a real program — a break, a request, the
 * prayer hour's welcome … — written by the real writer with the editor's
 * unsaved settings and the station's own songs, then spoken as it would air
 * (Host\Scenarios, then "Try voice" with the moment's delivery). Each one
 * joins a test show the next one remembers, so three breaks in a row show
 * whether the host repeats itself; the words and their delivery can be
 * changed and spoken again without asking the writer anew. Closed until
 * opened: only then are the station's programs asked for.
 */
export function HostScenario({ host, provider, draftBody }: { host: ModHost; provider: VoiceProvider; draftBody: () => Record<string, unknown> }) {
  const { t, i18n } = useTranslation();
  const lang: Lang = i18n.language === 'de' ? 'de' : 'en';
  const ids = { program: useId(), moment: useId(), effort: useId(), en: useId(), de: useId() };
  const [open, setOpen] = useState(false);
  const { data: catalog, error: catalogError } = useApi<ScenarioCatalog>(open ? '/mod/hosts/scenarios' : null);
  const [programId, setProgramId] = useState<number | null>(null);
  const [moment, setMoment] = useState<string | null>(null);
  const [effort, setEffort] = useState<string | null>(null);
  const [before, setBefore] = useState<ScenarioSong | null>(null);
  const [after, setAfter] = useState<ScenarioSong | null>(null);
  const [show, setShow] = useState<TestMoment[]>([]);
  const [result, setResult] = useState<ScenarioResult | null>(null);
  // Which moment of the test show the result is (-1 once the show started over).
  const [entry, setEntry] = useState(-1);
  const [texts, setTexts] = useState<Partial<Record<Lang, string>>>({});
  const [delivery, setDelivery] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [voiced, setVoiced] = useState<Partial<Record<Lang, Voiced>>>({});
  const playing = useRef<HTMLAudioElement | null>(null);

  const program = catalog?.programs.find((p) => p.id === programId) ?? catalog?.programs[0] ?? null;
  const chosenMoment = program && moment !== null && program.moments.includes(moment) ? moment : (program?.moments[0] ?? null);
  const chosenEffort = effort ?? catalog?.effort ?? 'low';
  const channels = new Set(catalog?.programs.map((p) => p.channel)).size;

  const startOver = (): void => {
    setShow([]);
    setEntry(-1);
  };

  /** Speak one language of the moment; what was spoken last is what the test show remembers. */
  const speak = async (l: Lang, r: ScenarioResult, words: Partial<Record<Lang, string>>, how: string, at: number): Promise<void> => {
    const text = (words[l] ?? '').trim();
    if (text === '') return;
    setVoiced((v) => ({ ...v, [l]: { busy: true, progress: null } }));
    try {
      const clip = await speakTry({ host_id: host.id, lang: l, text, delivery: how, theirs: r.theirs, draft: draftBody() }, (progress) =>
        setVoiced((v) => ({ ...v, [l]: { busy: true, progress } })),
      );
      setVoiced((v) => ({ ...v, [l]: { busy: false, progress: null, clip } }));
      playing.current = playClip(clip.audio, playing.current);
      setShow((s) => s.map((m, i) => (i === at ? { ...m, texts: { ...m.texts, [l]: text } } : m)));
    } catch (e) {
      setVoiced((v) => ({ ...v, [l]: { busy: false, progress: null, error: tryError(e, t) } }));
    }
  };

  const write = async (): Promise<void> => {
    if (!program || !chosenMoment) return;
    setBusy(true);
    setError(null);
    setVoiced({});
    playing.current?.pause();
    try {
      // A test show goes on from the song after its last moment, unless one was picked.
      const last = show[show.length - 1];
      const r = await api<ScenarioResult>('/mod/hosts/scenario', {
        body: {
          host_id: host.id,
          draft: draftBody(),
          program_id: program.id,
          moment: chosenMoment,
          effort: chosenEffort,
          previous_id: before?.id ?? last?.next_id,
          next_id: after?.id,
          earlier: show,
        },
      });
      const at = show.length;
      setResult(r);
      setTexts(r.texts);
      setDelivery(r.delivery);
      setEntry(at);
      setShow((s) => [...s, { moment: r.moment, previous_id: r.songs.previous?.id || undefined, next_id: r.songs.next?.id || undefined, texts: r.texts }]);
      // As a listener hears it: in one language, the editor's. Each costs characters of
      // the host's day; an ElevenLabs host's account is small, so nothing by itself there.
      const first = r.texts[lang] ? lang : LANGS.find((l) => r.texts[l]);
      if (first && provider !== 'elevenlabs') void speak(first, r, r.texts, r.delivery, at);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const source = (r: ScenarioResult): string =>
    r.source.startsWith('template:')
      ? t('mod.hosts.scenario.template', { why: r.source.slice('template:'.length) })
      : r.source === 'listener'
        ? t('mod.hosts.scenario.theirs')
        : t('mod.hosts.scenario.written', { seconds: r.seconds.toFixed(1) });
  const song = (s: ScenarioSong | null): string => (s ? `${s.title} – ${s.artist}` : '—');

  return (
    <div className="card-inset flex flex-col gap-3 p-3">
      <button type="button" className="flex items-center justify-between text-left" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
        <span className="label">{t('mod.hosts.scenario.title')}</span>
        <span className="text-xs text-ink-muted">{open ? '▲' : '▼'}</span>
      </button>
      {open && (
        <>
          <p className="text-sm text-ink-muted">{t('mod.hosts.scenario.intro')}</p>
          {catalogError && <Notice tone="error">{catalogError}</Notice>}
          {catalog && program && (
            <>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label className="flex min-w-0 flex-col" htmlFor={ids.program}>
                  <span className="label">{t('mod.hosts.scenario.program')}</span>
                  <select
                    id={ids.program}
                    className="field"
                    value={program.id}
                    onChange={(e) => {
                      setProgramId(Number(e.target.value));
                      // Another program is another show.
                      startOver();
                      setResult(null);
                    }}
                  >
                    {catalog.programs.map((p) => (
                      <option key={p.id} value={p.id}>
                        {(p.title[lang] || p.title.en) + (channels > 1 ? ` (${p.channel})` : '')}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="flex min-w-0 flex-col" htmlFor={ids.moment}>
                  <span className="label">{t('mod.hosts.scenario.moment')}</span>
                  <select id={ids.moment} className="field" value={chosenMoment ?? ''} onChange={(e) => setMoment(e.target.value)}>
                    {program.moments.map((m) => (
                      <option key={m} value={m}>
                        {t(`mod.hosts.scenario.moments.${m}`)}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="flex min-w-0 flex-col" htmlFor={ids.effort}>
                  <span className="label">{t('mod.hosts.scenario.effort')}</span>
                  <select id={ids.effort} className="field" value={chosenEffort} onChange={(e) => setEffort(e.target.value)}>
                    {catalog.efforts.map((e) => (
                      <option key={e} value={e}>
                        {t(`mod.hosts.scenario.efforts.${e}`)}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <SongPick label={t('mod.hosts.scenario.songBefore')} value={before} onChange={setBefore} />
                <SongPick label={t('mod.hosts.scenario.songAfter')} value={after} onChange={setAfter} />
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <button type="button" className="btn-primary" disabled={busy || !chosenMoment} onClick={() => void write()}>
                  {busy ? t('mod.hosts.scenario.writing') : t('mod.hosts.scenario.write')}
                </button>
                {show.length > 0 && (
                  <>
                    <span className="text-sm text-ink-muted">{t('mod.hosts.scenario.show', { count: show.length })}</span>
                    <button type="button" className="btn-ghost px-3 py-1.5 text-sm" onClick={startOver}>
                      {t('mod.hosts.scenario.startOver')}
                    </button>
                  </>
                )}
              </div>
              <p className="text-xs text-ink-faint">
                {provider === 'elevenlabs' ? t('mod.hosts.scenario.costElevenLabs') : t('mod.hosts.scenario.cost')}
                {host.max_chars_day > 0 && ` ${t('mod.hosts.scenario.today', { used: host.today.chars, cap: host.max_chars_day })}`}
              </p>
            </>
          )}
          {error && <Notice tone="error">{error}</Notice>}
          {result && (
            <div className="flex flex-col gap-3 border-t border-line/30 pt-3">
              <p className="text-sm text-ink-muted">
                {source(result)} · {t('mod.hosts.scenario.songs', { before: song(result.songs.previous), after: song(result.songs.next) })}
              </p>
              <Field label={t('mod.hosts.scenario.delivery')} hint={t('mod.hosts.scenario.deliveryHint')}>
                <input className="field" maxLength={200} value={delivery} onChange={(e) => setDelivery(e.target.value)} />
              </Field>
              {LANGS.filter((l) => texts[l] !== undefined).map((l) => {
                const v = voiced[l];
                return (
                  <div key={l} className="flex flex-col gap-1">
                    <label className="label" htmlFor={ids[l]}>
                      {t(`mod.hosts.try.${l}`)}
                    </label>
                    <textarea id={ids[l]} className="field min-h-[72px]" maxLength={1200} value={texts[l] ?? ''} onChange={(e) => setTexts((x) => ({ ...x, [l]: e.target.value }))} />
                    <div className="flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        className="btn-ghost px-3 py-1.5 text-sm"
                        disabled={v?.busy || (texts[l] ?? '').trim() === ''}
                        onClick={() => void speak(l, result, texts, delivery, entry)}
                      >
                        {v?.busy ? t('mod.hosts.try.making') : t('mod.hosts.scenario.play', { lang: t(`mod.hosts.try.${l}`) })}
                      </button>
                      {v?.progress && (
                        <span className="text-sm text-ink-muted" role="status">
                          {v.progress === 'speaking' ? t('mod.hosts.try.speaking') : t('mod.hosts.try.waiting')}
                        </span>
                      )}
                      {v?.clip && (
                        <span className="text-sm text-ink-muted">
                          {t('mod.hosts.scenario.played', { seconds: (v.clip.ms / 1000).toFixed(1), voice: v.clip.voice, model: v.clip.model })}
                        </span>
                      )}
                    </div>
                    {v?.error && <Notice tone="error">{v.error}</Notice>}
                    {v?.clip?.spoken !== undefined && (
                      <p className="text-xs text-ink-faint">
                        {t('mod.hosts.scenario.got', { text: v.clip.spoken })}
                        {v.clip.direction ? ` ${t('mod.hosts.scenario.direction', { direction: v.clip.direction })}` : ''}
                      </p>
                    )}
                  </div>
                );
              })}
              <details>
                <summary className="cursor-pointer text-sm text-ink-muted">{t('mod.hosts.scenario.given')}</summary>
                <pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-soft p-2 text-xs">{JSON.stringify(result.given, null, 2)}</pre>
              </details>
            </div>
          )}
        </>
      )}
    </div>
  );
}

/**
 * The song either side of a test moment: at random (each test takes others),
 * or one found in the library — a messy YouTube title, to hear how the host says it.
 */
function SongPick({ label, value, onChange }: { label: string; value: ScenarioSong | null; onChange: (s: ScenarioSong | null) => void }) {
  const { t } = useTranslation();
  const [q, setQ] = useState('');
  const query = q.trim();
  const { data } = useApi<{ items: LibraryItem[] }>(query.length >= 2 ? `/mod/library?q=${encodeURIComponent(query)}&limit=8` : null);
  const items = (data?.items ?? []).filter((i) => SPOKEN_KINDS.includes(i.kind));
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <span className="label">{label}</span>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="min-w-0 truncate">{value ? `${value.title} – ${value.artist}` : t('mod.hosts.scenario.random')}</span>
        {value && (
          <button type="button" className="btn-ghost px-2 py-0.5 text-xs" onClick={() => onChange(null)}>
            {t('mod.hosts.scenario.random')}
          </button>
        )}
      </div>
      <input className="field" placeholder={t('mod.hosts.scenario.search')} aria-label={`${label}: ${t('mod.hosts.scenario.search')}`} value={q} onChange={(e) => setQ(e.target.value)} />
      {query.length >= 2 && items.length > 0 && (
        <ul className="flex flex-col gap-1">
          {items.map((i) => (
            <li key={i.id}>
              <button
                type="button"
                className="text-left text-sm text-accent underline-offset-2 hover:underline"
                onClick={() => {
                  onChange({ id: i.id, title: i.title, artist: i.artist, kind: i.kind });
                  setQ('');
                }}
              >
                {i.title} – {i.artist}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
