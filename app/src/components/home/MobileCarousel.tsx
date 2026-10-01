import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { useRadio } from '@/store/radio';
import { useRotation } from './useRotation';
import { fitMessages } from './carouselFit';
import { VoiceMessage, VoicesHeading } from './CommunityVoices';
import { useVoices } from './useVoices';
import { HostBody } from './HostCard';
import { PrayerEntry, WallEmpty, WallHeading } from './PrayerWall';
import { usePrayingWall } from './usePrayingWall';
import { ChevronIcon, MicIcon } from '@/components/common/icons';

const PANELS = ['voices', 'moderator', 'prayers'] as const;
type Panel = (typeof PANELS)[number];
const TITLE: Record<Panel, string> = { voices: 'voices.title', moderator: 'host.heading', prayers: 'wall.title' };
/** A swipe, not a wobble: this far sideways, and clearly more than up or down. */
const SWIPE_PX = 45;
/** The tallest message the panel has to leave room for (its min-height + gap). */
const MIN_ROW = 119;

/**
 * The phone's one panel under the pinned player (theme-preview.html, the
 * mobile carousel): community voices, the host, the prayer wall — by arrows,
 * dots, a swipe or ←/→. The feeds show as many messages as fit above the
 * dock and move on every few seconds.
 */
export function MobileCarousel({ onMoreWall }: { onMoreWall: () => void }) {
  const { t } = useTranslation();
  const [index, setIndex] = useState(0);
  const [visible, setVisible] = useState(1);
  const [picking, setPicking] = useState(false);
  const [nudge, setNudge] = useState(0);
  const section = useRef<HTMLElement>(null);
  const voicesBody = useRef<HTMLDivElement>(null);
  const prayersBody = useRef<HTMLDivElement>(null);
  const swipe = useRef<{ id: number; x: number; y: number } | null>(null);
  const voices = useVoices();
  const { wall, praying, now: prayingNow } = usePrayingWall();
  const speaking = useRadio((s) => s.engine.hostText !== null);
  const panel = PANELS[index]!;

  const onActivity = useCallback((open: boolean) => {
    setPicking(open);
    setNudge((n) => n + 1);
  }, []);
  const voiceOffset = useRotation(voicesBody, voices.length, panel !== 'voices' || picking || voices.length <= visible, nudge);
  // While the host prays for a request on the wall, it stays first ("Praying now").
  const prayerOffset = useRotation(prayersBody, wall.length, panel !== 'prayers' || picking || prayingNow || wall.length <= visible, nudge);

  const select = (i: number) => {
    setIndex((i + PANELS.length) % PANELS.length);
    setPicking(false);
  };

  // As many messages as fit between the pinned player and the unfolded dock.
  useEffect(() => {
    const el = section.current;
    const player = document.querySelector<HTMLElement>('.player-dock');
    if (!el || !player) return;
    const measure = () => {
      const style = getComputedStyle(el);
      const top = parseFloat(getComputedStyle(player).top) || 0;
      const nav = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--dock-nav')) || 64;
      const tray = window.innerWidth <= 400 ? 60 : 54;
      const available = window.innerHeight - top - player.getBoundingClientRect().height - (nav + tray) - 24;
      const heading = el.querySelector('.carousel-slide .section-heading')?.getBoundingClientRect().height ?? 0;
      const dots = el.querySelector('.carousel-pagination')?.getBoundingClientRect().height ?? 0;
      const chrome = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom) + heading + dots + 2;
      const rows = [...el.querySelectorAll<HTMLElement>('.carousel-body .feed-message')].map((m) => m.getBoundingClientRect().height + parseFloat(getComputedStyle(m).marginTop));
      setVisible(fitMessages(available, chrome, Math.max(MIN_ROW, ...rows)));
    };
    const observer = new ResizeObserver(measure);
    observer.observe(player);
    observer.observe(el);
    window.addEventListener('resize', measure);
    measure();
    return () => {
      observer.disconnect();
      window.removeEventListener('resize', measure);
    };
  }, []);

  const window_ = <T,>(list: T[], offset: number): T[] =>
    list.length <= visible ? list : Array.from({ length: visible }, (_, i) => list[(offset + i) % list.length]!);

  const title = (p: Panel) => t(TITLE[p]);
  const prev = PANELS[(index + PANELS.length - 1) % PANELS.length]!;
  const next = PANELS[(index + 1) % PANELS.length]!;

  return (
    <section
      ref={section}
      className="card community mobile-carousel"
      data-active-panel={panel}
      aria-roledescription={t('carousel.roledesc')}
      aria-label={t('carousel.label')}
      onKeyDown={(e) => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        if ((e.target as HTMLElement).closest('.feed-emoji-picker')) return;
        e.preventDefault();
        select(index + (e.key === 'ArrowRight' ? 1 : -1));
      }}
    >
      <button type="button" className="carousel-arrow previous" aria-label={t('carousel.show', { name: title(prev) })} onClick={() => select(index - 1)}>
        <ChevronIcon dir="left" />
      </button>
      <div
        className="carousel-stage"
        onPointerDown={(e) => {
          swipe.current = null;
          if (!e.isPrimary || e.button !== 0 || (e.target as HTMLElement).closest('button, a, input, textarea, select')) return;
          swipe.current = { id: e.pointerId, x: e.clientX, y: e.clientY };
          if (e.pointerType === 'mouse') e.preventDefault();
          e.currentTarget.setPointerCapture(e.pointerId);
        }}
        onPointerUp={(e) => {
          const start = swipe.current;
          swipe.current = null;
          if (!start || e.pointerId !== start.id) return;
          const dx = e.clientX - start.x;
          const dy = e.clientY - start.y;
          if (Math.abs(dx) > SWIPE_PX && Math.abs(dx) > Math.abs(dy) * 1.25) select(index + (dx < 0 ? 1 : -1));
        }}
        onPointerCancel={() => {
          swipe.current = null;
        }}
      >
        <div className="carousel-track" style={{ transform: `translateX(-${index * 100}%)` }}>
          {PANELS.map((p, i) => (
            <div
              key={p}
              className="carousel-slide"
              data-panel={p}
              role="group"
              aria-label={t('carousel.slide', { n: i + 1, total: PANELS.length, name: title(p) })}
              aria-hidden={i !== index}
              inert={i !== index}
            >
              {p === 'voices' && (
                <>
                  <VoicesHeading />
                  <div ref={voicesBody} key={voiceOffset} className={clsx('carousel-body', voices.length > visible && 'is-entering')}>
                    {voices.length === 0 && <p className="feed-empty">{t('voices.empty')}</p>}
                    {window_(voices, voiceOffset).map((v) => (
                      <VoiceMessage key={v.id} voice={v} onActivity={onActivity} />
                    ))}
                  </div>
                </>
              )}
              {p === 'moderator' && (
                <>
                  <div className="section-heading">
                    <MicIcon />
                    <h2>{t('host.heading')}</h2>
                    {speaking && <span className="host-badge">{t('live.badge')}</span>}
                  </div>
                  <div className="carousel-body moderator-body">
                    <HostBody />
                  </div>
                </>
              )}
              {p === 'prayers' && (
                <>
                  <WallHeading onMore={onMoreWall} />
                  <div ref={prayersBody} key={prayerOffset} className={clsx('carousel-body', wall.length > visible && 'is-entering')}>
                    {wall.length === 0 && <WallEmpty />}
                    {window_(wall, prayingNow ? 0 : prayerOffset).map((e) => (
                      <PrayerEntry key={e.id} entry={e} praying={praying.includes(e.id)} onActivity={onActivity} />
                    ))}
                  </div>
                </>
              )}
            </div>
          ))}
        </div>
      </div>
      <button type="button" className="carousel-arrow next" aria-label={t('carousel.show', { name: title(next) })} onClick={() => select(index + 1)}>
        <ChevronIcon dir="right" />
      </button>
      <div className="carousel-pagination" role="group" aria-label={t('carousel.pick')}>
        {PANELS.map((p, i) => (
          <button key={p} type="button" aria-label={t('carousel.show', { name: title(p) })} aria-pressed={i === index} onClick={() => select(i)} />
        ))}
      </div>
    </section>
  );
}
