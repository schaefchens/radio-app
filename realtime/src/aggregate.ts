import type {
  ChatReport,
  HighlightCandidate,
  ItemReactionDelta,
  ReactionKind,
  VoiceReactionDelta,
} from '@arche/shared';

/**
 * Everything that leaves the node in a report, accumulated between reports.
 *
 * Reactions are counted, never forwarded one by one: a report carries one
 * delta per item regardless of how many listeners pressed a button, so the
 * cost to PHP is O(report intervals), not O(reactions). Deltas are additive —
 * when a report fails they are merged back and ride along with the next one,
 * and two nodes' reports simply sum on the PHP side.
 *
 * Every collection is capped so a long outage of the webhosting cannot grow
 * the node's memory without bound; past the cap, new keys are dropped (trends
 * are a soft signal) and the oldest candidates/reports go first.
 */

export const AGG_LIMITS = { keys: 500, candidates: 200, reports: 200 } as const;

type Counts = Partial<Record<ReactionKind, number>>;

export interface Deltas {
  reactions: ItemReactionDelta[];
  voiceReactions: VoiceReactionDelta[];
  candidates: HighlightCandidate[];
  reports: ChatReport[];
}

function addCount(map: Map<string, Counts>, key: string, kind: ReactionKind, n: number): void {
  let counts = map.get(key);
  if (counts === undefined) {
    if (map.size >= AGG_LIMITS.keys) return;
    counts = {};
    map.set(key, counts);
  }
  counts[kind] = (counts[kind] ?? 0) + n;
}

function mergeCounts(map: Map<string, Counts>, key: string, counts: Counts): void {
  for (const [kind, n] of Object.entries(counts) as [ReactionKind, number][]) {
    if (n > 0) addCount(map, key, kind, n);
  }
}

function capOldestFirst<T>(list: T[], cap: number): void {
  if (list.length > cap) list.splice(0, list.length - cap);
}

export class Aggregator {
  private items = new Map<string, Counts>();
  private voices = new Map<string, Counts>();
  private candidates: HighlightCandidate[] = [];
  private reports: ChatReport[] = [];

  addItemReaction(item: string, kind: ReactionKind, n = 1): void {
    addCount(this.items, item, kind, n);
  }

  addVoiceReaction(voice: string, kind: ReactionKind, n = 1): void {
    addCount(this.voices, voice, kind, n);
  }

  addCandidate(candidate: HighlightCandidate): void {
    this.candidates.push(candidate);
    capOldestFirst(this.candidates, AGG_LIMITS.candidates);
  }

  addReport(report: ChatReport): void {
    this.reports.push(report);
    capOldestFirst(this.reports, AGG_LIMITS.reports);
  }

  /** Hand over everything accumulated so far and start from empty. */
  take(): Deltas {
    const deltas: Deltas = {
      reactions: [...this.items].map(([item, counts]) => ({ item, counts })),
      voiceReactions: [...this.voices].map(([voice, counts]) => ({ voice, counts })),
      candidates: this.candidates,
      reports: this.reports,
    };
    this.items = new Map();
    this.voices = new Map();
    this.candidates = [];
    this.reports = [];
    return deltas;
  }

  /** Put back deltas whose report did not get through. They are older than
   *  anything accumulated since, so they go first (and are capped first). */
  restore(deltas: Deltas): void {
    for (const r of deltas.reactions) mergeCounts(this.items, r.item, r.counts);
    for (const r of deltas.voiceReactions) mergeCounts(this.voices, r.voice, r.counts);
    this.candidates = [...deltas.candidates, ...this.candidates];
    this.reports = [...deltas.reports, ...this.reports];
    capOldestFirst(this.candidates, AGG_LIMITS.candidates);
    capOldestFirst(this.reports, AGG_LIMITS.reports);
  }

  get isEmpty(): boolean {
    return this.items.size === 0 && this.voices.size === 0 && this.candidates.length === 0 && this.reports.length === 0;
  }
}
