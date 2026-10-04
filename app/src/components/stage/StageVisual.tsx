import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { GroupNotice, Lang, SubmissionState, SubmissionType, Voice, WallEntry } from '@arche/shared';
import type { EngineState } from '@/lib/engine';
import { useSession } from '@/store/session';
import { useSheets } from '@/store/sheets';
import { countryName } from '@/lib/format';
import { entryBy, entryText, wallPage } from '@/lib/prayerWall';
import { visibleVoices, visibleWall } from '@/lib/blocking';
import { useBlocks } from '@/store/blocks';
import { useServerNow } from '@/components/home/useServerNow';
import { CdnImg } from '@/components/common/CdnImg';
import { RadioIcon } from '@/components/common/icons';

/**
 * Our own stage, under the player: the program's visual, the host speaking,
 * a listener's recording, the moment of silence, community fly-ins. Captions
 * and fly-ins live here only — never over the YouTube player.
 *
 * In a prayer hour, while no song plays (the player is parked off screen
 * then, so the stage may carry buttons), its prayer view: a few of the
 * requests read out so far — or how many came in, during the collection —
 * and the buttons for what the minute file says is taken now: "Pray" in the
 * prayer time, "Share a prayer request" while requests are taken. A request
 * read out shows on the stage as it is read; a listener's written prayer
 * shows as theirs, never as the host's. The compact stage of other pages has
 * no sheets to open, so no buttons.
 *
 * Right after an item of a library group that wants it, the host's word comes
 * with the group's notice: who it was from and links to more (full stage only).
 */
export function StageVisual({ engine, compact = false }: { engine: EngineState; compact?: boolean }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === engine.channel));
  const program = engine.program;
  const item = engine.item;
  const image = program?.stage.image ?? null;
  const tagline = program?.stage.tagline[lang] ?? '';
  const hostName = channel?.host.name ?? 'Hope';
  const prayerHour = program?.format === 'prayer';
  // What this device reported or whose author it blocked stays off the stage too.
  const blocked = useBlocks((s) => s.users);
  const hidden = useBlocks((s) => s.hidden);
  const wall = visibleWall(engine.wall, hidden);
  const onAir = engine.mode === 'host' ? wall.filter((e) => engine.praying.includes(e.id)) : [];
  const hostKind = item?.type === 'host' ? item.kind : null;

  return (
    <div className="absolute inset-0 z-0 select-none">
      <Backdrop image={image} color={program?.color ?? '#2f7bff'} calm={engine.mode === 'silence' || engine.mode === 'bed'} />

      {/* Before joining, the round play button has the stage to itself. */}
      <div className={clsx('absolute inset-0 flex flex-col items-center justify-center p-5 text-center', !engine.joined && 'invisible')}>
        {engine.mode === 'host' && onAir.length > 0 && <OnAir entries={onAir} label={t('stage.prayingNow')} lang={lang} />}
        {engine.mode === 'host' && onAir.length === 0 && hostKind === 'intercession' && (
          <div className="stage-prayer animate-fly-in">
            <p className="eyebrow">{t('stage.listenerPrayer')}</p>
            {engine.hostText && <p className="text-balance text-base leading-snug text-ink drop-shadow sm:text-xl">{engine.hostText}</p>}
          </div>
        )}
        {engine.mode === 'host' && onAir.length === 0 && hostKind !== 'intercession' && (
          <HostMoment
            name={hostName}
            avatar={channel?.host.avatar ?? null}
            text={engine.hostText}
            label={t('stage.hostSpeaking', { name: hostName })}
            notice={!compact && item?.type === 'host' ? item.notice : null}
            lang={lang}
          />
        )}
        {engine.mode === 'contrib' && item?.type === 'contrib' && (
          <div className="max-w-lg animate-fly-in">
            {/* No name: the sender stays anonymous, so no stray "· place" either. */}
            {item.name && <p className="eyebrow mb-2">{item.name}{item.place ? ` · ${item.place}` : ''}</p>}
            <p className="text-lg font-medium leading-snug text-ink drop-shadow sm:text-2xl">
              {item.caption[lang] ?? item.caption.en ?? ''}
            </p>
          </div>
        )}
        {(engine.mode === 'bed' || engine.mode === 'silence') && prayerHour && (
          <PrayerView
            eyebrow={engine.mode === 'bed' ? t('nowPlaying.bed') : null}
            label={item && (item.type === 'bed' || item.type === 'silence') ? item.label[lang] : t('stage.silence')}
            wall={wall}
            collected={engine.collected}
            submissions={engine.submissions}
            compact={compact}
            lang={lang}
          />
        )}
        {engine.mode === 'bed' && !prayerHour && item?.type === 'bed' && (
          <div className="stage-prayer animate-fly-in">
            <p className="eyebrow">{t('nowPlaying.bed')}</p>
            {item.label[lang] && <p className="stage-prayer-title">{item.label[lang]}</p>}
          </div>
        )}
        {engine.mode === 'silence' && !prayerHour && (
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
        <FlyIns voices={visibleVoices(item.voices.length ? item.voices : engine.voices.slice(0, 3), blocked, hidden)} locale={lang} />
      )}
    </div>
  );
}

