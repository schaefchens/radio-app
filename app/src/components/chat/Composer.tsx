import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useChat } from '@/store/chat';
import { realtime } from '@/lib/realtime/client';
import { SendIcon } from '@/components/common/icons';

const MAX = 280;

export function Composer() {
  const { t } = useTranslation();
  const connected = useChat((s) => s.status === 'connected');
  const [text, setText] = useState('');
  const send = (): void => {
    if (realtime.sendChat(text)) setText('');
  };
  return (
    <form
      className="flex items-end gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        send();
      }}
    >
      <label className="sr-only" htmlFor="chat-text">
        {t('chat.placeholder')}
      </label>
      <textarea
        id="chat-text"
        className="field min-h-[44px] flex-1 resize-none"
        rows={1}
        maxLength={MAX}
        placeholder={t('chat.placeholder')}
        value={text}
        disabled={!connected}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
          }
        }}
      />
      <button type="submit" className="btn-primary h-11 w-11 shrink-0 p-0" aria-label={t('chat.send')} disabled={!connected || text.trim() === ''}>
        <SendIcon size={18} />
      </button>
    </form>
  );
}
