import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { clockDuration, localDate } from '@/lib/format';
import { describeMicError, micConstraints, pickMicMime } from '@/lib/micRecord';
import { toMp3 } from '@/lib/recordingEncoder';
import { useApi } from './useApi';
import { modError } from './modApi';
import { Field, Loading, Notice, Pill } from './ui';

interface OpeningPrayer {
  id: number;
  mode: 'text' | 'audio';
  name: string;
  text_en: string;
  text_de: string;
  audio: string | null;
  audio_ms: number;
  status: 'waiting' | 'aired';
  aired_at: number | null;
  created: number;
}

/** The server takes three minutes; the recorder stops there. */
const LIMIT_S = 180;
const wallClock = (): number => Date.now();

/**
 * A prayer hour's opening prayers a moderator prepares: each airing takes the
 * oldest one waiting, otherwise the AI host prays. Typed, it is read word for
 * word in the host voice (only the languages filled in); recorded — an MP3, or
 * in the browser — it plays as it is.
 */
export function OpeningPrayers({ programId }: { programId: number }) {
  const { t, i18n } = useTranslation();
  const uid = useId();
  const path = `/mod/programs/${programId}/opening-prayers`;
  const { data, error, reload } = useApi<{ prayers: OpeningPrayer[] }>(path);
  const [name, setName] = useState('');
  const [en, setEn] = useState('');
  const [de, setDe] = useState('');
  const [recorded, setRecorded] = useState<{ blob: Blob; url: string } | null>(null);
  const [recording, setRecording] = useState(false);
  const [elapsed, setElapsed] = useState(0);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const rec = useRef<MediaRecorder | null>(null);
  const stream = useRef<MediaStream | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const keep = (blob: Blob | null): void => {
    setRecorded((old) => {
      if (old) URL.revokeObjectURL(old.url);
      return blob ? { blob, url: URL.createObjectURL(blob) } : null;
    });
  };

  const release = (): void => {
    if (timer.current) clearInterval(timer.current);
    timer.current = null;
    stream.current?.getTracks().forEach((tr) => tr.stop());
    stream.current = null;
  };
  useEffect(() => release, []);

  const run = async (fn: () => Promise<unknown>, ok: string): Promise<void> => {
    setBusy(true);
    setNotice(null);
    try {
      await fn();
      setNotice({ tone: 'ok', text: ok });
      reload();
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  const addText = () =>
    run(async () => {
      await api(path, { body: { name, text_en: en, text_de: de } });
      setEn('');
      setDe('');
    }, t('mod.programs.opening.added'));

  const addAudio = (audio: Blob) =>
    run(async () => {
      const mp3 = audio.type === 'audio/mpeg' ? audio : (await toMp3(audio)).mp3;
      const form = new FormData();
      form.set('name', name);
      form.set('audio', mp3, 'opening.mp3');
      await api(path, { form });
      keep(null);
    }, t('mod.programs.opening.added'));

  const start = async (): Promise<void> => {
    setNotice(null);
    keep(null);
    try {
      stream.current = await navigator.mediaDevices.getUserMedia(micConstraints());
    } catch (e) {
      setNotice({ tone: 'error', text: describeMicError(e) });
      return;
    }
    const mime = pickMicMime();
    const r = new MediaRecorder(stream.current, mime ? { mimeType: mime } : undefined);
    const chunks: BlobPart[] = [];
    r.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    r.onstop = () => {
      keep(new Blob(chunks, { type: r.mimeType || mime || 'audio/webm' }));
      release();
    };
    rec.current = r;
    r.start(250);
    setRecording(true);
    setElapsed(0);
    const began = wallClock();
    timer.current = setInterval(() => {
      const s = Math.floor((wallClock() - began) / 1000);
      setElapsed(s);
      if (s >= LIMIT_S) stop();
    }, 250);
  };

  const stop = (): void => {
    setRecording(false);
    if (rec.current && rec.current.state !== 'inactive') rec.current.stop();
  };

  const prayers = data?.prayers ?? [];
  return (
    <div className="flex flex-col gap-3 border-t border-line/20 pt-3">
      <p className="label">{t('mod.programs.opening.heading')}</p>
      <p className="text-sm text-ink-muted">{t('mod.programs.opening.hint')}</p>
      {error && <Notice tone="error">{error}</Notice>}
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {!data ? (
        <Loading />
      ) : prayers.length === 0 ? (
        <p className="text-sm text-ink-muted">{t('mod.programs.opening.none')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {prayers.map((p) => (
            <li key={p.id} className="card-inset flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
              <Pill tone={p.status === 'waiting' ? 'good' : 'default'}>
                {p.status === 'waiting' ? t('mod.programs.opening.waiting') : t('mod.programs.opening.aired', { date: localDate(p.aired_at ?? p.created, i18n.language === 'de' ? 'de' : 'en') })}
              </Pill>
              <span className="font-medium">{p.name || '—'}</span>
              <span className="min-w-0 flex-1 truncate text-ink-muted">
                {p.mode === 'audio' ? `🎙 ${clockDuration(p.audio_ms)}` : [p.text_en && `EN: ${p.text_en}`, p.text_de && `DE: ${p.text_de}`].filter(Boolean).join(' · ')}
              </span>
              {p.status === 'waiting' && (
                <button
                  type="button"
                  className="btn-ghost px-3 py-1 text-xs text-heart"
                  disabled={busy}
                  onClick={() => void run(() => api(`/mod/opening-prayers/${p.id}`, { method: 'DELETE' }), t('mod.programs.opening.deleted'))}
                >
                  {t('mod.common.delete')}
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      <Field label={t('mod.programs.opening.name')}>
        <input className="field" maxLength={60} value={name} onChange={(e) => setName(e.target.value)} />
      </Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('mod.programs.opening.textEn')}>
          <textarea className="field min-h-[96px]" maxLength={1100} value={en} onChange={(e) => setEn(e.target.value)} />
        </Field>
        <Field label={t('mod.programs.opening.textDe')}>
          <textarea className="field min-h-[96px]" maxLength={1100} value={de} onChange={(e) => setDe(e.target.value)} />
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-ghost" disabled={busy || (en.trim() === '' && de.trim() === '')} onClick={() => void addText()}>
          {t('mod.programs.opening.addText')}
        </button>
        <label className="btn-ghost cursor-pointer" htmlFor={`${uid}-mp3`}>
          {t('mod.programs.opening.upload')}
        </label>
        <input
          id={`${uid}-mp3`}
          type="file"
          accept="audio/mpeg,.mp3"
          className="sr-only"
          disabled={busy}
          onChange={(e) => {
            const f = e.target.files?.[0];
            e.target.value = '';
            if (f) void addAudio(f);
          }}
        />
        {recording ? (
          <button type="button" className="btn-primary" onClick={stop}>
            {t('mod.programs.opening.stop', { s: elapsed })}
          </button>
        ) : (
          <button type="button" className="btn-ghost" disabled={busy} onClick={() => void start()}>
            {t('mod.programs.opening.record')}
          </button>
        )}
        {recorded && !recording && (
          <>
            <audio controls src={recorded.url} className="h-9" />
            <button type="button" className="btn-primary" disabled={busy} onClick={() => void addAudio(recorded.blob)}>
              {t('mod.programs.opening.addRecording')}
            </button>
          </>
        )}
      </div>
    </div>
  );
}
