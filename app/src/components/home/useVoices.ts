import { useMemo } from 'react';
import type { Voice } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useBlocks } from '@/store/blocks';
import { visibleVoices } from '@/lib/blocking';
import { useChatVoices } from '@/components/chat/useChatVoices';

/**
 * The room's latest messages (while connected) and the approved highlights,
 * once each — without the voices of people this listener blocked, or that
 * they reported.
 */
export function useVoices(): Voice[] {
  const chat = useChatVoices();
  const published = useRadio((s) => s.engine.voices);
  const blocked = useBlocks((s) => s.users);
  const hidden = useBlocks((s) => s.hidden);
  return useMemo(() => {
    const seen = new Set<string>();
    return visibleVoices([...chat, ...published], blocked, hidden).filter((v) => !seen.has(v.id) && !!seen.add(v.id));
  }, [chat, published, blocked, hidden]);
}
