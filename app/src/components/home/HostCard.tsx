import { useTranslation } from 'react-i18next';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';

/** "Our AI host says:" — the current words, or the last ones while music plays. */
export function HostCard() {
  const { t } = useTranslation();
  const engine = useRadio((s) => s.engine);
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === engine.channel));
  const name = channel?.host.name ?? 'Hope';
  const text = engine.hostText ?? engine.lastHost?.text ?? null;
  return (
    <div className="card flex items-start gap-4 p-4">
      {channel?.host.avatar ? (
        <img src={channel.host.avatar} alt="" className="h-16 w-16 shrink-0 rounded-full object-cover ring-2 ring-brand/40 sm:h-20 sm:w-20" />
      ) : (
        <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand to-song-to text-2xl font-semibold ring-2 ring-brand/40 sm:h-20 sm:w-20">
          {name.slice(0, 1)}
        </div>
      )}
      <div className="min-w-0">
        <p className="text-sm font-semibold text-brand-bright">{t('host.says')}</p>
        <p className="mt-1 text-[0.95rem] leading-relaxed text-ink">{text ?? t('host.idle', { name })}</p>
      </div>
    </div>
  );
}
