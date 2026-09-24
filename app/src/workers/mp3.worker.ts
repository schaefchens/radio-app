/// <reference lib="webworker" />
import { Mp3Encoder } from '@breezystack/lamejs';

/**
 * PCM (mono Float32, already resampled and normalised) → MP3, off the main
 * thread. The webhosting has no ffmpeg, so listener recordings arrive as MP3
 * that every browser can play and getID3 can measure.
 */
self.onmessage = (e: MessageEvent<{ pcm: Float32Array; sampleRate: number; kbps: number }>) => {
  const { pcm, sampleRate, kbps } = e.data;
  const enc = new Mp3Encoder(1, sampleRate, kbps);
  const chunk = 1152 * 16;
  const parts: Uint8Array[] = [];
  const buf = new Int16Array(chunk);
  for (let i = 0; i < pcm.length; i += chunk) {
    const n = Math.min(chunk, pcm.length - i);
    for (let j = 0; j < n; j++) {
      const s = Math.max(-1, Math.min(1, pcm[i + j]!));
      buf[j] = s < 0 ? s * 0x8000 : s * 0x7fff;
    }
    const out = enc.encodeBuffer(n === chunk ? buf : buf.subarray(0, n));
    if (out.length) parts.push(new Uint8Array(out));
  }
  const end = enc.flush();
  if (end.length) parts.push(new Uint8Array(end));
  (self as unknown as Worker).postMessage(new Blob(parts as BlobPart[], { type: 'audio/mpeg' }));
};