const taken = (s: SubmissionState | undefined): boolean => s === 'open' || s === 'closing';

/**
 * A prayer hour while nobody speaks — the collection's prayer music, the
 * quiet of the prayer time: its label; a few of the requests read out so far,
 * a page at a time and the same for everyone (what there is to pray for, not
 * one request picked for them), or how many came in; and the buttons for
 * what is taken now. The 60 s pieces render as one view: nothing here is
 * keyed by the item.
 */
function PrayerView({
  eyebrow,
  label,
  wall,
  collected,
  submissions,
  compact,
  lang,
}: {
  eyebrow: string | null;
  label: string;
  wall: WallEntry[];
  collected: number | null;
  submissions: Partial<Record<SubmissionType, SubmissionState>>;
  compact: boolean;
  lang: Lang;
}) {
  const { t } = useTranslation();
  const now = useServerNow(1000);
  const page = wallPage(wall, now, compact ? 1 : 3);
  const pray = !compact && taken(submissions.intercession);
  const share = !compact && taken(submissions.prayer);
  return (
    <div className="stage-prayer animate-fly-in">
      <div className="stage-prayer-ring mx-auto h-12 w-12 rounded-full border border-ink/30">
        <div className="h-full w-full animate-ring rounded-full border border-ink/40" />
      </div>
      {eyebrow && <p className="eyebrow">{eyebrow}</p>}
      {label && <p className="stage-prayer-title">{label}</p>}
      {page.length > 0 ? (
        <div className="stage-wall-page">
          {page.map((e) => (
            <WallCard key={e.id} entry={e} lang={lang} className="animate-fly-in" />
          ))}
        </div>
      ) : (
        collected !== null && collected > 0 && <p className="stage-prayer-count">{t('stage.collected', { count: collected })}</p>
      )}
      {(pray || share) && (
        <div className="stage-prayer-actions">
          {pray && <PrayButton />}
          {share && <ShareButton />}
        </div>
      )}
    </div>
  );
}

/** The request being read out right now, instead of the text a phone's stage cannot hold. */
function OnAir({ entries, label, lang }: { entries: WallEntry[]; label: string; lang: Lang }) {
  return (
    <div className="stage-prayer animate-fly-in">
      <p className="eyebrow">{label}</p>
      {entries.slice(0, 2).map((e) => (
        <WallCard key={e.id} entry={e} lang={lang} />
      ))}
    </div>
  );
}

/** One request on the stage: whose it is (when the sender said), then the text. */
function WallCard({ entry, lang, className }: { entry: WallEntry; lang: Lang; className?: string }) {
  const by = entryBy(entry);
  return (
    <div className={clsx('stage-wall-entry', className)}>
      {by && <p className="stage-wall-source">{by}</p>}
      <p className="stage-wall-text">{entryText(entry, lang)}</p>
    </div>
  );
}

function ShareButton() {
  const { t } = useTranslation();
  const show = useSheets((s) => s.show);
  return (
    <button type="button" className="stage-prayer-share" onClick={() => show('prayer')}>
      {t('stage.sharePrayer')}
    </button>
  );
}

