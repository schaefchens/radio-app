import { VIDEO_SUBMISSIONS, isVideoFormat, isVideoSubmissionType, type ProgramFormat } from '@arche/shared';

/**
 * The submission types a program takes once its format changes from `from` to
 * `to`, as the server keeps them (Catalog::saveProgram): a video format takes
 * its own kind of suggestion from the start and gives up the old format's own
 * one; any other video kind a moderator ticked stays. A format without videos
 * takes none of them — a suggestion sent there would wait for a moment that
 * never comes.
 */
export function allowedForFormat(allowed: readonly string[], from: ProgramFormat, to: ProgramFormat): string[] {
  if (from === to) return [...allowed];
  const kept = allowed.filter((a) => !(isVideoFormat(from) && a === VIDEO_SUBMISSIONS[from]));
  if (!isVideoFormat(to)) return kept.filter((a) => !isVideoSubmissionType(a));
  const own = VIDEO_SUBMISSIONS[to];
  return kept.includes(own) ? kept : [...kept, own];
}
