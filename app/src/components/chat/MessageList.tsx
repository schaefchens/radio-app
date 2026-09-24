import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { ChatMessage, Lang } from '@arche/shared';
import { useChat } from '@/store/chat';
import { realtime } from '@/lib/realtime/client';
import { countryName, localTime } from '@/lib/format';
import { FlagIcon, HeartIcon } from '@/components/common/icons';
import { ConfirmButton } from '@/components/mod/ui';

export function MessageList() {
  const { t } = useTranslation();
  const messages = useChat((s) => s.messages);
  const end = useRef<HTMLDivElement>(null);

  // Follow the conversation: scroll to the newest message when one arrives.
  useEffect(() => {
    end.current?.scrollIntoView({ block: 'end', behavior: 'smooth' });
  }, [messages.length]);

  if (messages.length === 0) return <p className="px-2 py-8 text-center text-sm text-ink-muted">{t('chat.empty')}</p>;
  return (
    <ol className="flex flex-col gap-2">
      {messages.map((m) => (
        <MessageRow key={m.id} msg={m} />
      ))}
      <div ref={end} />
    </ol>
  );
}

function MessageRow({ msg }: { msg: ChatMessage }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const me = useChat((s) => s.me);
  const liked = useChat((s) => !!s.liked[msg.id]);
  const reported = useChat((s) => !!s.reported[msg.id]);
  const mine = me !== null && msg.sub === me.sub;
  const local = msg.id.startsWith('local:');
  return (
    <li className={clsx('flex', mine ? 'justify-end' : 'justify-start')}>
      <div className={clsx('max-w-[85%] rounded-2xl border px-3 py-2', mine ? 'border-brand/40 bg-brand/15' : 'border-night-line/25 bg-night-raised/60', local && 'opacity-60')}>
        <p className="text-xs text-ink-muted">
          <span className="font-semibold text-brand-bright">{mine ? t('chat.you') : msg.name}</span>
          {msg.country && ` · ${countryName(msg.country, lang)}`}
          {` · ${localTime(msg.at, lang)}`}
        </p>
        <p className="whitespace-pre-wrap break-words text-sm text-ink">{msg.text}</p>
        {!local && (
          <div className="mt-1 flex items-center gap-2">
            <button
              type="button"
              aria-label={t('chat.like')}
              disabled={mine || liked}
              onClick={() => realtime.like(msg.id)}
              className={clsx('inline-flex items-center gap-1 rounded-lg px-1.5 py-0.5 text-xs', liked ? 'text-heart' : 'text-ink-muted hover:text-heart', mine && 'cursor-default')}
            >
              <HeartIcon size={14} filled={liked} />
              {msg.likes > 0 && <span className="tabular-nums">{msg.likes}</span>}
            </button>
            {!mine &&
              (reported ? (
                <span className="text-[0.7rem] text-ink-faint">{t('chat.reported')}</span>
              ) : (
                <ConfirmButton
                  className="inline-flex items-center gap-1 rounded-lg px-1.5 py-0.5 text-xs text-ink-faint hover:text-ink"
                  label={
                    <>
                      <FlagIcon size={12} />
                      {t('chat.report')}
                    </>
                  }
                  question={t('chat.reportConfirm')}
                  onConfirm={() => realtime.report(msg.id, 'inappropriate')}
                />
              ))}
          </div>
        )}
      </div>
    </li>
  );
}
