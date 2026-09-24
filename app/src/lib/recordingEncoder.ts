/**
 * A browser recording (webm/opus or mp4/aac, whatever MediaRecorder gives)
 * → a normalised mono MP3: decode, mix down, resample to 24 kHz, bring the
 * level up to a sensible loudness (quiet phone recordings are the norm), then
 * encode in a worker.
 */
const TARGET_RATE = 24_000;
const KBPS = 64;

export async function toMp3(recording: Blob): Promise<{ mp3: Blob; ms: number }> {
  const arr = await recording.arrayBuffer();
  const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
  const ctx = new Ctx();
  let decoded: AudioBuffer;
  try {
    decoded = await ctx.decodeAudioData(arr);
  } finally {
    void ctx.close();
  }
  const length = Math.ceil(decoded.duration * TARGET_RATE);
  const offline = new OfflineAudioContext(1, length, TARGET_RATE);
  const src = offline.createBufferSource();
  src.buffer = decoded;
  src.connect(offline.destination);
  src.start();
  const mono = (await offline.startRendering()).getChannelData(0);

  // Normalise: aim for ~-18 dBFS RMS, never clip, never boost more than 6x.
  let peak = 0;
  let sum = 0;
  for (const s of mono) {
    const a = Math.abs(s);
    if (a > peak) peak = a;
    sum += s * s;
  }
  const rms = Math.sqrt(sum / Math.max(1, mono.length));
  const gain = Math.min(6, rms > 0 ? 0.125 / rms : 1, peak > 0 ? 0.95 / peak : 1);
  const pcm = new Float32Array(mono.length);
  for (let i = 0; i < mono.length; i++) pcm[i] = mono[i]! * gain;

  const worker = new Worker(new URL('../workers/mp3.worker.ts', import.meta.url), { type: 'module' });
  const mp3 = await new Promise<Blob>((resolve, reject) => {
    worker.onmessage = (e: MessageEvent<Blob>) => resolve(e.data);
    worker.onerror = () => reject(new Error('mp3 encoding failed'));
    worker.postMessage({ pcm, sampleRate: TARGET_RATE, kbps: KBPS }, [pcm.buffer]);
  });
  worker.terminate();
  return { mp3, ms: Math.round(decoded.duration * 1000) };
}
