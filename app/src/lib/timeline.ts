import type { ProgramRef, SlotFile, SubmissionState, SubmissionType, TimelineItem } from '@arche/shared';

/**
 * The client's merged view of the minute files it has fetched: items by id
 * (every file repeats the ten minutes it overlaps), the program refs, and the
 * per-minute submission states. Lookups are by server time.
 */
export class Timeline {
  private items = new Map<string, TimelineItem>();
  private sorted: TimelineItem[] = [];
  private programs = new Map<string, ProgramRef>();
  private slots: SlotFile[] = [];

  add(slot: SlotFile): void {
    for (const it of slot.items) this.items.set(it.id, it);
    for (const [id, p] of Object.entries(slot.programs)) this.programs.set(id, p);
    this.slots = [...this.slots.filter((s) => s.t !== slot.t), slot].sort((a, b) => a.t - b.t).slice(-30);
    this.sorted = [...this.items.values()].sort((a, b) => a.start - b.start);
  }

  /** The item on air at `t`, skipping ones a moderator pulled from air. */
  at(t: number, blocked?: ReadonlySet<string>): TimelineItem | null {
    const list = this.sorted;
    let lo = 0;
    let hi = list.length - 1;
    while (lo <= hi) {
      const mid = (lo + hi) >> 1;
      const it = list[mid]!;
      if (t < it.start) hi = mid - 1;
      else if (t >= it.start + it.dur) lo = mid + 1;
      else return blocked?.has(it.id) ? null : it;
    }
    return null;
  }

  next(t: number): TimelineItem | null {
    return this.sorted.find((it) => it.start > t) ?? null;
  }

  /** Until when the fetched files describe the program. */
  coveredUntil(): number {
    const last = this.sorted[this.sorted.length - 1];
    return last ? last.start + last.dur : 0;
  }

  /** The newest minute file at or before `t` (for program and submission state). */
  slotAt(t: number): SlotFile | null {
    let found: SlotFile | null = null;
    for (const s of this.slots) if (s.t <= t) found = s;
    return found;
  }

  program(id: string | null | undefined): ProgramRef | null {
    return id ? (this.programs.get(id) ?? null) : null;
  }

  submissions(t: number): Partial<Record<SubmissionType, SubmissionState>> {
    return this.slotAt(t)?.submissions ?? {};
  }

  hasMinute(t: number): boolean {
    return this.slots.some((s) => s.t === t);
  }

  prune(before: number): void {
    for (const [id, it] of this.items) if (it.start + it.dur < before) this.items.delete(id);
    this.slots = this.slots.filter((s) => s.t + 600_000 >= before);
    this.sorted = [...this.items.values()].sort((a, b) => a.start - b.start);
  }

  clear(): void {
    this.items.clear();
    this.sorted = [];
    this.programs.clear();
    this.slots = [];
  }
}
