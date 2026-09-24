import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { SubmissionState } from '@arche/shared';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { api } from '@/lib/api';
import { describeMicError, micConstraints, pickMicMime } from '@/lib/micRecord';
import { toMp3 } from '@/lib/recordingEncoder';
import { setRadioMuted } from '@/lib/radio';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { useSubmit } from './useSubmit';
import { Done, NamePlace, PrivacyNote } from './SongRequestSheet';
import { MicIcon } from '@/components/common/icons';

type Kind = 'story' | 'testimony' | 'greeting' | 'prayer';
const LIMIT_S: Record<Kind, number> = { story: 90, testimony: 90, greeting: 60, prayer: 90 };
const wallClock = (): number => Date.now();

/**
 * Record a story, testimony, greeting or prayer in the browser. The radio is
 * muted while recording (the processing that would cancel echo is off to keep
 * the iOS audio session alone), the listener can listen back, and it goes up
 * as a normalised MP3.
 */
export function RecordSheet({ open, onClose, initialKind = 'story' }: { open: boolean; onClose: () => void; initialKind?: Kind }) {
  const { t } = useTranslation();
  const identity = useSession((s) => s.identity);
  const lang = useSettings((s) => s.lang);
  const engine = useRadio((s) => s.engine);
  const [kind, setKind] = useState<Kind>(initialKind);
  const [name, setName] = useState(identity?.name ?? '');
  const [place, setPlace] = useState('');
  const [consentAir, setConsentAir] = useState(false);
  const [consentReplay, setConsentReplay] = useState(false);
  const [recording, setRecording] = useState(false);
  const [elapsed, setElapsed] = useState(0);
  const [blob, setBlob] = useState<Blob | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [micError, setMicError] = useState<string | null>(null);
  const rec = useRef<MediaRecorder | null>(null);
  const stream = useRef<MediaStream | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  // A new initial kind (the prayer sheet's "record instead") resets the choice —
  // adjusted during render rather than in an effect.
  const [shownFor, setShownFor] = useState(initialKind);
  if (shownFor !== initialKind) {
    setShownFor(initialKind);
    setKind(initialKind);
  }

  const allowed = (k: Kind): boolean => {
    const s: SubmissionState | undefined = engine.submissions[k];
    return s === 'open' || s === 'closing';
  };

  const cleanup = (): void => {
    if (timer.current) clearInterval(timer.current);
    timer.current = null;
    stream.current?.getTracks().forEach((tr) => tr.stop());
    stream.current = null;
    setRadioMuted(false);
  };
  useEffect(() => cleanup, []);

  const start = async (): Promise<void> => {
    setMicError(null);
    setBlob(null);
    if (preview) URL.revokeObjectURL(preview);
    setPreview(null);
    try {
      stream.current = await navigator.mediaDevices.getUserMedia(micConstraints());
    } catch (e) {
      const denied = e instanceof DOMException && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
      setMicError(denied ? t('record.micDenied') : describeMicError(e));
      return;
    }
    const mime = pickMicMime();
    const r = new MediaRecorder(stream.current, mime ? { mimeType: mime } : undefined);
    const chunks: BlobPart[] = [];
    r.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    r.onstop = () => {
      const b = new Blob(chunks, { type: r.mimeType || mime || 'audio/webm' });
      setBlob(b);
      setPreview(URL.createObjectURL(b));
      cleanup();
    };
    rec.current = r;
    setRadioMuted(true);
    r.start(250);
    setRecording(true);
    setElapsed(0);
    const began = wallClock();
    timer.current = setInterval(() => {
      const s = Math.floor((wallClock() - began) / 1000);
      setElapsed(s);
      if (s >= LIMIT_S[kind]) stop();
    }, 250);
  };

  const stop = (): void => {
    setRecording(false);
    if (rec.current && rec.current.state !== 'inactive') rec.current.stop();
  };

  const submit = useSubmit(async () => {
    if (!blob) throw new Error('no recording');
    const { mp3 } = await toMp3(blob);
    const form = new FormData();
    form.set('channel', engine.channel);
    form.set('type', kind);
    form.set('name', name);
    form.set('place', place);
    form.set('lang', lang);
    form.set('consent_air', consentAir ? '1' : '');
    form.set('consent_replay', consentReplay ? '1' : '');
    form.set('audio', mp3, 'recording.mp3');
    await api('/submissions/audio', { form });
  });

  const close = (): void => {
    if (recording) stop();
    cleanup();
    onClose();
    if (submit.done) {
      setBlob(null);
      setPreview(null);
      submit.reset();
    }
  };

  const kinds: Kind[] = ['story', 'testimony', 'greeting', 'prayer'];
  return (
    <BottomSheet open={open} onClose={close} title={t('submit.story.title')}>
      <BottomSheetBody>
        {submit.done ? (
          <Done onClose={close} />
        ) : (
          <div className="flex flex-col gap-4">
            <div>
              <p className="label">{t('record.kind')}</p>
              <div className="grid grid-cols-2 gap-2">
                {kinds.map((k) => (
                  <button
                    key={k}
                    type="button"
                    disabled={!allowed(k) || recording}
                    onClick={() => setKind(k)}
                    className={clsx('rounded-xl border px-3 py-2 text-sm', kind === k ? 'border-brand bg-brand/20 text-ink' : 'border-night-line/30 text-ink-muted', !allowed(k) && 'opacity-40')}
                  >
                    {t(`record.${k}`)}
                  </button>
                ))}
              </div>
            </div>

            <div className="card-inset flex flex-col items-center gap-3 p-4">
              <p className="text-xs text-ink-muted">{t('record.limit', { seconds: LIMIT_S[kind] })}</p>
              <button
                type="button"
                onClick={() => (recording ? stop() : void start())}
                disabled={!allowed(kind)}
                className={clsx('flex h-20 w-20 items-center justify-center rounded-full text-white shadow-glow transition-colors', recording ? 'bg-live' : 'bg-brand hover:bg-brand-bright')}
                aria-label={recording ? t('record.stop') : blob ? t('record.again') : t('record.start')}
              >
                <MicIcon size={32} />
              </button>
              <p className="tabular-nums text-sm">{recording ? `${elapsed}s / ${LIMIT_S[kind]}s` : blob ? t('record.again') : t('record.start')}</p>
              {recording && <p className="text-xs text-ink-faint">{t('record.radioMuted')}</p>}
              {micError && <p className="text-sm text-heart">{micError}</p>}
              {preview && !recording && <audio controls src={preview} className="w-full" aria-label={t('record.listen')} />}
            </div>

            <NamePlace name={name} place={place} setName={setName} setPlace={setPlace} />
            <label className="flex items-start gap-2 text-sm text-ink-muted">
              <input type="checkbox" className="mt-1" checked={consentAir} onChange={(e) => setConsentAir(e.target.checked)} />
              {t('submit.consentAir')}
            </label>
            <label className="flex items-start gap-2 text-sm text-ink-muted">
              <input type="checkbox" className="mt-1" checked={consentReplay} onChange={(e) => setConsentReplay(e.target.checked)} />
              {t('submit.consentReplay')}
            </label>
            {submit.error && <p className="text-sm text-heart">{submit.error}</p>}
            <PrivacyNote />
            <button type="button" className="btn-primary" disabled={!blob || !consentAir || submit.busy || recording} onClick={() => void submit.run()}>
              {submit.busy ? t('record.preparing') : t('submit.send')}
            </button>
          </div>
        )}
      </BottomSheetBody>
    </BottomSheet>
  );
}
