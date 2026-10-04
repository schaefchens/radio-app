import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { describeMicError, micConstraints, pickMicMime } from '@/lib/micRecord';
import { setRadioMuted } from '@/lib/radio';
import { isNative } from '@/lib/native';

const wallClock = (): number => Date.now();

export interface Recorder {
  recording: boolean;
  elapsed: number;
  blob: Blob | null;
  preview: string | null;
  micError: string | null;
  /** Ask for the microphone and record, stopping by itself after `limitS` seconds. */
  start: (limitS: number) => Promise<void>;
  stop: () => void;
  /** Let go of the microphone and unmute the radio (a sheet closing). */
  cleanup: () => void;
  /** Forget the recording (after it was sent). */
  reset: () => void;
}

/**
 * Recording in the browser, for the sheets that take a recording: the
 * microphone (with its processing off, to keep the iOS audio session alone),
 * MediaRecorder, a time limit and a preview to listen back to. The radio is
 * muted while recording: without echo cancellation it would be recorded too.
 */
export function useRecorder(): Recorder {
  const { t } = useTranslation();
  const [recording, setRecording] = useState(false);
  const [elapsed, setElapsed] = useState(0);
  const [blob, setBlob] = useState<Blob | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [micError, setMicError] = useState<string | null>(null);
  const rec = useRef<MediaRecorder | null>(null);
  const stream = useRef<MediaStream | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const cleanup = (): void => {
    if (timer.current) clearInterval(timer.current);
    timer.current = null;
    stream.current?.getTracks().forEach((tr) => tr.stop());
    stream.current = null;
    setRadioMuted(false);
  };
  // Leaving the page mid-recording lets go of the microphone too.
  useEffect(() => cleanup, []);

  const stop = (): void => {
    setRecording(false);
    if (rec.current && rec.current.state !== 'inactive') rec.current.stop();
  };

  const start = async (limitS: number): Promise<void> => {
    setMicError(null);
    setBlob(null);
    if (preview) URL.revokeObjectURL(preview);
    setPreview(null);
    try {
      stream.current = await navigator.mediaDevices.getUserMedia(micConstraints());
    } catch (e) {
      const denied = e instanceof DOMException && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
      setMicError(denied ? (isNative() ? t('record.micDeniedApp') : t('record.micDenied')) : describeMicError(e));
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
      if (s >= limitS) stop();
    }, 250);
  };

  const reset = (): void => {
    if (preview) URL.revokeObjectURL(preview);
    setBlob(null);
    setPreview(null);
  };

  return { recording, elapsed, blob, preview, micError, start, stop, cleanup, reset };
}