/** The prayer time's main thing to do: a listener's own prayer, spoken first (the recorder), or written. */
function PrayButton() {
  const { t } = useTranslation();
  const show = useSheets((s) => s.show);
  return (
    <button type="button" className="stage-prayer-share is-main" onClick={() => show('pray')}>
      <span aria-hidden="true">🙏</span> {t('stage.pray')}
    </button>
  );
}

function Backdrop({ image, color, calm }: { image: string | null; color: string; calm: boolean }) {
  if (image) {
    return (
      <>
        <CdnImg src={image} className={clsx('absolute inset-0 h-full w-full object-cover transition-opacity duration-700', calm && 'opacity-50')} />
        <div className="absolute inset-0 bg-gradient-to-t from-soft/80 via-soft/10 to-transparent" />
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
      <span className="font-serif text-3xl font-bold tracking-tight sm:text-4xl">Arche Radio</span>
      <RadioIcon size={30} className="text-accent" />
    </div>
  );
}

function HostMoment({
  name,
  avatar,
  text,
  label,
  notice,
  lang,
}: {
  name: string;
  avatar: string | null;
  text: string | null;
  label: string;
  notice: GroupNotice | null;
  lang: Lang;
}) {
  return (
    <div className={clsx('stage-host flex max-w-xl flex-col items-center gap-3 animate-fly-in', notice && 'has-notice')}>
      <div className="stage-host-avatar relative h-20 w-20 sm:h-24 sm:w-24">
        <span className="absolute inset-0 animate-ring rounded-full border-2 border-accent/60" />
        <span className="absolute inset-0 animate-ring rounded-full border-2 border-accent/40 [animation-delay:0.7s]" />
        {avatar ? (
          <CdnImg src={avatar} className="relative h-full w-full rounded-full object-cover ring-2 ring-accent/70" />
        ) : (
          <div className="relative flex h-full w-full items-center justify-center rounded-full bg-gradient-to-br from-accent-fill to-song-to text-2xl font-semibold ring-2 ring-accent/70">
            {name.slice(0, 1)}
          </div>
        )}
      </div>
      <p className="eyebrow">{label}</p>
      {text && <p className="stage-host-text text-balance text-base leading-snug text-ink drop-shadow sm:text-xl">{text}</p>}
      {notice && <NoticeCard notice={notice} lang={lang} />}
    </div>
  );
}

/** A link's label: what it is, or for any other site its name ("example.org"). */
function linkHost(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch {
    return url;
  }
}

/**
 * Who the item before was from — a preacher, a church, a ministry, an artist
 * — a few words about them and where to find more. The links open outside
 * the app (a new tab; the system browser in the store apps), and are https
 * only: the parser drops anything else.
 */
function NoticeCard({ notice, lang }: { notice: GroupNotice; lang: Lang }) {
  const { t } = useTranslation();
  const about = notice.text[lang] || notice.text.en;
  return (
    <div className="stage-notice">
      <p className="eyebrow">{t('stage.moreFrom', { name: notice.name })}</p>
      {about && <p className="stage-notice-text">{about}</p>}
      {notice.links.length > 0 && (
        <div className="stage-prayer-actions">
          {notice.links.map((l, i) => (
            <a key={`${i}-${l.url}`} className="stage-prayer-share" href={l.url} target="_blank" rel="noopener noreferrer">
              {l.kind === 'other' ? linkHost(l.url) : t(`stage.link.${l.kind}`)}
            </a>
          ))}
        </div>
      )}
    </div>
  );
}

function FlyIns({ voices, locale }: { voices: Voice[]; locale: Lang }) {
  return (
    <div className="pointer-events-none absolute inset-x-3 bottom-3 flex flex-col items-start gap-2">
      {voices.slice(0, 3).map((v, i) => (
        <div
          key={v.id}
          className="max-w-[80%] animate-fly-in rounded-2xl border border-ink/10 bg-soft/70 px-3 py-1.5 text-left text-xs text-ink backdrop-blur"
          style={{ animationDelay: `${i * 1.2}s` }}
        >
          <span className="font-semibold text-accent">{v.name}</span>
          {v.country && <span className="text-ink-faint"> · {countryName(v.country, locale)}</span>}
          <span className="block text-ink/90">{v.text}</span>
        </div>
      ))}
    </div>
  );
}
