import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useChat } from '@/store/chat';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { realtime } from '@/lib/realtime/client';
import { NameGate } from '@/components/chat/NameGate';
import { MessageList } from '@/components/chat/MessageList';
import { Composer } from '@/components/chat/Composer';
import { chatErrorKey } from '@/lib/realtime/errors';
import { RefreshIcon, UsersIcon } from '@/components/common/icons';

/**
 * The community room. Joining may wake a realtime node first (the first
 * listener after a quiet spell waits ~30–40 s while it boots). The room stays
 * connected when the listener leaves this page, so Home keeps its live voices;
 * "leave" is explicit.
 */
export function ChatPage() {
  const { t } = useTranslation();
  const identity = useSession((s) => s.identity);
  const realtimeOn = useSession((s) => s.config?.realtime ?? true);
  const channel = useRadio((s) => s.engine.channel);
  const status = useChat((s) => s.status);
  const error = useChat((s) => s.error);
  const room = useChat((s) => s.room);
  const hasName = !!identity?.name;

  useEffect(() => {
    if (hasName && channel && realtimeOn && !identity?.banned) void realtime.connect(channel);
  }, [hasName, channel, realtimeOn, identity?.banned]);

  if (!realtimeOn) return <Shell><p className="card px-4 py-3 text-sm text-ink-muted">{t('chat.unavailable')}</p></Shell>;
  if (identity?.banned) return <Shell><p className="card px-4 py-3 text-sm text-heart">{t('chat.banned')}</p></Shell>;
  if (!hasName) return <Shell><NameGate /></Shell>;

  const errorKey = chatErrorKey(error);
  return (
    <Shell
      header={
        <div className="flex items-center gap-3">
          {status === 'connected' && room && (
            <span className="inline-flex items-center gap-1.5 text-sm text-ink-muted">
              <UsersIcon size={18} />
              {t('chat.people', { count: room.count })}
            </span>
          )}
          {(status === 'connected' || status === 'connecting' || status === 'waking') && (
            <button type="button" className="btn-ghost px-3 py-1.5 text-xs" onClick={() => realtime.leave()}>
              {t('chat.leave')}
            </button>
          )}
        </div>
      }
    >
      <div className="card flex min-h-[50vh] flex-col gap-3 p-3 sm:p-4">
        {status === 'waking' && <Waiting text={t('chat.waking')} />}
        {status === 'connecting' && <Waiting text={t('chat.connecting')} />}
        {status === 'idle' && (
          <div className="flex flex-1 items-center justify-center">
            <button type="button" className="btn-primary" onClick={() => void realtime.connect(channel)}>
              {t('chat.join')}
            </button>
          </div>
        )}
        {(status === 'unavailable' || status === 'error') && (
          <div className="flex flex-1 flex-col items-center justify-center gap-3 text-center">
            <p className="text-sm text-ink-muted">{t(errorKey ?? 'chat.error')}</p>
            {error !== 'banned' && (
              <button type="button" className="btn-ghost" onClick={() => void realtime.connect(channel)}>
                <RefreshIcon size={16} />
                {t('chat.retry')}
              </button>
            )}
          </div>
        )}
        {status === 'connected' && (
          <>
            <div className="max-h-[60vh] flex-1 overflow-y-auto pr-1">
              <MessageList />
            </div>
            {errorKey && <p className="text-xs text-heart">{t(errorKey)}</p>}
            <Composer />
          </>
        )}
      </div>
    </Shell>
  );
}

function Shell({ children, header }: { children: React.ReactNode; header?: React.ReactNode }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-col gap-4 pt-2">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('chat.title')}</h1>
        {header}
      </div>
      {children}
    </div>
  );
}

function Waiting({ text }: { text: string }) {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-4 py-10 text-center">
      <span className="relative flex h-12 w-12">
        <span className="absolute inset-0 animate-ring rounded-full border-2 border-brand-bright/60" />
        <span className="relative m-auto h-4 w-4 rounded-full bg-brand-bright" />
      </span>
      <p className="max-w-sm text-sm text-ink-muted">{text}</p>
    </div>
  );
}
