// Copied from bible-assistant-app/src/lib/micRecord.ts (iOS-safe recording).
/**
 * Pick a `MediaRecorder` MIME type the current browser actually supports.
 *
 * iOS Safari (the most common offender) does NOT support `audio/webm` —
 * passing it to the constructor throws `NotSupportedError`. Safari does
 * support `audio/mp4` (AAC). Chrome/Firefox/Edge support `audio/webm`.
 *
 * Returns `undefined` if no candidate is supported; pass `undefined` to the
 * MediaRecorder constructor to let the browser pick its default.
 */
export function pickMicMime(): string | undefined {
  if (typeof MediaRecorder === 'undefined') return undefined;
  const candidates = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/mp4;codecs=mp4a.40.2',
    'audio/mp4',
    'audio/aac',
    'audio/ogg;codecs=opus',
  ];
  for (const c of candidates) {
    try {
      if (MediaRecorder.isTypeSupported(c)) return c;
    } catch {
      /* ignore — some engines throw on unknown types */
    }
  }
  return undefined;
}

/** Filename hint based on the actual recorder MIME, so the server sees the
 * right extension and the OpenAI Whisper request can decode it. */
export function micFileNameFor(mime: string | undefined): string {
  if (!mime) return 'speech.bin';
  const m = mime.toLowerCase();
  if (m.includes('mp4') || m.includes('aac')) return 'speech.m4a';
  if (m.includes('webm')) return 'speech.webm';
  if (m.includes('ogg')) return 'speech.ogg';
  if (m.includes('wav')) return 'speech.wav';
  return 'speech.bin';
}

/**
 * `getUserMedia` constraints tuned to leave the iOS audio session alone.
 *
 * Background: on iOS Safari, asking for `{audio: true}` with default
 * processing flags switches the system AVAudioSession into the "voice"
 * category — volume buttons start controlling the ringer, and any
 * subsequent Web Audio playback comes out quieter (routed through the
 * voice path). Turning off echoCancellation / noiseSuppression / AGC keeps
 * the session in plain media mode on iOS while still leaving the audio
 * usable for Whisper.
 */
export function micConstraints(): MediaStreamConstraints {
  return {
    audio: {
      echoCancellation: false,
      noiseSuppression: false,
      autoGainControl: false,
    },
  };
}

/** Friendly error string for a `getUserMedia` rejection — used to render UI. */
export function describeMicError(err: unknown): string {
  if (err instanceof DOMException) {
    if (err.name === 'NotAllowedError' || err.name === 'SecurityError') {
      return 'Microphone permission denied. Enable it in your browser settings.';
    }
    if (err.name === 'NotFoundError') {
      return 'No microphone found.';
    }
    if (err.name === 'NotReadableError') {
      return 'Microphone is in use by another app.';
    }
    return err.message || err.name;
  }
  return err instanceof Error ? err.message : 'Microphone unavailable.';
}
