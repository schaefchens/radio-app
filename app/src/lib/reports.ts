import type { ChatMessage } from '@arche/shared';
import { useBlocks } from '@/store/blocks';
import { api } from './api';
import { voiceTag } from './blocking';
import { realtime } from './realtime/client';

/**
 * Reporting and blocking from the listener's side (the app stores require
 * both for every kind of user content). What is reported disappears on this
 * device at once; moderators decide in /mod (server/app/Moderation/Reports.php).
 */

const REASON = 'inappropriate';

/** A request on the prayer wall. Gone from the wall meanwhile (404) is fine too. */
export function reportWallEntry(id: string): void {
  useBlocks.getState().hide(id);
  void api(`/wall/${encodeURIComponent(id)}/report`, { method: 'POST', body: { reason: REASON } }).catch(() => {});
}

/** A community voice: a message of the live room through the room, a published one through the station. */
export function reportVoice(id: string): void {
  useBlocks.getState().hide(id);
  if (realtime.isConnected() && realtime.hasMessage(id) && realtime.report(id, REASON)) return;
  void api(`/voices/${encodeURIComponent(id)}/report`, { method: 'POST', body: { reason: REASON } }).catch(() => {});
}

/**
 * Block the author of a room message: their messages and community voices
 * disappear here, and the moderators learn of it as a report.
 */
export async function blockAuthor(msg: ChatMessage): Promise<void> {
  const tag = await voiceTag(msg.sub);
  useBlocks.getState().block({ sub: msg.sub, name: msg.name, tag, at: Date.now() });
  realtime.report(msg.id, 'blocked');
}
