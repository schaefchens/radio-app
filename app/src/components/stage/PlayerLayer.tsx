import { useEffect, useRef } from 'react';
import { useRadio } from '@/store/radio';
import { useSettings } from '@/store/settings';
import { stageAvailable, useStage } from '@/store/stage';
import { mountPlayer } from '@/lib/radio';

/**
 * The one YouTube player, in a fixed layer that follows the current stage
 * slot. Mounted once in the app shell and never moved in the DOM — an iframe
 * that is re-parented reloads, which would stop the song on every page change.
 *
 * While a song plays and the slot is available the layer sits exactly on the
 * slot, above everything else on the page; otherwise it is parked off screen
 * (and the engine has paused the video, see lib/radio.ts).
 */
export function PlayerLayer() {
  const host = useRef<HTMLDivElement>(null);
  const layer = useRef<HTMLDivElement>(null);
  const joined = useRadio((s) => s.engine.joined);
  const playerVisible = useRadio((s) => s.engine.playerVisible);
  const consent = useSettings((s) => s.consent);
  const slot = useStage((s) => s.slot);
  const available = useStage(stageAvailable);
  const show = playerVisible && available && slot !== null;

  useEffect(() => {
    if (consent && joined && host.current) void mountPlayer(host.current);
  }, [consent, joined]);

  useEffect(() => {
    const box = layer.current;
    if (!box) return;
    if (!show || !slot) {
      box.style.transform = 'translate(-10000px, 0)';
      return;
    }
    let raf = 0;
    const follow = (): void => {
      const r = slot.getBoundingClientRect();
      box.style.transform = `translate(${Math.round(r.left)}px, ${Math.round(r.top)}px)`;
      box.style.width = `${Math.round(r.width)}px`;
      box.style.height = `${Math.round(r.height)}px`;
      box.style.borderRadius = getComputedStyle(slot).borderRadius;
      raf = requestAnimationFrame(follow);
    };
    follow();
    return () => cancelAnimationFrame(raf);
  }, [show, slot]);

  return (
    <div
      ref={layer}
      aria-hidden={!show}
      className="pointer-events-auto fixed left-0 top-0 z-[35] overflow-hidden"
      style={{ width: 480, height: 270, transform: 'translate(-10000px, 0)' }}
    >
      <div ref={host} className="h-full w-full" />
    </div>
  );
}
