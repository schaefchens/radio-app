import { useMemo } from 'react';
import type { Voice } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useChatVoices } from '@/components/chat/useChatVoices';

/** The room's latest messages (while connected) and the approved highlights, once each. */
export function useVoices(): Voice[] {
  const chat = useChatVoices();
  const published = useRadio((s) => s.engine.voices);
  return useMemo(() => {
    const seen = new Set<string>();
    return [...chat, ...published].filter((v) => !seen.has(v.id) && !!seen.add(v.id));
  }, [chat, published]);
}
