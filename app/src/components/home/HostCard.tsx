import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { CdnImg } from '@/components/common/CdnImg';
import { MicIcon } from '@/components/common/icons';
import { speakingHost } from '@/lib/host';

/** The design's waveform: fixed bars that only move while the host speaks. */
const BARS = [20, 45, 65, 35, 85, 55, 100, 70, 40, 80, 55, 30, 65, 95, 60, 35, 75, 50, 25, 45];

/** "From the host" on desktop, beside the player. */
export function HostCard() {
  const { t } = useTranslation();
  const speaking = useRadio((s) => s.engine.hostText !== null);
  return (
    <section className="card host-card" aria-label={t('host.heading')}>
      <div className="section-heading">
        <MicIcon />
        <h2>{t('host.heading')}</h2>
        {speaking && <span className="host-badge">{t('live.badge')}</span>}
      </div>
      <HostBody />
    </section>
  );
}

/** The host's face, name and words: the current ones, or the last ones while music plays. */
export function HostBody() {
  const { t, i18n } = useTranslation();
  const engine = useRadio((s) => s.engine);
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === engine.channel));
  // Who speaks now — or spoke last: several hosts share the station.
  const host = speakingHost(engine.item, engine.lastHost, channel);
  const name = host.name;
  const about = host.about[i18n.language === 'de' ? 'de' : 'en'] || host.about.en;
  const speaking = engine.hostText !== null;
  // A prayer hour has no songs to come back between.
  const text = engine.hostText ?? engine.lastHost?.text ?? t(engine.program?.format === 'prayer' ? 'host.idlePrayer' : 'host.idle', { name });
  return (
    <div className="host-body">
      <div className="host-avatar" aria-hidden="true" style={{ borderColor: host.color }}>
        {host.avatar ? <CdnImg src={host.avatar} className="h-full w-full rounded-full object-cover" /> : <MicIcon />}
      </div>
      <div className="host-message">
        <strong>{name}</strong>
        {about && <small className="host-about">{about}</small>}
        <div className={clsx('waveform', speaking && 'is-speaking')} aria-hidden="true">
          {BARS.map((h, i) => (
            <i key={i} style={{ '--bar': `${h}%`, animationDelay: `${(i % 7) * -0.13}s` } as React.CSSProperties} />
          ))}
        </div>
        <p>{text}</p>
      </div>
    </div>
  );
}
