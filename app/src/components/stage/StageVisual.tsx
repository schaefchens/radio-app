import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { Lang, Voice } from '@arche/shared';
import type { EngineState } from '@/lib/engine';
import { useSession } from '@/store/session';
import { countryName } from '@/lib/format';
import { CdnImg } from '@/components/common/CdnImg';
import { RadioIcon } from '@/components/common/icons';

/**
 * Our own stage, under the player: the program's visual, the host speaking,
 * a listener's recording, the moment of silence, community fly-ins. Captions
 * and fly-ins live here only — never over the YouTube player.
 */
export function StageVisual({ engine }: { engine: EngineState }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === engine.channel));
  const program = engine.program;
  const item = engine.item;
  const image = program?.stage.image ?? null;
  const tagline = program?.stage.tagline[lang] ?? '';
  const hostName = channel?.host.name ?? 'Hope';

  return (
    <div className="absolute inset-0 z-0 select-none">
      <Backdrop image={image} color={program?.color ?? '#2f7bff'} calm={engine.mode === 'silence'} />

      <div className="absolute inset-0 flex flex-col items-center justify-center p-5 text-center">
        {engine.mode === 'host' && (
          <HostMoment name={hostName} avatar={channel?.host.avatar ?? null} text={engine.hostText} label={t('stage.hostSpeaking', { name: hostName })} />
        )}
        {engine.mode === 'contrib' && item?.type === 'contrib' && (
          <div className="max-w-lg animate-fly-in">
            <p className="eyebrow mb-2">{item.name}{item.place ? ` · ${item.place}` : ''}</p>
            <p className="text-lg font-medium leading-snug text-ink drop-shadow sm:text-2xl">
              {item.caption[lang] ?? item.caption.en ?? ''}
            </p>
          </div>
        )}
        {engine.mode === 'silence' && (
          <div className="animate-fly-in">
            <div className="mx-auto mb-4 h-16 w-16 rounded-full border border-ink/30">
              <div className="h-full w-full animate-ring rounded-full border border-ink/40" />
            </div>
            <p className="text-xl font-light tracking-wide text-ink/90">{t('stage.silence')}</p>
          </div>
        )}
        {(engine.mode === 'stage' || engine.mode === 'jingle') && (
          <div className="animate-fly-in">
            <Logo />
            {item && (item.type === 'stage' || item.type === 'silence') && (
              <p className="mt-3 text-base text-ink-muted">{item.label[lang]}</p>
            )}
          </div>
        )}
        {engine.mode === 'offline' && (
          <div className="animate-fly-in">
            <Logo />
            <p className="mt-3 text-sm text-ink-muted">{engine.hasData ? t('stage.offline') : t('stage.preparing')}</p>
          </div>
        )}
        {(engine.mode === 'idle' || ((engine.mode === 'song' || engine.mode === 'evergreen') && !engine.joined)) && (
          <div className="animate-fly-in">
            {!image && <Logo />}
            {tagline && <p className="mt-3 max-w-md text-balance text-2xl font-light italic text-ink drop-shadow-lg sm:text-3xl">{tagline}</p>}
          </div>
        )}
      </div>

      {engine.mode === 'host' && program?.stage.mode === 'flyins' && item?.type === 'host' && (
        <FlyIns voices={item.voices.length ? item.voices : engine.voices.slice(0, 3)} locale={lang} />
      )}
    </div>
  );
}

function Backdrop({ image, color, calm }: { image: string | null; color: string; calm: boolean }) {
  if (image) {
    return (
      <>
        <CdnImg src={image} className={clsx('absolute inset-0 h-full w-full object-cover transition-opacity duration-700', calm && 'opacity-50')} />
        <div className="absolute inset-0 bg-gradient-to-t from-night-deep/80 via-night-deep/10 to-transparent" />
      </>
    );
  }
  // The default: a dawn over the night blue, breathing slowly.
  return (
    <div
      className={clsx('absolute inset-0 transition-opacity duration-1000', calm ? 'opacity-40' : 'opacity-100')}
      style={{
        background: `radial-gradient(60% 55% at 50% 78%, rgb(255 170 90 / 0.55), transparent 70%),
          radial-gradient(45% 40% at 22% 30%, ${color}55, transparent 70%),
          radial-gradient(50% 45% at 82% 25%, rgb(142 77 255 / 0.35), transparent 70%),
          linear-gradient(180deg, #0b1a3d 0%, #13244d 55%, #2a2140 100%)`,
      }}
    >
      <div className="absolute inset-0 animate-pulse-soft bg-[radial-gradient(40%_30%_at_50%_85%,rgb(255_210_150/0.25),transparent_70%)]" />
    </div>
  );
}

function Logo() {
  return (
    <div className="flex items-center justify-center gap-2 text-ink">
      <span className="text-3xl font-light tracking-logo sm:text-4xl">ARCHE</span>
      <RadioIcon size={30} className="text-brand-bright" />
    </div>
  );
}

function HostMoment({ name, avatar, text, label }: { name: string; avatar: string | null; text: string | null; label: string }) {
  return (
    <div className="flex max-w-xl flex-col items-center gap-3 animate-fly-in">
      <div className="relative h-20 w-20 sm:h-24 sm:w-24">
        <span className="absolute inset-0 animate-ring rounded-full border-2 border-brand-bright/60" />
        <span className="absolute inset-0 animate-ring rounded-full border-2 border-brand-bright/40 [animation-delay:0.7s]" />
        {avatar ? (
          <CdnImg src={avatar} className="relative h-full w-full rounded-full object-cover ring-2 ring-brand-bright/70" />
        ) : (
          <div className="relative flex h-full w-full items-center justify-center rounded-full bg-gradient-to-br from-brand to-song-to text-2xl font-semibold ring-2 ring-brand-bright/70">
            {name.slice(0, 1)}
          </div>
        )}
      </div>
      <p className="eyebrow">{label}</p>
      {text && <p className="text-balance text-base leading-snug text-ink drop-shadow sm:text-xl">{text}</p>}
    </div>
  );
}

function FlyIns({ voices, locale }: { voices: Voice[]; locale: Lang }) {
  return (
    <div className="pointer-events-none absolute inset-x-3 bottom-3 flex flex-col items-start gap-2">
      {voices.slice(0, 3).map((v, i) => (
        <div
          key={v.id}
          className="max-w-[80%] animate-fly-in rounded-2xl border border-ink/10 bg-night-deep/70 px-3 py-1.5 text-left text-xs text-ink backdrop-blur"
          style={{ animationDelay: `${i * 1.2}s` }}
        >
          <span className="font-semibold text-brand-bright">{v.name}</span>
          {v.country && <span className="text-ink-faint"> · {countryName(v.country, locale)}</span>}
          <span className="block text-ink/90">{v.text}</span>
        </div>
      ))}
    </div>
  );
}
