import { useMemo } from 'react';
import type { Voice } from '@arche/shared';
import { useChat } from '@/store/chat';
import { useBlocks } from '@/store/blocks';
import { visibleMessages } from '@/lib/blocking';

/**
 * Messages from the listener's chat room, as voices for the main screen:
 * newest first, at most five, only while connected (otherwise Home shows the
 * moderated voices from live.json alone). A reaction on one of these is a
 * like in the room — see reactVoice() in lib/radio.ts.
 */
export function useChatVoices(): Voice[] {
  const status = useChat((s) => s.status);
  const messages = useChat((s) => s.messages);
  const me = useChat((s) => s.me);
  const blocked = useBlocks((s) => s.users);
  return useMemo(() => {
    if (status !== 'connected') return [];
    return visibleMessages(messages, blocked)
      .filter((m) => !m.id.startsWith('local:') && m.sub !== me?.sub)
      .slice(-5)
      .reverse()
      .map((m) => ({ id: m.id, name: m.name, country: m.country, text: m.text, at: m.at }));
  }, [status, messages, me, blocked]);
}
