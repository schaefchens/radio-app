import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { ChevronIcon, CloseIcon } from '@/components/common/icons';
import { useStage } from '@/store/stage';

/** Drag far enough and let go, and the sheet closes rather than snapping back. */
const DISMISS_PX = 96;
/** …or flick it: a fast downward release dismisses from anywhere. px/ms. */
const DISMISS_VELOCITY = 0.5;

/**
 * Body-scroll lock, refcounted because more than one sheet can be mounted at
 * once (the quick bar mounts two) and the last one to close must not unlock
 * while another is still open.
 */
let scrollLocks = 0;
let restoreOverflow = '';
function lockScroll() {
  if (scrollLocks++ === 0) {
    restoreOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
}
function unlockScroll() {
  if (scrollLocks > 0 && --scrollLocks === 0) {
    document.body.style.overflow = restoreOverflow;
  }
}

const FOCUSABLE =
  'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),' +
  'textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

type Props = {
  open: boolean;
  onClose: () => void;
  /** Header text, and the sheet's accessible name. */
  title: string;
  /** When given, the header title becomes a back button instead of a heading —
   * for sheets that navigate between sub-views. */
  onBack?: () => void;
  children: React.ReactNode;
};

/**
 * Adapted from bible-assistant's BottomSheet. The app's slide-up sheet: scrim, rounded panel, draggable grab handle, title
 * row, and whatever body the caller supplies. Always mounted and translated
 * off-screen when closed, so the open/close transition has something to animate.
 *
 * **Rendered through a portal, deliberately.** Several of the bars that open a
 * sheet set `backdrop-blur`, and a `backdrop-filter` makes an element a
 * containing block for `position: fixed` descendants — a sheet rendered inside
 * one anchors to that bar instead of the viewport, and sits in the middle of the
 * page while "closed". Portalling here means no caller has to know that.
 *
 * Children are placed directly in the panel's flex column rather than in a
 * fixed body wrapper, because the three sheets genuinely differ: one wants
 * padded scrolling (use `BottomSheetBody`), one hands its whole body to a child
 * that scrolls itself, one switches between several body views.
 */
export function BottomSheet({ open, onClose, title, onBack, children }: Props) {
  const { t } = useTranslation();
  const panelRef = useRef<HTMLDivElement>(null);
  const [host] = useState(() =>
    typeof document === 'undefined' ? null : document.createElement('div'),
  );
  // null = not dragging. A number is the live downward offset in px.
  const [dragY, setDragY] = useState<number | null>(null);
  const drag = useRef({ startY: 0, lastY: 0, lastT: 0 });

  useEffect(() => {
    if (!host) return;
    document.body.appendChild(host);
    return () => host.remove();
  }, [host]);

  // Escape closes, matching every other dismissible surface in the app.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  // Lock the page behind the scrim, and hide it from assistive tech. `inert`
  // covers what aria-modal alone doesn't: without it the page behind stays
  // reachable by screen reader and by Tab.
  // An open sheet covers the stage; the floating YouTube player must not play
  // under it (see store/stage.ts).
  useEffect(() => {
    if (!open) return;
    useStage.getState().pushOverlay();
    return () => useStage.getState().popOverlay();
  }, [open]);

  useEffect(() => {
    if (!open || !host) return;
    lockScroll();
    const backdropped = Array.from(document.body.children).filter(
      (el): el is HTMLElement => el !== host && el instanceof HTMLElement && !el.inert,
    );
    backdropped.forEach((el) => (el.inert = true));
    return () => {
      backdropped.forEach((el) => (el.inert = false));
      unlockScroll();
    };
  }, [open, host]);

  // A closed sheet is still in the DOM, just translated away — so its buttons
  // would otherwise stay tabbable from the page behind it.
  useEffect(() => {
    const el = panelRef.current;
    if (el) el.inert = !open;
  }, [open]);

  // Move focus in on open and hand it back on close, so keyboard and screen
  // reader users end up where they started.
  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    const panel = panelRef.current;
    const first = panel?.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? panel)?.focus({ preventScroll: true });
    return () => previous?.focus?.({ preventScroll: true });
  }, [open]);

  const onTrapKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key !== 'Tab') return;
    const panel = panelRef.current;
    if (!panel) return;
    const items = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
      (el) => el.offsetParent !== null || el === document.activeElement,
    );
    if (items.length === 0) return;
    const first = items[0]!;
    const last = items[items.length - 1]!;
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }, []);

  const onDragStart = (e: React.PointerEvent) => {
    if (!open) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    drag.current = { startY: e.clientY, lastY: e.clientY, lastT: e.timeStamp };
    setDragY(0);
  };

  const onDragMove = (e: React.PointerEvent) => {
    if (dragY === null) return;
    drag.current.lastY = e.clientY;
    drag.current.lastT = e.timeStamp;
    // Downward only — dragging up shouldn't lift the sheet off its anchor.
    setDragY(Math.max(0, e.clientY - drag.current.startY));
  };

  const onDragEnd = (e: React.PointerEvent) => {
    if (dragY === null) return;
    const dt = e.timeStamp - drag.current.lastT;
    const velocity = dt > 0 ? (e.clientY - drag.current.lastY) / dt : 0;
    const dismiss = dragY > DISMISS_PX || velocity > DISMISS_VELOCITY;
    // Drop the inline transform either way: the class-based translate takes
    // over and animates from wherever the finger left it — down and out when
    // dismissing, back up when snapping home.
    setDragY(null);
    if (dismiss) onClose();
  };

  if (!host) return null;

  return createPortal(
    <>
      <div
        aria-hidden={!open}
        onClick={onClose}
        className={clsx(
          'fixed inset-0 z-40 bg-black/50 transition-opacity duration-200',
          open ? 'opacity-100' : 'pointer-events-none opacity-0',
        )}
      />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        onKeyDown={onTrapKeyDown}
        className={clsx(
          'fixed left-0 right-0 bottom-0 z-50 outline-none',
          'rounded-t-3xl border-t border-night-line/40 bg-night-deep/95 shadow-2xl backdrop-blur-xl',
          'will-change-transform',
          // Suppress the transition only while a finger is on it, so the sheet
          // tracks the drag exactly instead of lagging 300ms behind.
          dragY === null && 'transition-transform duration-300 ease-out',
          open ? 'translate-y-0' : 'translate-y-full',
        )}
        style={{
          maxHeight: '85vh',
          ...(dragY === null ? null : { transform: `translateY(${dragY}px)` }),
        }}
      >
        <div className="flex flex-col" style={{ maxHeight: '85vh' }}>
          {/* Grab handle + title are one drag surface: the pill alone is a 12px
              target, and every native sheet lets you drag the header too. */}
          <div
            onPointerDown={onDragStart}
            onPointerMove={onDragMove}
            onPointerUp={onDragEnd}
            onPointerCancel={onDragEnd}
            className="shrink-0 cursor-grab active:cursor-grabbing"
            style={{ touchAction: 'none' }}
          >
            <div className="flex flex-col items-center pt-2 pb-1">
              <div className="h-1.5 w-12 rounded-full bg-ink/25" />
            </div>
            <div className="flex items-center justify-between px-5 pb-3 gap-2">
              {onBack ? (
                <button
                  type="button"
                  onPointerDown={(e) => e.stopPropagation()}
                  onClick={onBack}
                  aria-label={t('common.back') as string}
                  className="text-ink-muted hover:text-ink transition-colors -ml-1 px-1 flex items-center gap-1 min-w-0"
                >
                  <ChevronIcon dir="left" size={20} />
                  <span className="text-ink text-lg font-semibold truncate">{title}</span>
                </button>
              ) : (
                <h2 className="text-ink text-lg font-semibold truncate">{title}</h2>
              )}
              <button
                type="button"
                onPointerDown={(e) => e.stopPropagation()}
                onClick={onClose}
                aria-label={t('common.close') as string}
                className="text-ink-muted hover:text-ink transition-colors px-2 shrink-0"
              >
                <CloseIcon size={20} />
              </button>
            </div>
          </div>

          {children}
        </div>
      </div>
    </>,
    host,
  );
}

/** The usual padded, scrolling sheet body. Sheets whose child scrolls itself
 *  (or that switch between several body views) skip this and lay out their own. */
export function BottomSheetBody({ children }: { children: React.ReactNode }) {
  return <div className="flex-1 overflow-y-auto px-5 pb-8 pb-safe">{children}</div>;
}

