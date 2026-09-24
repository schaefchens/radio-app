import { afterEach, describe, expect, it } from 'vitest';
import { renderHook } from '@testing-library/react';
import type { ChatMessage } from '@arche/shared';
import { useChatVoices } from '@/components/chat/useChatVoices';
import { initialChat, useChat } from '@/store/chat';

const msg = (id: string, sub = 'other'): ChatMessage => ({ id, sub, name: 'Maria', country: 'DE', text: `text ${id}`, at: Number(id.slice(1)) || 0, likes: 0 });

afterEach(() => useChat.setState({ ...initialChat }));

describe('useChatVoices', () => {
  it('is empty unless connected', () => {
    useChat.setState({ ...initialChat, status: 'connecting', messages: [msg('m1')] });
    expect(renderHook(() => useChatVoices()).result.current).toEqual([]);
  });

  it('newest first, at most five, without own or unconfirmed messages', () => {
    useChat.setState({
      ...initialChat,
      status: 'connected',
      me: { sub: 'me', name: 'Jo' },
      messages: [msg('m1'), msg('m2'), msg('m3'), msg('m4', 'me'), msg('m5'), msg('m6'), msg('m7'), { ...msg('local:c1', 'me') }],
    });
    const voices = renderHook(() => useChatVoices()).result.current;
    expect(voices.map((v) => v.id)).toEqual(['m7', 'm6', 'm5', 'm3', 'm2']);
    expect(voices[0]).toEqual({ id: 'm7', name: 'Maria', country: 'DE', text: 'text m7', at: 7 });
  });
});
