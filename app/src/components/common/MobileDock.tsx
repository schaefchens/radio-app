import { useCallback, useEffect, useRef, useState } from 'react';
import { MainNav } from './MainNav';
import { SubmitTiles } from '@/components/home/SubmitTiles';

const PHONE = '(max-width: 600px)';
/** How long a tapped-open tray stays open before it folds away again. */
const TRAY_OPEN_MS = 6000;

const isPhone = () => typeof matchMedia === 'function' && matchMedia(PHONE).matches;

/**
 * The phone's bottom dock (theme-preview.html, the mobile dock): the menu,
 * and on Home the four tiles peeking out above it. They unfold when the page
 * is scrolled to the bottom, and for a few seconds when a folded tile is
 * tapped — the second tap then opens it. A keyboard focus unfolds them at
 * once, so the first Enter already opens the sheet.
 *
 * The dock sits below the YouTube player's layer (z 30 < 35): it must never
 * paint over the video.
 */
export function MobileDock({ withTiles }: { withTiles: boolean }) {
  const [expanded, setExpanded] = useState(false);
  const expandedRef = useRef(false);
  const auto = useRef(false);
  const wasAtBottom = useRef(false);
  const timer = useRef<number | undefined>(undefined);
  const dock = useRef<HTMLDivElement>(null);

  const setTray = useCallback((open: boolean) => {
    if (!open) window.clearTimeout(timer.current);
    expandedRef.current = open;
    setExpanded(open);
  }, []);

  useEffect(() => {
    if (!withTiles) return;
    let frame = 0;
    const check = () => {
      frame = 0;
      const root = document.documentElement;
      const atBottom = isPhone() && window.scrollY > 0 && root.scrollHeight - window.scrollY - window.innerHeight <= 6;
      if (atBottom && !wasAtBottom.current && !expandedRef.current) {
        auto.current = true;
        setTray(true);
      } else if (!atBottom && wasAtBottom.current && auto.current) {
        auto.current = false;
        setTray(false);
      }
      wasAtBottom.current = atBottom;
    };
    const onScroll = () => {
      if (!frame) frame = requestAnimationFrame(check);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      window.removeEventListener('scroll', onScroll);
      cancelAnimationFrame(frame);
      window.clearTimeout(timer.current);
    };
  }, [withTiles, setTray]);

  // The page leaves room for the dock (styles/shell.css): the menu's height,
  // measured, because it grows with the text size and the safe area.
  useEffect(() => {
    const nav = dock.current?.querySelector('.navigation');
    if (!nav) return;
    const measure = () => {
      const space = Math.max(0, Math.round(window.innerHeight - nav.getBoundingClientRect().top));
      if (space > 0) document.documentElement.style.setProperty('--dock-nav', `${space}px`);
    };
    const observer = new ResizeObserver(measure);
    observer.observe(nav);
    window.addEventListener('resize', measure);
    measure();
    return () => {
      observer.disconnect();
      window.removeEventListener('resize', measure);
    };
  }, []);

  const beforeOpen = () => {
    if (!isPhone()) return false;
    const wasOpen = expandedRef.current;
    auto.current = false;
    setTray(true);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setTray(false), TRAY_OPEN_MS);
    return !wasOpen;
  };

  return (
    <div ref={dock} className="mobile-dock" data-expanded={withTiles && expanded}>
      <div className="lower-content">
        {withTiles && (
          <div
            className="action-tray"
            onFocus={(e) => {
              if (!(e.target instanceof HTMLElement) || !e.target.matches(':focus-visible')) return;
              auto.current = false;
              window.clearTimeout(timer.current);
              setTray(true);
            }}
            onBlur={(e) => {
              if (e.currentTarget.contains(e.relatedTarget as Node | null)) return;
              window.clearTimeout(timer.current);
              timer.current = window.setTimeout(() => setTray(false), TRAY_OPEN_MS);
            }}
          >
            <div className="actions-window">
              <SubmitTiles beforeOpen={beforeOpen} />
            </div>
          </div>
        )}
        <MainNav className="navigation" />
      </div>
    </div>
  );
}
