import type { ReactionKind } from '@arche/shared';
import { api } from './api';
import { accept } from './clock';

/**
 * Presence and batched feedback. Every `interval` seconds (live.json decides;
 * 0 turns pulses off under load) one request carries the listener's presence
 * plus everything collected since the last one: reactions to songs and to
 * community voices, and player errors. A tap never causes a request of its
 * own — the scaling rule from the concept.
 */

interface Pending {
  reactions: Map<string, number>; // `${item}|${kind}` → n
  voices: Map<string, ReactionKind>;
  errors: Map<string, number>;
}

const pending: Pending = { reactions: new Map(), voices: new Map(), errors: new Map() };
let timer: ReturnType<typeof setTimeout> | null = null;
let intervalSec = 120;
let channel = '';

export function reactToItem(item: string, kind: ReactionKind): void {
  const key = `${item}|${kind}`;
  pending.reactions.set(key, Math.min(10, (pending.reactions.get(key) ?? 0) + 1));
}

export function reactToVoice(voice: string, kind: ReactionKind): void {
  pending.voices.set(voice, kind);
}

export function reportPlaybackError(item: string, code: number): void {
  pending.errors.set(item, code);
}

async function send(): Promise<void> {
  if (!channel) return;
  const reactions = [...pending.reactions].map(([k, n]) => {
    const [item, kind] = k.split('|');
    return { item, kind, n };
  });
  const voices = [...pending.voices].map(([voice, kind]) => ({ voice, kind }));
  const errors = [...pending.errors].map(([item, code]) => ({ item, code }));
  pending.reactions.clear();
  pending.voices.clear();
  pending.errors.clear();
  const t0 = Date.now();
  try {
    const r = await api<{ now: number; pulse: number }>('/pulse', { body: { channel, reactions, voices, errors } });
    accept({ t0, t1: Date.now(), server: r.now });
    if (r.pulse >= 0) intervalSec = r.pulse;
  } catch {
    // Offline or API down: the radio does not care. Feedback of this round is dropped.
  }
}

function schedule(): void {
  if (timer) clearTimeout(timer);
  if (intervalSec <= 0) {
    timer = setTimeout(schedule, 60_000);
    return;
  }
  timer = setTimeout(async () => {
    if (document.visibilityState === 'visible') await send();
    schedule();
  }, intervalSec * 1000);
}

export function startPulse(forChannel: string, seconds: number): void {
  const changed = channel !== forChannel;
  channel = forChannel;
  if (seconds >= 0) intervalSec = seconds;
  if (changed) void send();
  schedule();
}

export function setPulseInterval(seconds: number): void {
  if (seconds !== intervalSec) {
    intervalSec = seconds;
    schedule();
  }
}

/** Flush now (e.g. when the page is being hidden). */
export function flushPulse(): void {
  void send();
}
