import type { TFunction } from 'i18next';
import { api } from '@/lib/api';
import { modError, type WorkerTry } from './modApi';

/**
 * A clip from "Try voice" (POST /mod/hosts/try): spoken at once by OpenAI or
 * ElevenLabs, or a task one of our computers speaks, asked after until it
 * has. Shared by the editor's sample and its test moments (HostScenario).
 */
export interface TryClip {
  audio: string;
  ms: number;
  provider: string;
  voice: string;
  model: string;
  /** What the voice got: the words made speakable, and its direction ('' where it takes none). */
  spoken?: string;
  direction?: string;
}

/** A try on our own computers that came to nothing: given up by them, or no answer in time. */
export class TryFailed extends Error {
  readonly reason: 'failed' | 'gaveUp';
  readonly detail: string;

  constructor(reason: 'failed' | 'gaveUp', detail = '') {
    super(reason);
    this.reason = reason;
    this.detail = detail;
  }
}

/** How often a try on our own computers is asked after, and for how long. */
const POLL_MS = 1500;
/** A test moment's words (up to 1,100 characters) take a computer about a minute, after any live moment ahead. */
export const TRY_GIVE_UP_MS = 180_000;

const wait = (ms: number): Promise<void> => new Promise((done) => setTimeout(done, ms));

/** Speak words in a host's voice; on our computers, wait for the clip and report how it goes. */
export async function speakTry(body: Record<string, unknown>, onProgress: (p: 'waiting' | 'speaking') => void = () => undefined): Promise<TryClip> {
  const sent = await api<Partial<TryClip> & { task?: number }>('/mod/hosts/try', { body });
  const clip = { audio: sent.audio ?? '', ms: sent.ms ?? 0, provider: sent.provider ?? '', voice: sent.voice ?? '', model: sent.model ?? '', spoken: sent.spoken, direction: sent.direction };
  if (typeof sent.task !== 'number') return clip;
  onProgress('waiting');
  const until = Date.now() + TRY_GIVE_UP_MS;
  for (;;) {
    const s = await api<WorkerTry>(`/mod/hosts/try/${sent.task}`);
    if (s.state === 'done' && s.audio) return { ...clip, audio: s.audio, ms: s.ms ?? 0 };
    if (s.state === 'failed' || s.state === 'cancelled') throw new TryFailed('failed', s.error ?? s.state);
    onProgress(s.state === 'leased' ? 'speaking' : 'waiting');
    if (Date.now() >= until) throw new TryFailed('gaveUp');
    await wait(POLL_MS);
  }
}

/** Play a clip, stopping the one before. The clip was made (and paid for) either way; a browser that will not play it says so in its own way. */
export function playClip(audio: string, before: HTMLAudioElement | null): HTMLAudioElement {
  before?.pause();
  const element = new Audio(`data:audio/mpeg;base64,${audio}`);
  void Promise.resolve()
    .then(() => element.play())
    .catch(() => undefined);
  return element;
}

/** What went wrong with a try, as the editor says it: our computers' own reasons, else the API's. */
export function tryError(e: unknown, t: TFunction): string {
  if (e instanceof TryFailed) return e.reason === 'gaveUp' ? t('mod.hosts.try.gaveUp') : t('mod.hosts.try.failed', { error: e.detail });
  return modError(e);
}
